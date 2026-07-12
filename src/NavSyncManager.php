<?php

declare(strict_types=1);

namespace Drupal\menu_autopilot;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Utility\Token;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\menu_link_content\MenuLinkContentInterface;
use Drupal\node\NodeInterface;

/**
 * Keeps auto-generated child menu links in sync with their source content.
 *
 * Managed child links are ordinary menu_link_content entities carrying a
 * `menu_autopilot` map field of `['managed' => TRUE, 'node' => <nid>]`, so any
 * menu consumer (GraphQL, JSON:API, a theme) sees them with no extra work.
 * Every managed link uses a canonical `entity:node/<nid>` URI so it resolves to
 * the node's real path alias — never a raw or editorial path.
 */
final class NavSyncManager {

  /**
   * Re-entrancy guard: TRUE while this manager is saving its own child links.
   */
  private bool $syncing = FALSE;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly NavSourceResolver $resolver,
    private readonly Token $token,
  ) {}

  /**
   * TRUE while the manager is writing its own links (hooks should stand down).
   */
  public function isSyncing(): bool {
    return $this->syncing;
  }

  /**
   * React to a node change: reconcile every dynamic parent it can affect.
   */
  public function syncNode(NodeInterface $node): void {
    foreach ($this->findDynamicParents() as $parent) {
      if ($this->parentAffectedByNode($parent, $node)) {
        $this->syncParent($parent);
      }
    }
  }

  /**
   * If an editor-saved link is a dynamic parent, reconcile its children.
   *
   * Ignores links the manager owns, so it never reacts to its own writes.
   */
  public function syncParentIfDynamic(MenuLinkContentInterface $link): void {
    if (!$this->isManaged($link) && ($this->getSource($link)['type'] ?? 'none') !== 'none') {
      $this->syncParent($link);
    }
  }

  /**
   * Reconcile the managed children under a single dynamic parent (idempotent).
   */
  public function syncParent(MenuLinkContentInterface $parent): void {
    if ($this->syncing) {
      return;
    }
    $source = $this->getSource($parent);
    $desired = $this->resolver->resolve($source);
    $existing = $this->ownedChildren($parent);
    $nodes = $desired ? $this->entityTypeManager->getStorage('node')->loadMultiple($desired) : [];

    $this->syncing = TRUE;
    try {
      $weight = 0;
      $seen = [];
      foreach ($desired as $nid) {
        $node = $nodes[$nid] ?? NULL;
        if (!$node instanceof NodeInterface) {
          continue;
        }
        if ($link = $existing[$nid] ?? NULL) {
          $this->updateChild($link, $node, $source, $weight);
        }
        else {
          $this->createChild($parent, $node, $source, $weight);
        }
        $seen[$nid] = TRUE;
        $weight++;
      }
      // Remove owned children that are no longer wanted.
      foreach ($existing as $nid => $link) {
        if (empty($seen[$nid])) {
          $link->delete();
        }
      }
    }
    finally {
      $this->syncing = FALSE;
    }
  }

  /**
   * Reconcile every dynamic parent in the managed menus.
   */
  public function reconcile(): void {
    foreach ($this->findDynamicParents() as $parent) {
      $this->syncParent($parent);
    }
  }

  /**
   * Rewrite editorial node link URIs to canonical entity references.
   *
   * A menu link stored as an editorial or internal node route — for example
   * `/node/12/latest`, `internal:/node/12`, or `entity:node/12/latest` — has no
   * path alias, so a decoupled front end resolves it verbatim and 404s. This
   * rewrites every such link (across the given menus, or the managed menus by
   * default) to `entity:node/<nid>`, which resolves to the node's real alias.
   * It touches any matching link, not just the ones this module manages, so it
   * doubles as a one-time cleanup when adopting the module. Idempotent: links
   * already canonical are left untouched.
   *
   * @param string[]|null $menu_names
   *   The menus to scan, or NULL for the managed menus.
   *
   * @return array<int,string>
   *   Changed links keyed by id, valued "old-uri → new-uri".
   */
  public function normalizeNodeUris(?array $menu_names = NULL): array {
    $ids = $this->menuLinkStorage()->getQuery()
      ->condition('menu_name', $menu_names ?: $this->managedMenus(), 'IN')
      ->accessCheck(FALSE)
      ->execute();

    $changed = [];
    $was_syncing = $this->syncing;
    $this->syncing = TRUE;
    try {
      foreach ($this->menuLinkStorage()->loadMultiple($ids) as $link) {
        if ($link->get('link')->isEmpty()) {
          continue;
        }
        $item = $link->get('link')->first();
        $uri = (string) $item->uri;
        $canonical = $this->canonicalNodeUri($uri);
        if ($canonical !== NULL && $canonical !== $uri) {
          $value = $item->getValue();
          $value['uri'] = $canonical;
          $link->set('link', $value);
          $link->save();
          $changed[(int) $link->id()] = $uri . ' → ' . $canonical;
        }
      }
    }
    finally {
      $this->syncing = $was_syncing;
    }
    return $changed;
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
  private function canonicalNodeUri(string $uri): ?string {
    if (preg_match('#^(?:entity:|internal:|base:)?/?node/(\d+)(?![0-9])#', $uri, $matches)) {
      return 'entity:node/' . $matches[1];
    }
    return NULL;
  }

  /**
   * The menus whose links may drive automatic children.
   *
   * @return string[]
   *   The configured menu machine names.
   */
  public function managedMenus(): array {
    return $this->configFactory->get('menu_autopilot.settings')->get('managed_menus') ?: ['main'];
  }

  /**
   * All dynamic parent links across the managed menus.
   *
   * @return \Drupal\menu_link_content\MenuLinkContentInterface[]
   *   The parent links that have a source descriptor.
   */
  private function findDynamicParents(): array {
    $ids = $this->menuLinkStorage()->getQuery()
      ->condition('menu_name', $this->managedMenus(), 'IN')
      ->accessCheck(FALSE)
      ->execute();
    $parents = [];
    foreach ($this->menuLinkStorage()->loadMultiple($ids) as $link) {
      if (!$this->isManaged($link) && ($this->getSource($link)['type'] ?? 'none') !== 'none') {
        $parents[] = $link;
      }
    }
    return $parents;
  }

  /**
   * Managed child links under a parent, keyed by their source node id.
   *
   * @return \Drupal\menu_link_content\MenuLinkContentInterface[]
   *   The managed child links, keyed by source node id.
   */
  private function ownedChildren(MenuLinkContentInterface $parent): array {
    $ids = $this->menuLinkStorage()->getQuery()
      ->condition('menu_name', $parent->getMenuName())
      ->condition('parent', 'menu_link_content:' . $parent->uuid())
      ->accessCheck(FALSE)
      ->execute();
    $children = [];
    foreach ($this->menuLinkStorage()->loadMultiple($ids) as $link) {
      $data = $this->getData($link);
      if (!empty($data['managed']) && !empty($data['node'])) {
        $children[(int) $data['node']] = $link;
      }
    }
    return $children;
  }

  /**
   * Whether a node change could add, remove, or update a link under a parent.
   */
  private function parentAffectedByNode(MenuLinkContentInterface $parent, NodeInterface $node): bool {
    // The parent already has an owned link (it may need updating or removing).
    if (isset($this->ownedChildren($parent)[(int) $node->id()])) {
      return TRUE;
    }
    // Otherwise, the node may newly match this parent's source.
    return $this->nodeMatchesSource($node, $this->getSource($parent));
  }

  /**
   * Whether a node belongs to a source descriptor's result set.
   */
  private function nodeMatchesSource(NodeInterface $node, array $source): bool {
    return match ($source['type'] ?? 'none') {
      'manual' => in_array((int) $node->id(), array_map('intval', $source['nodes'] ?? []), TRUE),
      'bundle' => $node->bundle() === ($source['bundle'] ?? NULL),
      'term' => $this->nodeReferencesTerm($node, (string) ($source['reference_field'] ?? ''), (int) ($source['term'] ?? 0))
        && (empty($source['bundle']) || $node->bundle() === $source['bundle']),
      default => FALSE,
    };
  }

  /**
   * Whether a node's reference field points at a given term.
   */
  private function nodeReferencesTerm(NodeInterface $node, string $field, int $term): bool {
    if ($field === '' || $term === 0 || !$node->hasField($field)) {
      return FALSE;
    }
    foreach ($node->get($field) as $item) {
      if ((int) ($item->target_id ?? 0) === $term) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Create a managed child link for a node under a parent.
   */
  private function createChild(MenuLinkContentInterface $parent, NodeInterface $node, array $source, int $weight): void {
    MenuLinkContent::create([
      'menu_name' => $parent->getMenuName(),
      'parent' => 'menu_link_content:' . $parent->uuid(),
      'title' => $this->linkTitle($node, $source),
      'link' => ['uri' => 'entity:node/' . $node->id()],
      'weight' => $weight,
      'enabled' => TRUE,
      'menu_autopilot' => ['managed' => TRUE, 'node' => (int) $node->id()],
    ])->save();
  }

  /**
   * Update a managed child link to mirror its node (title, weight, URI).
   */
  private function updateChild(MenuLinkContentInterface $link, NodeInterface $node, array $source, int $weight): void {
    $changed = FALSE;
    $title = $this->linkTitle($node, $source);
    if ($link->getTitle() !== $title) {
      $link->set('title', $title);
      $changed = TRUE;
    }
    if ((int) $link->getWeight() !== $weight) {
      $link->set('weight', $weight);
      $changed = TRUE;
    }
    $uri = 'entity:node/' . $node->id();
    $current = $link->get('link')->isEmpty() ? NULL : $link->get('link')->first()->uri;
    if ($current !== $uri) {
      $link->set('link', ['uri' => $uri]);
      $changed = TRUE;
    }
    if ($changed) {
      $link->save();
    }
  }

  /**
   * The title for a managed child link.
   *
   * When the source defines a `title_pattern`, it is run through the token
   * service (e.g. `[node:title]`, `[node:field_nav_title]`) so editors can give
   * nav a shorter or decorated label than the page title; unreplaced tokens are
   * cleared. Falls back to the node label when no pattern is set or the pattern
   * resolves to an empty string. URIs are never tokenized — a managed link
   * always points at `entity:node/<nid>`.
   */
  private function linkTitle(NodeInterface $node, array $source): string {
    $pattern = trim((string) ($source['title_pattern'] ?? ''));
    if ($pattern === '') {
      return (string) $node->label();
    }
    $title = trim($this->token->replace(
      $pattern,
      ['node' => $node],
      ['clear' => TRUE, 'langcode' => $node->language()->getId()],
    ));
    return $title !== '' ? $title : (string) $node->label();
  }

  /**
   * The parent link's source descriptor (empty when it is not dynamic).
   */
  private function getSource(MenuLinkContentInterface $link): array {
    $source = $this->getData($link)['source'] ?? NULL;
    return is_array($source) ? $source : [];
  }

  /**
   * Whether a link is a managed (auto-generated) child.
   */
  private function isManaged(MenuLinkContentInterface $link): bool {
    return !empty($this->getData($link)['managed']);
  }

  /**
   * Read the `menu_autopilot` map field off a link.
   */
  private function getData(MenuLinkContentInterface $link): array {
    if (!$link->hasField('menu_autopilot') || $link->get('menu_autopilot')->isEmpty()) {
      return [];
    }
    $value = $link->get('menu_autopilot')->first()->getValue();
    return is_array($value) ? $value : [];
  }

  /**
   * The menu link content storage handler.
   *
   * @return \Drupal\Core\Entity\EntityStorageInterface
   *   The storage handler.
   */
  private function menuLinkStorage(): EntityStorageInterface {
    return $this->entityTypeManager->getStorage('menu_link_content');
  }

}
