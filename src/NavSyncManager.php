<?php

declare(strict_types=1);

namespace Drupal\menu_autopilot;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DestructableInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Utility\Token;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\menu_link_content\MenuLinkContentInterface;
use Drupal\node\NodeInterface;
use Drupal\path_alias\PathAliasInterface;

/**
 * Keeps auto-generated child menu links in sync with their source content.
 *
 * Managed child links are ordinary menu_link_content entities carrying a
 * `menu_autopilot` map field of `['managed' => TRUE, 'node' => <nid>]`, so any
 * menu consumer (GraphQL, JSON:API, a theme) sees them with no extra work.
 * Every managed link uses a canonical `entity:node/<nid>` URI so it resolves to
 * the node's real path alias — never a raw or editorial path.
 */
final class NavSyncManager implements DestructableInterface {

  /**
   * Re-entrancy guard: TRUE while this manager is saving its own child links.
   */
  private bool $syncing = FALSE;

  /**
   * Node ids whose reconcile is waiting until after node-form submit handlers.
   *
   * @var array<int, true>
   */
  private array $queuedNodeIds = [];

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
   * {@inheritdoc}
   */
  public function destruct(): void {
    $this->flushQueued();
  }

  /**
   * Queue a node to reconcile after menu_ui has finished writing its link.
   *
   * Node form submit saves the node (this module's entity hooks run) and only
   * then runs menu_ui's submit handler. Deleting a managed child in the hook
   * makes getActive() return NULL and menuUiNodeSave() fatal on
   * isTranslatable().
   */
  public function queueNode(NodeInterface $node): void {
    $id = (int) $node->id();
    if ($id > 0) {
      $this->queuedNodeIds[$id] = TRUE;
    }
  }

  /**
   * Reconcile every node queued by queueNode().
   */
  public function flushQueued(): void {
    if ($this->queuedNodeIds === []) {
      return;
    }
    $ids = array_keys($this->queuedNodeIds);
    $this->queuedNodeIds = [];
    $storage = $this->entityTypeManager->getStorage('node');
    $storage->resetCache($ids);
    foreach ($storage->loadMultiple($ids) as $node) {
      if ($node instanceof NodeInterface) {
        $this->syncNode($node);
      }
    }
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
      $policy = $this->existingChildrenPolicy($source);
      if (!empty($source['reparent_matches'])) {
        $this->reparentMatchingLinks($parent, $desired);
      }
      if ($policy === 'replace') {
        foreach ($this->loadChildren($parent) as $link) {
          if (!$this->isManaged($link)) {
            $link->delete();
          }
        }
      }

      $weight = 0;
      $seen = [];
      $preserve = $this->preservesEditorOrder($source);
      $append_weight = $preserve ? $this->nextAppendWeight($parent) : 0;
      $adoptable = $policy === 'replace' ? [] : $this->adoptableChildren($parent);
      foreach ($desired as $nid) {
        $node = $nodes[$nid] ?? NULL;
        if (!$node instanceof NodeInterface) {
          continue;
        }
        if ($link = $existing[$nid] ?? NULL) {
          $this->updateChild($link, $node, $source, $preserve ? (int) $link->getWeight() : $weight);
        }
        elseif ($link = $adoptable[$nid] ?? NULL) {
          if ($policy === 'add') {
            // Leave the hand-created link as-is; do not add a second copy.
            $seen[$nid] = TRUE;
            if (!$preserve) {
              $weight++;
            }
            continue;
          }
          $this->adoptChild($link, $node, $source, $preserve ? (int) $link->getWeight() : $weight);
        }
        else {
          $this->createChild($parent, $node, $source, $preserve ? $append_weight++ : $weight);
        }
        $seen[$nid] = TRUE;
        if (!$preserve) {
          $weight++;
        }
      }
      // Remove owned children that are no longer wanted.
      foreach ($existing as $nid => $link) {
        if (empty($seen[$nid])) {
          $link->delete();
        }
      }
      // Drop unmanaged twins of a node we already manage. Hand-created
      // extras (and add-only matches we left unmanaged) are not in
      // ownedChildren(), so they stay.
      $owned = $this->ownedChildren($parent);
      foreach ($this->loadChildren($parent) as $link) {
        if ($this->isManaged($link)) {
          continue;
        }
        $nid = $this->nodeIdFromLink($link);
        if ($nid !== NULL && isset($owned[$nid])) {
          $link->delete();
        }
      }
      if ($policy === 'adopt_prune') {
        foreach ($this->loadChildren($parent) as $link) {
          if (!$this->isManaged($link)) {
            $link->delete();
          }
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
        if (!$link instanceof MenuLinkContentInterface) {
          continue;
        }
        $item = $link->get('link')->first();
        if ($item === NULL) {
          continue;
        }
        $value = $item->getValue();
        $uri = (string) ($value['uri'] ?? '');
        $canonical = $this->canonicalNodeUri($uri);
        if ($canonical !== NULL && $canonical !== $uri) {
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
      if (!$link instanceof MenuLinkContentInterface) {
        continue;
      }
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
    $children = [];
    foreach ($this->loadChildren($parent) as $link) {
      $data = $this->getData($link);
      if (!empty($data['managed']) && !empty($data['node'])) {
        $children[(int) $data['node']] = $link;
      }
    }
    return $children;
  }

  /**
   * Unmanaged children under a parent that already point at a node.
   *
   * Used when a parent is first marked dynamic: existing hand-created links
   * to source nodes are adopted instead of duplicated. Children that do not
   * resolve to a node (or that are already managed) are ignored so curated
   * extras stay put. Keyed by node id; the first match wins.
   *
   * @return \Drupal\menu_link_content\MenuLinkContentInterface[]
   *   Unmanaged child links keyed by the node they already target.
   */
  private function adoptableChildren(MenuLinkContentInterface $parent): array {
    $children = [];
    foreach ($this->loadChildren($parent) as $link) {
      if ($this->isManaged($link)) {
        continue;
      }
      $nid = $this->nodeIdFromLink($link);
      if ($nid !== NULL && !isset($children[$nid])) {
        $children[$nid] = $link;
      }
    }
    return $children;
  }

  /**
   * Direct children of a parent link in the same menu.
   *
   * @return \Drupal\menu_link_content\MenuLinkContentInterface[]
   *   Child links, keyed by entity id.
   */
  private function loadChildren(MenuLinkContentInterface $parent): array {
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
   * The node id a link already points at, if it is a node link.
   *
   * Matches the same URI forms as normalizeNodeUris() (`entity:node/12`,
   * `internal:/node/12`, editorial `/node/12/latest`) and, when path_alias
   * is available, an alias that resolves to a node path.
   */
  private function nodeIdFromLink(MenuLinkContentInterface $link): ?int {
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
   * Move unmanaged matches from elsewhere in this menu under the parent.
   *
   * Skips links this module already manages and children of another
   * dynamic parent, so two automatic parents cannot steal from each other.
   *
   * @param \Drupal\menu_link_content\MenuLinkContentInterface $parent
   *   The dynamic parent that should receive the matches.
   * @param int[] $desired
   *   Source node ids this parent wants as children.
   */
  private function reparentMatchingLinks(MenuLinkContentInterface $parent, array $desired): void {
    if ($desired === []) {
      return;
    }
    $wanted = array_fill_keys($desired, TRUE);
    $protected = ['menu_link_content:' . $parent->uuid() => TRUE];
    foreach ($this->findDynamicParents() as $other) {
      $protected['menu_link_content:' . $other->uuid()] = TRUE;
    }
    $ids = $this->menuLinkStorage()->getQuery()
      ->condition('menu_name', $parent->getMenuName())
      ->accessCheck(FALSE)
      ->execute();
    foreach ($this->menuLinkStorage()->loadMultiple($ids) as $link) {
      if (!$link instanceof MenuLinkContentInterface || $this->isManaged($link)) {
        continue;
      }
      if ((int) $link->id() === (int) $parent->id()) {
        continue;
      }
      if (isset($protected[$link->getParentId()])) {
        continue;
      }
      $nid = $this->nodeIdFromLink($link);
      if ($nid === NULL || !isset($wanted[$nid])) {
        continue;
      }
      $link->set('parent', 'menu_link_content:' . $parent->uuid());
      $link->save();
    }
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
   * Take over an existing unmanaged child so it is not duplicated.
   *
   * Marks the link as managed and reconciles title, weight, and URI. The
   * entity id is preserved so existing references (and a second reconcile)
   * keep the same link.
   */
  private function adoptChild(MenuLinkContentInterface $link, NodeInterface $node, array $source, int $weight): void {
    $link->set('menu_autopilot', ['managed' => TRUE, 'node' => (int) $node->id()]);
    $link->save();
    $this->updateChild($link, $node, $source, $weight);
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
    if (!$this->preservesEditorOrder($source) && (int) $link->getWeight() !== $weight) {
      $link->set('weight', $weight);
      $changed = TRUE;
    }
    $uri = 'entity:node/' . $node->id();
    $link_item = $link->get('link')->first();
    $current = $link_item !== NULL ? ($link_item->getValue()['uri'] ?? NULL) : NULL;
    if ($current !== $uri) {
      $link->set('link', ['uri' => $uri]);
      $changed = TRUE;
    }
    if ($changed) {
      $link->save();
    }
  }

  /**
   * TRUE when the parent keeps editor-set child weights (drag order).
   *
   * Manual sources always follow the hand-picked node list, even if a leftover
   * or site-default `preserve` value is stored on the descriptor.
   */
  private function preservesEditorOrder(array $source): bool {
    return ($source['type'] ?? '') !== 'manual' && ($source['sort'] ?? '') === 'preserve';
  }

  /**
   * Weight for a newly created child when the editor order is preserved.
   *
   * Appends after every current sibling so a new published node does not
   * land in the middle of a hand-arranged list.
   */
  private function nextAppendWeight(MenuLinkContentInterface $parent): int {
    $max = -1;
    foreach ($this->loadChildren($parent) as $link) {
      $max = max($max, (int) $link->getWeight());
    }
    return $max + 1;
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
   * How unmanaged children under a parent are treated during sync.
   *
   * @return string
   *   One of adopt, adopt_prune, add, or replace. Unknown values become adopt.
   */
  private function existingChildrenPolicy(array $source): string {
    $policy = (string) ($source['existing_children'] ?? 'adopt');
    return in_array($policy, ['adopt', 'adopt_prune', 'add', 'replace'], TRUE)
      ? $policy
      : 'adopt';
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
