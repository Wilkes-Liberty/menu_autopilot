<?php

declare(strict_types=1);

namespace Drupal\menu_autopilot;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\menu_link_content\MenuLinkContentInterface;
use Drupal\node\NodeInterface;
use Drupal\path_alias\PathAliasInterface;

/**
 * Read-only report of dynamic parents and the children under them.
 *
 * A view over the same discovery and partition a sync uses, for status
 * pages and tools. It does not resolve the source, so it says what is
 * under each parent now, not what the next sync will do. It returns
 * titles, ids and counts only: never the label pattern, and no node field.
 *
 * The sync manager uses the same discovery when it writes, so a status
 * row and a rebuild agree on which parents and children exist.
 */
final class NavStatusReporter {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * Every dynamic parent in the managed menus, with counts of its children.
   *
   * - `owned`: children this module manages.
   * - `adoptable`: unmanaged children that point at a node, one per node. A
   *   sync adopts, leaves or deletes them, depending on the parent's policy
   *   and on whether the node is in the source.
   * - `extra`: every other child.
   * - `disabled`: owned children that are disabled.
   * - `disabled_by_save`: the disabled ones a sync save, not an editor,
   *   left disabled.
   * - `stale_translations`: owned-child translations whose language the
   *   node does not have. The next sync removes them. The link's own
   *   language is not counted: that translation cannot be removed.
   *
   * `translations` lists those titles (node id, language code, title, and
   * whether it is stale). It is capped separately from `disabled_children`.
   *
   * @param int $max_parents
   *   The most parents to describe. `parents_total` still counts them all.
   * @param int $max_listed
   *   The most disabled children to list under one parent, and separately
   *   the most translations.
   *
   * @return array{managed_menus: string[], parents_total: int, parents_truncated: bool, parents: list<array<string, mixed>>}
   *   The report.
   */
  public function parentStatus(int $max_parents = 50, int $max_listed = 25): array {
    $max_parents = max(1, $max_parents);
    $max_listed = max(0, $max_listed);
    $parents = $this->findDynamicParents();
    $rows = [];
    foreach (array_slice($parents, 0, $max_parents) as $parent) {
      $siblings = $this->loadChildren($parent);
      $partition = $this->partitionChildren($siblings);
      $source = $this->getSource($parent);
      $disabled = [];
      $disabled_by_save = 0;
      foreach ($partition['owned'] as $nid => $link) {
        if ($link->isEnabled()) {
          continue;
        }
        $by_save = !empty($this->getData($link)['disabled_by_save']);
        $disabled_by_save += (int) $by_save;
        $disabled[] = [
          'title' => (string) $link->getTitle(),
          'node' => (int) $nid,
          'disabled_by_save' => $by_save,
        ];
      }
      [$translations, $stale_translations] = $this->linkTranslationReport($partition['owned']);
      $rows[] = [
        'uuid' => (string) $parent->uuid(),
        'title' => (string) $parent->getTitle(),
        'menu' => (string) $parent->getMenuName(),
        'enabled' => $parent->isEnabled(),
        'source_type' => (string) ($source['type'] ?? 'none'),
        'existing_children' => $this->existingChildrenPolicy($source),
        'counts' => [
          'owned' => count($partition['owned']),
          'adoptable' => count($partition['adoptable']),
          'extra' => count($siblings) - count($partition['owned']) - count($partition['adoptable']),
          'disabled' => count($disabled),
          'disabled_by_save' => $disabled_by_save,
          'stale_translations' => $stale_translations,
        ],
        'disabled_children' => array_slice($disabled, 0, $max_listed),
        'disabled_children_truncated' => count($disabled) > $max_listed,
        'translations' => array_slice($translations, 0, $max_listed),
        'translations_truncated' => count($translations) > $max_listed,
      ];
    }
    return [
      'managed_menus' => array_values($this->managedMenus()),
      'parents_total' => count($parents),
      'parents_truncated' => count($parents) > $max_parents,
      'parents' => $rows,
    ];
  }

  /**
   * All dynamic parent links across the managed menus.
   *
   * Uses the queryable `menu_autopilot_dynamic` marker so this does not
   * hydrate every menu_link_content in a managed menu. The map field remains
   * the descriptor; the boolean is only an index. A cheap PHP check still
   * drops a stale marker.
   *
   * @return \Drupal\menu_link_content\MenuLinkContentInterface[]
   *   The parent links that have a source descriptor.
   */
  public function findDynamicParents(): array {
    $menus = $this->managedMenus();
    if ($menus === []) {
      return [];
    }
    $ids = $this->menuLinkStorage()->getQuery()
      ->condition('menu_name', $menus, 'IN')
      ->condition('menu_autopilot_dynamic', TRUE)
      ->accessCheck(FALSE)
      ->execute();
    $parents = [];
    foreach ($this->menuLinkStorage()->loadMultiple($ids) as $link) {
      if (!$link instanceof MenuLinkContentInterface) {
        continue;
      }
      $source = $this->getSource($link);
      if ($this->isUnavailableTermSource($source)) {
        continue;
      }
      if (!$this->isManaged($link) && ($source['type'] ?? 'none') !== 'none') {
        $parents[] = $link;
      }
    }
    return $parents;
  }

  /**
   * Direct children of a parent link in the same menu.
   *
   * @return \Drupal\menu_link_content\MenuLinkContentInterface[]
   *   Child links, keyed by entity id.
   */
  public function loadChildren(MenuLinkContentInterface $parent): array {
    $ids = $this->menuLinkStorage()->getQuery()
      ->condition('menu_name', $parent->getMenuName())
      ->condition('parent', 'menu_link_content:' . $parent->uuid())
      ->accessCheck(FALSE)
      ->execute();
    $children = [];
    foreach ($this->menuLinkStorage()->loadMultiple($ids) as $link) {
      if ($link instanceof MenuLinkContentInterface) {
        $children[(int) $link->id()] = $link;
      }
    }
    return $children;
  }

  /**
   * Split already-loaded siblings into owned, adoptable, and extras.
   *
   * @param \Drupal\menu_link_content\MenuLinkContentInterface[] $siblings
   *   Direct children keyed by entity id.
   *
   * @return array
   *   Keys owned and adoptable (node id => link) plus append_weight.
   */
  public function partitionChildren(array $siblings): array {
    $owned = [];
    $adoptable = [];
    $max_weight = -1;
    foreach ($siblings as $link) {
      $max_weight = max($max_weight, (int) $link->getWeight());
      $data = $this->getData($link);
      if (!empty($data['managed']) && !empty($data['node'])) {
        $owned[(int) $data['node']] = $link;
      }
      elseif (($nid = $this->nodeIdFromLink($link)) !== NULL && !isset($adoptable[$nid])) {
        $adoptable[$nid] = $link;
      }
    }
    return [
      'owned' => $owned,
      'adoptable' => $adoptable,
      'append_weight' => $max_weight + 1,
    ];
  }

  /**
   * The node id a link already points at, if it is a node link.
   *
   * Matches the same URI forms as normalizeNodeUris() (`entity:node/12`,
   * `internal:/node/12`, editorial `/node/12/latest`) and, when path_alias
   * is available, an alias that resolves to a node path.
   */
  public function nodeIdFromLink(MenuLinkContentInterface $link): ?int {
    $item = $link->get('link')->first();
    if ($item === NULL) {
      return NULL;
    }
    $uri = (string) ($item->getValue()['uri'] ?? '');
    if ($nid = $this->nodeIdFromUri($uri)) {
      return $nid;
    }
    $alias = $this->internalPathFromUri($uri);
    if ($alias === NULL) {
      return NULL;
    }
    $system = $this->resolveAliasToSystemPath($alias);
    return $system === NULL ? NULL : $this->nodeIdFromUri('internal:' . $system);
  }

  /**
   * The canonical `entity:node/<nid>` form of a node link URI, if it is one.
   *
   * @param string $uri
   *   The stored link URI.
   *
   * @return string|null
   *   The canonical URI, or NULL when the URI does not target a node.
   */
  public function canonicalNodeUri(string $uri): ?string {
    if (preg_match('#^(?:entity:|internal:|base:)?/?node/(\d+)(?![0-9])#', $uri, $matches)) {
      return 'entity:node/' . $matches[1];
    }
    return NULL;
  }

  /**
   * TRUE when a leftover term source cannot run because Taxonomy is gone.
   *
   * Empty resolve() is the same signal as an empty published set, so these
   * parents must not enter the reconcile delete path. The stored descriptor
   * is left unchanged; the parent form converts leftover→none on save.
   */
  public function isUnavailableTermSource(array $source): bool {
    return ($source['type'] ?? '') === 'term'
      && !$this->moduleHandler->moduleExists('taxonomy');
  }

  /**
   * Translated titles on owned children, for the status report.
   *
   * The link's own language is left out. A row is stale when the node has
   * no translation in that language, including when the node is gone.
   *
   * @param \Drupal\menu_link_content\MenuLinkContentInterface[] $owned
   *   Owned children keyed by node id.
   *
   * @return array
   *   A list of two elements: the rows (node, langcode, title, stale),
   *   sorted by node id then language code, and the stale count.
   */
  private function linkTranslationReport(array $owned): array {
    // The stale count is not capped, and the listed rows are sorted before
    // the cap is applied, so every extra translation is visited. Node
    // entities are the heavy part: skip links that have none, and drop each
    // batch before loading the next.
    $pending = [];
    foreach ($owned as $nid => $link) {
      $link = $link->getUntranslated();
      $default_lang = $link->language()->getId();
      $extras = [];
      foreach (array_keys($link->getTranslationLanguages()) as $langcode) {
        if ($langcode !== $default_lang) {
          $extras[] = $langcode;
        }
      }
      if ($extras !== []) {
        $pending[(int) $nid] = [$link, $extras];
      }
    }

    $rows = [];
    $stale = 0;
    $storage = $this->entityTypeManager->getStorage('node');
    foreach (array_chunk(array_keys($pending), 50) as $chunk) {
      $nodes = $storage->loadMultiple($chunk);
      foreach ($chunk as $nid) {
        [$link, $extras] = $pending[$nid];
        $node = $nodes[$nid] ?? NULL;
        $node_languages = $node instanceof NodeInterface
          ? $node->getUntranslated()->getTranslationLanguages()
          : [];
        foreach ($extras as $langcode) {
          $is_stale = !isset($node_languages[$langcode]);
          $stale += (int) $is_stale;
          $rows[] = [
            'node' => $nid,
            'langcode' => $langcode,
            'title' => (string) $link->getTranslation($langcode)->getTitle(),
            'stale' => $is_stale,
          ];
        }
      }
      $storage->resetCache($chunk);
    }
    usort($rows, static function (array $a, array $b): int {
      return [$a['node'], $a['langcode']] <=> [$b['node'], $b['langcode']];
    });
    return [$rows, $stale];
  }

  /**
   * The node id encoded in a stored link URI, if any.
   */
  private function nodeIdFromUri(string $uri): ?int {
    $canonical = $this->canonicalNodeUri($uri);
    if ($canonical === NULL) {
      return NULL;
    }
    $nid = (int) substr($canonical, strlen('entity:node/'));
    return $nid > 0 ? $nid : NULL;
  }

  /**
   * The path portion of an internal/base URI, or NULL if it is not one.
   */
  private function internalPathFromUri(string $uri): ?string {
    if (str_starts_with($uri, 'internal:')) {
      $path = substr($uri, strlen('internal:'));
    }
    elseif (str_starts_with($uri, 'base:')) {
      $path = '/' . ltrim(substr($uri, strlen('base:')), '/');
    }
    elseif (str_starts_with($uri, '/')) {
      $path = $uri;
    }
    else {
      return NULL;
    }
    $path = parse_url($path, PHP_URL_PATH);
    return is_string($path) && $path !== '' ? $path : NULL;
  }

  /**
   * Resolve a path alias to its system path, if path_alias is installed.
   */
  private function resolveAliasToSystemPath(string $alias): ?string {
    if (!$this->entityTypeManager->hasDefinition('path_alias')) {
      return NULL;
    }
    $alias = '/' . ltrim($alias, '/');
    $ids = $this->entityTypeManager->getStorage('path_alias')->getQuery()
      ->condition('alias', $alias)
      ->accessCheck(FALSE)
      ->range(0, 1)
      ->execute();
    if ($ids === []) {
      return NULL;
    }
    $entity = $this->entityTypeManager->getStorage('path_alias')->load(reset($ids));
    if (!$entity instanceof PathAliasInterface) {
      return NULL;
    }
    $path = $entity->getPath();
    return $path !== '' ? $path : NULL;
  }

  /**
   * The menus whose links may drive automatic children.
   *
   * An explicit empty list means no menus. Only a missing value uses the
   * install default (`main`).
   *
   * @return string[]
   *   The configured menu machine names.
   */
  public function managedMenus(): array {
    return $this->configFactory->get('menu_autopilot.settings')->get('managed_menus') ?? ['main'];
  }

  /**
   * How unmanaged children under a parent are treated during sync.
   *
   * @param array $source
   *   The parent source descriptor.
   *
   * @return string
   *   One of adopt, adopt_prune, add, or replace. Unknown values become adopt.
   */
  public function existingChildrenPolicy(array $source): string {
    $policy = $source['existing_children'] ?? 'adopt';
    return _menu_autopilot_existing_children_policy($policy);
  }

  /**
   * The parent link's source descriptor (empty when it is not dynamic).
   *
   * @param \Drupal\menu_link_content\MenuLinkContentInterface $link
   *   The menu link.
   *
   * @return array
   *   The stored source map, or an empty array.
   */
  public function getSource(MenuLinkContentInterface $link): array {
    $source = $this->getData($link)['source'] ?? NULL;
    return is_array($source) ? $source : [];
  }

  /**
   * Whether a link is a managed (auto-generated) child.
   *
   * @param \Drupal\menu_link_content\MenuLinkContentInterface $link
   *   The menu link.
   *
   * @return bool
   *   TRUE when the link carries the managed flag.
   */
  public function isManaged(MenuLinkContentInterface $link): bool {
    return !empty($this->getData($link)['managed']);
  }

  /**
   * Read the `menu_autopilot` map field off a link.
   *
   * @param \Drupal\menu_link_content\MenuLinkContentInterface $link
   *   The menu link.
   *
   * @return array
   *   The stored map, or an empty array.
   */
  public function getData(MenuLinkContentInterface $link): array {
    return _menu_autopilot_link_data($link);
  }

  /**
   * The menu link content storage handler.
   *
   * @return \Drupal\Core\Entity\EntityStorageInterface
   *   The storage handler.
   */
  public function menuLinkStorage(): EntityStorageInterface {
    return $this->entityTypeManager->getStorage('menu_link_content');
  }

}
