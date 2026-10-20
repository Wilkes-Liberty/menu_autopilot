<?php

declare(strict_types=1);

namespace Drupal\menu_autopilot;

use Drupal\Core\DestructableInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Utility\Token;
use Drupal\menu_link_content\MenuLinkContentInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Keeps auto-generated child menu links in sync with their source content.
 *
 * Managed child links are ordinary menu_link_content entities carrying a
 * `menu_autopilot` map field of `['managed' => TRUE, 'node' => <nid>]`, so any
 * menu consumer (GraphQL, JSON:API, a theme) sees them with no extra work.
 * Every managed link uses a canonical `entity:node/<nid>` URI so it resolves to
 * the node's real path alias — never a raw or editorial path.
 *
 * The map also carries `disabled_by_save => TRUE` when one of this manager's
 * own saves asked for an enabled child and storage holds a disabled one.
 * Another module's presave hook can do that: `enabled` is the published key of
 * menu_link_content. The flag separates those links from the ones an editor
 * disabled, which a sync never enables.
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

  /**
   * Managed children to delete after core reparents them off a dying parent.
   *
   * Keys are child entity ids; values are the parent plugin id they left.
   *
   * @var array<int, string>
   */
  private array $pendingManagedDeletes = [];

  /**
   * Flagged links this request already tried to enable, per acting account.
   *
   * Keys are link ids, then account ids. One try per account per request: an
   * account whose save was disabled once gets the same answer again.
   *
   * @var array<int, array<int, true>>
   */
  private array $enableAttempts = [];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly NavSourceResolver $resolver,
    private readonly Token $token,
    private readonly AccountProxyInterface $currentUser,
    private readonly LoggerInterface $logger,
    private readonly NavStatusReporter $statusReporter,
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
      $this->syncNode($node);
    }
  }

  /**
   * React to a node change: reconcile every dynamic parent it can affect.
   */
  public function syncNode(NodeInterface $node): void {
    foreach ($this->statusReporter->findDynamicParents() as $parent) {
      $siblings = $this->statusReporter->loadChildren($parent);
      $partition = $this->statusReporter->partitionChildren($siblings);
      if ($this->parentAffectedByNode($parent, $node, $partition['owned'])) {
        $this->doSyncParent($parent, $siblings, $partition);
      }
    }
  }

  /**
   * If an editor-saved link is a dynamic parent, reconcile its children.
   *
   * Ignores links the manager owns, so it never reacts to its own writes.
   */
  public function syncParentIfDynamic(MenuLinkContentInterface $link): void {
    if ($this->statusReporter->isManaged($link)) {
      return;
    }
    $source = $this->statusReporter->getSource($link);
    if (($source['type'] ?? 'none') === 'none'
      || $this->statusReporter->isUnavailableTermSource($source)) {
      return;
    }
    $this->syncParent($link);
  }

  /**
   * Reconcile the managed children under a single dynamic parent (idempotent).
   */
  private function syncParent(MenuLinkContentInterface $parent): void {
    if ($this->syncing) {
      return;
    }
    $siblings = $this->statusReporter->loadChildren($parent);
    $this->doSyncParent(
      $parent,
      $siblings,
      $this->statusReporter->partitionChildren($siblings),
    );
  }

  /**
   * Clear sticky owned flags when a parent is no longer dynamic.
   *
   * “Nothing (curated by hand)” keeps the child links; it only drops the
   * managed bookkeeping so editors can reclaim them. Any non-dynamic parent
   * save is enough — leftover owned flags with no source are orphans.
   */
  public function releaseOwnedChildrenIfSourceCleared(MenuLinkContentInterface $link): void {
    if ($this->syncing || $this->statusReporter->isManaged($link)) {
      return;
    }
    if (($this->statusReporter->getSource($link)['type'] ?? 'none') !== 'none') {
      return;
    }
    $this->releaseOwnedChildren($link);
  }

  /**
   * Note a managed child whose parent is changing outside our own writes.
   *
   * MenuLinkContent::preDelete reparents children and saves them before
   * hook_entity_predelete. Compare the in-memory parent to the stored one
   * during presave (the DB still has the old parent). Deletion waits until
   * that previous parent is itself deleted, so an editor or API reparent
   * is not treated as a delete.
   */
  public function flagManagedIfParentMoving(MenuLinkContentInterface $link): void {
    if ($this->syncing
      || !$this->statusReporter->isManaged($link)
      || !$link->id()) {
      return;
    }
    $stored = $this->entityTypeManager
      ->getStorage('menu_link_content')
      ->loadUnchanged((int) $link->id());
    if (!$stored instanceof MenuLinkContentInterface) {
      return;
    }
    if ($stored->getParentId() !== $link->getParentId()) {
      $this->pendingManagedDeletes[(int) $link->id()] = $stored->getParentId();
    }
  }

  /**
   * Drop a `disabled_by_save` flag from a link that has since been enabled.
   *
   * Runs in presave for saves that are not this manager's own. A flagged link
   * that is enabled in storage was enabled by someone else, for example on
   * the menu overview form, where no sync runs. The flag has done its job.
   * Dropping it here means a later editor disable is never undone by a sync.
   */
  public function clearStaleDisabledBySaveFlag(MenuLinkContentInterface $link): void {
    if ($this->syncing || !$link->id() || !$this->isDisabledBySave($link)) {
      return;
    }
    $stored = $this->entityTypeManager
      ->getStorage('menu_link_content')
      ->loadUnchanged((int) $link->id());
    if ($stored instanceof MenuLinkContentInterface && $stored->isEnabled()) {
      $this->setDisabledBySave($link, FALSE);
    }
  }

  /**
   * Make a disabled, flagged link the editor's own choice.
   *
   * Called when the link form is saved with Enabled unchecked. Does not save.
   */
  public function releaseDisabledBySaveFlag(MenuLinkContentInterface $link): void {
    if ($this->isDisabledBySave($link)) {
      $this->setDisabledBySave($link, FALSE);
    }
  }

  /**
   * Delete managed children flagged by flagManagedIfParentMoving().
   *
   * Only children that were moved off $parent are removed. A parent change
   * alone is not a delete.
   *
   * @param \Drupal\menu_link_content\MenuLinkContentInterface $parent
   *   The parent being deleted.
   */
  private function flushPendingManagedDeletes(MenuLinkContentInterface $parent): void {
    if ($this->pendingManagedDeletes === [] || $this->syncing) {
      return;
    }
    $from = $parent->getPluginId();
    $ids = [];
    foreach ($this->pendingManagedDeletes as $id => $old_parent) {
      if ($old_parent === $from) {
        $ids[] = $id;
        unset($this->pendingManagedDeletes[$id]);
      }
    }
    if ($ids === []) {
      return;
    }
    $this->syncing = TRUE;
    try {
      $storage = $this->entityTypeManager->getStorage('menu_link_content');
      foreach ($storage->loadMultiple($ids) as $link) {
        if ($this->statusReporter->isManaged($link)) {
          $link->delete();
        }
      }
    }
    finally {
      $this->syncing = FALSE;
    }
  }

  /**
   * Delete generated children still hanging off a parent being deleted.
   *
   * Flushes children core already reparented in MenuLinkContent::preDelete.
   * Loading children is then usually empty; it remains a backup for deletes
   * that do not go through that reparenting.
   */
  public function onParentDeleted(MenuLinkContentInterface $parent): void {
    if ($this->syncing) {
      return;
    }
    $this->flushPendingManagedDeletes($parent);
    $this->syncing = TRUE;
    try {
      foreach ($this->statusReporter->loadChildren($parent) as $link) {
        if ($this->statusReporter->isManaged($link)) {
          $link->delete();
        }
      }
    }
    finally {
      $this->syncing = FALSE;
    }
  }

  /**
   * Reconcile one parent from an already-loaded sibling set.
   *
   * @param \Drupal\menu_link_content\MenuLinkContentInterface $parent
   *   The dynamic parent.
   * @param \Drupal\menu_link_content\MenuLinkContentInterface[] $siblings
   *   Direct children keyed by entity id.
   * @param array $partition
   *   Keys owned and adoptable (node id => link) plus append_weight.
   */
  private function doSyncParent(MenuLinkContentInterface $parent, array $siblings, array $partition): void {
    if ($this->syncing) {
      return;
    }
    $source = $this->statusReporter->getSource($parent);
    if ($this->statusReporter->isUnavailableTermSource($source)) {
      return;
    }
    $desired = $this->resolver->resolve($source);
    $existing = $partition['owned'];
    $nodes = $desired ? $this->entityTypeManager->getStorage('node')->loadMultiple($desired) : [];

    $this->syncing = TRUE;
    try {
      $policy = $this->statusReporter->existingChildrenPolicy($source);
      if (!empty($source['reparent_matches'])) {
        foreach ($this->reparentMatchingLinks($parent, $desired) as $id => $link) {
          $siblings[$id] = $link;
        }
        $partition = $this->statusReporter->partitionChildren($siblings);
        $existing = $partition['owned'];
      }
      if ($policy === 'replace') {
        foreach ($siblings as $id => $link) {
          if (!$this->statusReporter->isManaged($link)) {
            $link->delete();
            unset($siblings[$id]);
          }
        }
        $partition = $this->statusReporter->partitionChildren($siblings);
        $existing = $partition['owned'];
      }

      $weight = 0;
      $seen = [];
      $preserve = $this->statusReporter->preservesEditorOrder($source);
      $append_weight = $preserve ? $partition['append_weight'] : 0;
      $adoptable = $policy === 'replace' ? [] : $partition['adoptable'];
      $owned = $existing;
      foreach ($desired as $nid) {
        $node = $nodes[$nid] ?? NULL;
        if (!$node instanceof NodeInterface) {
          continue;
        }
        if ($link = $owned[$nid] ?? NULL) {
          $this->enableIfDisabledBySave($link, $parent, $node);
          $this->updateChild($link, $parent, $node, $source, $preserve ? (int) $link->getWeight() : $weight);
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
          $this->adoptChild($link, $parent, $node, $source, $preserve ? (int) $link->getWeight() : $weight);
          $owned[$nid] = $link;
        }
        else {
          $link = $this->createChild($parent, $node, $source, $preserve ? $append_weight++ : $weight);
          $owned[$nid] = $link;
          $siblings[(int) $link->id()] = $link;
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
          unset($owned[$nid], $siblings[(int) $link->id()]);
        }
      }
      // Drop unmanaged twins of a node we already manage. Hand-created
      // extras (and add-only matches we left unmanaged) are not in $owned,
      // so they stay.
      foreach ($siblings as $id => $link) {
        if ($this->statusReporter->isManaged($link)) {
          continue;
        }
        $nid = $this->statusReporter->nodeIdFromLink($link);
        if ($nid !== NULL && isset($owned[$nid])) {
          $link->delete();
          unset($siblings[$id]);
        }
      }
      if ($policy === 'adopt_prune') {
        foreach ($siblings as $link) {
          if (!$this->statusReporter->isManaged($link)) {
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
    foreach ($this->statusReporter->findDynamicParents() as $parent) {
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
   * @return array<int, array{from: string, to: string}>
   *   Changed links keyed by id, each with the previous URI and the
   *   canonical URI. Callers that print a change format the arrow.
   */
  public function normalizeNodeUris(?array $menu_names = NULL): array {
    $menus = $menu_names ?? $this->statusReporter->managedMenus();
    if ($menus === []) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('menu_link_content');
    $ids = $storage->getQuery()
      ->condition('menu_name', $menus, 'IN')
      ->accessCheck(FALSE)
      ->execute();

    $changed = [];
    $was_syncing = $this->syncing;
    $this->syncing = TRUE;
    try {
      foreach ($storage->loadMultiple($ids) as $link) {
        $item = $link->get('link')->first();
        if ($item === NULL) {
          continue;
        }
        $value = $item->getValue();
        $uri = (string) ($value['uri'] ?? '');
        $canonical = $this->statusReporter->canonicalNodeUri($uri);
        if ($canonical !== NULL && $canonical !== $uri) {
          $value['uri'] = $canonical;
          $link->set('link', $value);
          $link->save();
          $changed[(int) $link->id()] = [
            'from' => $uri,
            'to' => $canonical,
          ];
        }
      }
    }
    finally {
      $this->syncing = $was_syncing;
    }
    return $changed;
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
   *
   * @return \Drupal\menu_link_content\MenuLinkContentInterface[]
   *   Links moved under the parent, keyed by entity id.
   */
  private function reparentMatchingLinks(MenuLinkContentInterface $parent, array $desired): array {
    if ($desired === []) {
      return [];
    }
    $wanted = array_fill_keys($desired, TRUE);
    $protected = ['menu_link_content:' . $parent->uuid() => TRUE];
    foreach ($this->statusReporter->findDynamicParents() as $other) {
      $protected['menu_link_content:' . $other->uuid()] = TRUE;
    }
    $storage = $this->entityTypeManager->getStorage('menu_link_content');
    $ids = $storage->getQuery()
      ->condition('menu_name', $parent->getMenuName())
      ->accessCheck(FALSE)
      ->execute();
    $moved = [];
    foreach ($storage->loadMultiple($ids) as $link) {
      if ($this->statusReporter->isManaged($link)) {
        continue;
      }
      if ((int) $link->id() === (int) $parent->id()) {
        continue;
      }
      if (isset($protected[$link->getParentId()])) {
        continue;
      }
      $nid = $this->statusReporter->nodeIdFromLink($link);
      if ($nid === NULL || !isset($wanted[$nid])) {
        continue;
      }
      $link->set('parent', 'menu_link_content:' . $parent->uuid());
      $link->save();
      $moved[(int) $link->id()] = $link;
    }
    return $moved;
  }

  /**
   * Whether a node change could add, remove, or update a link under a parent.
   *
   * @param \Drupal\menu_link_content\MenuLinkContentInterface $parent
   *   The dynamic parent.
   * @param \Drupal\node\NodeInterface $node
   *   The node that changed.
   * @param \Drupal\menu_link_content\MenuLinkContentInterface[] $owned
   *   Already-partitioned owned children keyed by node id.
   */
  private function parentAffectedByNode(MenuLinkContentInterface $parent, NodeInterface $node, array $owned): bool {
    // The parent already has an owned link (it may need updating or removing).
    if (isset($owned[(int) $node->id()])) {
      return TRUE;
    }
    // Otherwise, the node may newly match this parent's source.
    return $this->nodeMatchesSource($node, $this->statusReporter->getSource($parent));
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
  private function adoptChild(MenuLinkContentInterface $link, MenuLinkContentInterface $parent, NodeInterface $node, array $source, int $weight): void {
    $link->set('menu_autopilot', ['managed' => TRUE, 'node' => (int) $node->id()]);
    $this->saveChild($link, $parent, $node);
    $this->updateChild($link, $parent, $node, $source, $weight);
  }

  /**
   * Create a managed child link for a node under a parent.
   *
   * @return \Drupal\menu_link_content\MenuLinkContentInterface
   *   The saved child link.
   */
  private function createChild(MenuLinkContentInterface $parent, NodeInterface $node, array $source, int $weight): MenuLinkContentInterface {
    $node = $node->getUntranslated();
    $values = [
      'menu_name' => $parent->getMenuName(),
      'parent' => 'menu_link_content:' . $parent->uuid(),
      'title' => $this->linkTitle($node, $source),
      'link' => ['uri' => 'entity:node/' . $node->id()],
      'weight' => $weight,
      'enabled' => TRUE,
      'menu_autopilot' => ['managed' => TRUE, 'node' => (int) $node->id()],
    ];
    // A translatable link is created in the node's language, so the other
    // languages can be added on it. A bundle that cannot be translated keeps
    // the language create() assigns, which is what this did before.
    $storage = $this->entityTypeManager->getStorage('menu_link_content');
    $probe = $storage->create([]);
    if ($probe->isTranslatable() && !$node->language()->isLocked()) {
      $values['langcode'] = $node->language()->getId();
    }
    $link = $storage->create($values);
    // Translations are written before the first save, so create is one write.
    $this->syncLinkTitles($link, $node, $source);
    $this->saveChild($link, $parent, $node);
    return $link;
  }

  /**
   * Save a managed child, then check the save kept it enabled.
   *
   * Every write this manager makes to a child goes through here, so a create,
   * an adopt and an update are all checked the same way. A child that was
   * already disabled before the save is not checked: that is an editor's
   * choice, or an earlier save this method already flagged.
   */
  private function saveChild(MenuLinkContentInterface $link, MenuLinkContentInterface $parent, NodeInterface $node): void {
    $expect_enabled = $link->isEnabled();
    $created = $link->isNew();
    $link->save();
    if ($expect_enabled) {
      $this->flagIfSaveDisabled($link, $parent, $node, $created);
    }
  }

  /**
   * Log and flag a child that this manager's own save left disabled.
   *
   * The sync asked for an enabled link. `enabled` is the published key of
   * menu_link_content, so another module's presave hook can refuse that for
   * the acting account. Without this the node is published, the sync reports
   * nothing, and no menu shows the link.
   */
  private function flagIfSaveDisabled(MenuLinkContentInterface $link, MenuLinkContentInterface $parent, NodeInterface $node, bool $created): void {
    $stored = $this->entityTypeManager
      ->getStorage('menu_link_content')
      ->loadUnchanged((int) $link->id());
    if (!$stored instanceof MenuLinkContentInterface || $stored->isEnabled()) {
      return;
    }
    $uid = (int) $this->currentUser->id();
    $context = [
      '%title' => $link->getTitle(),
      '@link' => $link->id(),
      '@nid' => $node->id(),
      '%parent' => $parent->getTitle(),
      '@parent_id' => $parent->id(),
      '@uid' => $uid,
    ];
    if ($created) {
      $this->logger->warning('The new menu link %title (link @link) for node @nid under %parent (link @parent_id) was saved disabled, so the menu does not show it. Acting account: uid @uid. Another module disabled it during the save. The next sync run by an account that may enable menu links enables it.', $context);
    }
    else {
      $this->logger->warning('The menu link %title (link @link) for node @nid under %parent (link @parent_id) was enabled, and a sync update saved it disabled, so the menu no longer shows it. Acting account: uid @uid. Another module disabled it during the save. The next sync run by an account that may enable menu links enables it.', $context);
    }
    // This account's save was just disabled. Do not try again on its behalf.
    $this->enableAttempts[(int) $link->id()][$uid] = TRUE;
    $link->set('enabled', FALSE);
    $this->setDisabledBySave($link, TRUE);
    $link->save();
  }

  /**
   * Enable a child an earlier save disabled, if this account's save sticks.
   *
   * Only links carrying the `disabled_by_save` flag are touched. A link with
   * no flag was disabled by an editor and stays disabled. The flag is kept
   * when the save is disabled again, so a later sync by another account can
   * still enable the link.
   */
  private function enableIfDisabledBySave(MenuLinkContentInterface $link, MenuLinkContentInterface $parent, NodeInterface $node): void {
    if (!$this->isDisabledBySave($link)) {
      return;
    }
    if ($link->isEnabled()) {
      // Enabled outside a sync. The flag has nothing left to say, unless this
      // save is disabled too: saveChild() then flags the link again.
      $this->setDisabledBySave($link, FALSE);
      $this->saveChild($link, $parent, $node);
      return;
    }
    $id = (int) $link->id();
    $uid = (int) $this->currentUser->id();
    if (isset($this->enableAttempts[$id][$uid])) {
      return;
    }
    $this->enableAttempts[$id][$uid] = TRUE;

    $link->set('enabled', TRUE);
    $link->save();
    $stored = $this->entityTypeManager
      ->getStorage('menu_link_content')
      ->loadUnchanged($id);
    if ($stored instanceof MenuLinkContentInterface && $stored->isEnabled()) {
      $this->setDisabledBySave($link, FALSE);
      $this->saveChild($link, $parent, $node);
      $this->logger->notice('Enabled the menu link %title (link @link) under %parent. An earlier sync save had left it disabled. Acting account: uid @uid.', [
        '%title' => $link->getTitle(),
        '@link' => $id,
        '%parent' => $parent->getTitle(),
        '@uid' => $uid,
      ]);
      return;
    }
    // Keep the entity in step with storage so a later save in this sync does
    // not carry a second attempt.
    $link->set('enabled', FALSE);
  }

  /**
   * Whether one of this manager's saves left a managed link disabled.
   */
  private function isDisabledBySave(MenuLinkContentInterface $link): bool {
    $data = $this->statusReporter->getData($link);
    return !empty($data['managed']) && !empty($data['disabled_by_save']);
  }

  /**
   * Set or remove the `disabled_by_save` flag, keeping the rest of the map.
   */
  private function setDisabledBySave(MenuLinkContentInterface $link, bool $flag): void {
    $data = $this->statusReporter->getData($link);
    if ($flag) {
      $data['disabled_by_save'] = TRUE;
    }
    else {
      unset($data['disabled_by_save']);
    }
    $link->set('menu_autopilot', $data);
  }

  /**
   * Update a managed child link to mirror its node (title, weight, URI).
   */
  private function updateChild(MenuLinkContentInterface $link, MenuLinkContentInterface $parent, NodeInterface $node, array $source, int $weight): void {
    $link = $link->getUntranslated();
    $node = $node->getUntranslated();
    $changed = $this->syncLinkTitles($link, $node, $source);
    if (!$this->statusReporter->preservesEditorOrder($source) && (int) $link->getWeight() !== $weight) {
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
      $this->saveChild($link, $parent, $node);
    }
  }

  /**
   * Write one title per language the node has, and drop the rest.
   *
   * The link keeps its own language. That title comes from the node
   * translation in the same language, or from the node's default
   * translation when the node has none. Every other language the node has
   * is created or updated. A link translation the node does not have is
   * removed. Only `title` is written. The caller saves once.
   *
   * @return bool
   *   TRUE when a title was added, changed, or removed.
   */
  private function syncLinkTitles(MenuLinkContentInterface $link, NodeInterface $node, array $source): bool {
    $link = $link->getUntranslated();
    $node = $node->getUntranslated();
    $changed = FALSE;
    $node_languages = $node->getTranslationLanguages();
    $link_lang = $link->language()->getId();
    $source_node = isset($node_languages[$link_lang]) ? $node->getTranslation($link_lang) : $node;
    $title = $this->linkTitle($source_node, $source);
    if ($link->getTitle() !== $title) {
      $link->set('title', $title);
      $changed = TRUE;
    }
    if (!$link->isTranslatable()) {
      return $changed;
    }

    foreach ($node_languages as $langcode => $language) {
      if ($langcode === $link_lang || $language->isLocked()) {
        continue;
      }
      $translated_title = $this->linkTitle($node->getTranslation($langcode), $source);
      if ($link->hasTranslation($langcode)) {
        $translation = $link->getTranslation($langcode);
        if ($translation->getTitle() !== $translated_title) {
          $translation->set('title', $translated_title);
          $changed = TRUE;
        }
        continue;
      }
      try {
        $link->addTranslation($langcode, ['title' => $translated_title]);
        $changed = TRUE;
      }
      catch (\InvalidArgumentException $exception) {
        // A language the node still names, but menu links cannot use
        // (removed from the site, or locked). Keep going: one language
        // must not abort the rest of this parent's sync.
        $this->logger->error('Menu link @link for node @nid was not given a @langcode translation. @message', [
          '@link' => (string) ($link->id() ?: 'new'),
          '@nid' => (string) $node->id(),
          '@langcode' => $langcode,
          '@message' => $exception->getMessage(),
        ]);
      }
    }

    foreach (array_keys($link->getTranslationLanguages()) as $langcode) {
      if ($langcode === $link_lang || isset($node_languages[$langcode])) {
        continue;
      }
      $this->logger->notice('Removed the @langcode translation of menu link %title (link @link) for node @nid. The node no longer has that translation.', [
        '@langcode' => $langcode,
        '%title' => $link->getTranslation($langcode)->getTitle(),
        '@link' => (string) ($link->id() ?: 'new'),
        '@nid' => (string) $node->id(),
      ]);
      $link->removeTranslation($langcode);
      $changed = TRUE;
    }
    return $changed;
  }

  /**
   * The title for a managed child link in the language of $node.
   *
   * Callers pass the node translation whose label should be stored. When
   * the source defines a `title_pattern`, it is run through the token
   * service as plain text (e.g. `[node:title]`, `[node:field_nav_title]`) so
   * editors can give nav a shorter or decorated label than the page title;
   * unreplaced tokens are cleared. Markup replace would HTML-escape
   * ampersands into the stored title. Falls back to the node label when no
   * pattern is set or the pattern resolves to an empty string. URIs are
   * never tokenized — a managed link always points at `entity:node/<nid>`.
   */
  private function linkTitle(NodeInterface $node, array $source): string {
    $pattern = trim((string) ($source['title_pattern'] ?? ''));
    if ($pattern === '') {
      return (string) $node->label();
    }
    $title = trim($this->token->replacePlain(
      $pattern,
      ['node' => $node],
      ['clear' => TRUE, 'langcode' => $node->language()->getId()],
    ));
    return $title !== '' ? $title : (string) $node->label();
  }

  /**
   * Drop managed bookkeeping on children; keep the links themselves.
   */
  private function releaseOwnedChildren(MenuLinkContentInterface $parent): void {
    $this->syncing = TRUE;
    try {
      foreach ($this->statusReporter->loadChildren($parent) as $link) {
        if ($this->statusReporter->isManaged($link)) {
          $link->set('menu_autopilot', NULL);
          $link->save();
        }
      }
    }
    finally {
      $this->syncing = FALSE;
    }
  }

}
