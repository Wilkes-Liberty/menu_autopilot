<?php

declare(strict_types=1);

namespace Drupal\Tests\menu_autopilot\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\menu_link_content\MenuLinkContentInterface;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\path_alias\Entity\PathAlias;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;

/**
 * Covers the automatic-children sync engine.
 *
 * @group menu_autopilot
 * @coversDefaultClass \Drupal\menu_autopilot\NavSyncManager
 */
final class NavSyncManagerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'node',
    'taxonomy',
    'link',
    'menu_link_content',
    'path_alias',
    'menu_autopilot',
  ];

  /**
   * The taxonomy term whose members become menu children.
   */
  private Term $platform;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('menu_link_content');
    $this->installEntitySchema('path_alias');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'filter', 'node', 'taxonomy']);

    NodeType::create(['type' => 'solution', 'name' => 'Solution'])->save();

    Vocabulary::create(['vid' => 'solution_type', 'name' => 'Solution type'])->save();
    $this->platform = Term::create(['vid' => 'solution_type', 'name' => 'Platform']);
    $this->platform->save();

    FieldStorageConfig::create([
      'field_name' => 'field_solution_type',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'taxonomy_term'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_solution_type',
      'entity_type' => 'node',
      'bundle' => 'solution',
      'settings' => ['handler_settings' => ['target_bundles' => ['solution_type' => 'solution_type']]],
    ])->save();
  }

  /**
   * Publishing a matching node adds a clean-URI child; unpublishing removes it.
   *
   * @covers ::syncNode
   */
  public function testPublishAddsChildAndUnpublishRemovesIt(): void {
    $parent = $this->createDynamicParent();

    $node = $this->createSolution('Governed AI', TRUE);

    $children = $this->childrenOf($parent);
    $this->assertCount(1, $children, 'A child link is created for the published node.');
    $child = reset($children);
    $this->assertSame('Governed AI', $child->getTitle());
    $this->assertSame('entity:node/' . $node->id(), $child->get('link')->first()->getValue()['uri'], 'The child stores a canonical node URI, never an editorial path.');

    $node->setUnpublished()->save();
    $this->assertCount(0, $this->childrenOf($parent), 'Unpublishing the node removes its child link.');
  }

  /**
   * An unpublished node never produces a child.
   *
   * @covers ::syncNode
   */
  public function testUnpublishedNodeProducesNoChild(): void {
    $parent = $this->createDynamicParent();
    $this->createSolution('Draft platform', FALSE);
    $this->assertCount(0, $this->childrenOf($parent));
  }

  /**
   * Reconcile is idempotent: it never churns (delete + recreate) child links.
   *
   * @covers ::reconcile
   */
  public function testReconcileIsIdempotent(): void {
    $parent = $this->createDynamicParent();
    $this->createSolution('One', TRUE);
    $this->createSolution('Two', TRUE);

    /** @var \Drupal\menu_autopilot\NavSyncManager $sync */
    $sync = $this->container->get('menu_autopilot.sync_manager');

    $sync->reconcile();
    $before = array_keys($this->childrenOf($parent));
    $this->assertCount(2, $before);

    $sync->reconcile();
    $after = array_keys($this->childrenOf($parent));
    $this->assertSame($before, $after, 'A second reconcile reuses the same links — no delete/recreate churn.');
  }

  /**
   * A title pattern renames children via tokens.
   *
   * @covers ::syncNode
   */
  public function testTitlePatternIsApplied(): void {
    $parent = $this->createDynamicParent(['title_pattern' => '[node:title] platform']);
    $this->createSolution('Helios', TRUE);

    $children = $this->childrenOf($parent);
    $child = reset($children);
    $this->assertSame('Helios platform', $child->getTitle());
  }

  /**
   * Enabling automatic children reuses existing child links.
   *
   * A parent that already has hand-created children — the typical adoption
   * path — must take those links over when they already point at a source
   * node. Creating a second, managed copy is the bug this covers.
   *
   * @covers ::syncParent
   */
  public function testEnablingAutomaticChildrenAdoptsExistingLinks(): void {
    $parent = MenuLinkContent::create([
      'title' => 'Platforms',
      'menu_name' => 'main',
      'link' => ['uri' => 'route:<nolink>'],
    ]);
    $parent->save();

    $atlas = $this->createSolution('Atlas', TRUE);
    $helios = $this->createSolution('Helios', TRUE);
    $unrelated = Node::create([
      'type' => 'solution',
      'title' => 'Curated extra',
      'status' => 1,
    ]);
    $unrelated->save();

    $existing_atlas = MenuLinkContent::create([
      'title' => 'Atlas (hand)',
      'menu_name' => 'main',
      'parent' => 'menu_link_content:' . $parent->uuid(),
      'link' => ['uri' => 'entity:node/' . $atlas->id()],
    ]);
    $existing_atlas->save();
    $existing_helios = MenuLinkContent::create([
      'title' => 'Helios (hand)',
      'menu_name' => 'main',
      'parent' => 'menu_link_content:' . $parent->uuid(),
      'link' => ['uri' => 'internal:/node/' . $helios->id()],
    ]);
    $existing_helios->save();
    $existing_extra = MenuLinkContent::create([
      'title' => 'Curated extra',
      'menu_name' => 'main',
      'parent' => 'menu_link_content:' . $parent->uuid(),
      'link' => ['uri' => 'entity:node/' . $unrelated->id()],
    ]);
    $existing_extra->save();

    $this->assertCount(3, $this->childrenOf($parent));

    // The editor path: turn the existing parent into a dynamic source.
    $this->enableAutomaticChildren($parent);

    $children = $this->childrenOf($parent);
    $this->assertCount(3, $children, 'Matching children are adopted, not duplicated.');
    $this->assertArrayHasKey((int) $existing_atlas->id(), $children);
    $this->assertArrayHasKey((int) $existing_helios->id(), $children);
    $this->assertArrayHasKey((int) $existing_extra->id(), $children);

    $atlas_link = $children[(int) $existing_atlas->id()];
    $helios_link = $children[(int) $existing_helios->id()];
    $extra_link = $children[(int) $existing_extra->id()];

    $this->assertTrue($this->isManagedLink($atlas_link));
    $this->assertTrue($this->isManagedLink($helios_link));
    $this->assertFalse($this->isManagedLink($extra_link), 'Non-source children stay curated.');
    $this->assertSame(
      'entity:node/' . $helios->id(),
      $helios_link->get('link')->first()->getValue()['uri'],
      'Adopted editorial URIs become canonical.',
    );
  }

  /**
   * A later reconcile drops unmanaged copies left by the old duplicate bug.
   *
   * @covers ::syncParent
   */
  public function testReconcileRemovesUnmanagedDuplicates(): void {
    $parent = $this->createDynamicParent();
    $node = $this->createSolution('Helios', TRUE);

    $children = $this->childrenOf($parent);
    $this->assertCount(1, $children);
    $managed = reset($children);

    $stale = MenuLinkContent::create([
      'title' => 'Helios (stale)',
      'menu_name' => 'main',
      'parent' => 'menu_link_content:' . $parent->uuid(),
      'link' => ['uri' => 'entity:node/' . $node->id()],
    ]);
    $stale->save();
    $this->assertCount(2, $this->childrenOf($parent));

    /** @var \Drupal\menu_autopilot\NavSyncManager $sync */
    $sync = $this->container->get('menu_autopilot.sync_manager');
    $sync->reconcile();

    $after = $this->childrenOf($parent);
    $this->assertCount(1, $after, 'The unmanaged duplicate is removed.');
    $this->assertArrayHasKey((int) $managed->id(), $after);
    $this->assertArrayNotHasKey((int) $stale->id(), $after);
  }

  /**
   * Add-only fills gaps and leaves hand-created children unmanaged.
   *
   * @covers ::syncParent
   */
  public function testAddOnlyFillsGapsWithoutDuplicating(): void {
    $parent = MenuLinkContent::create([
      'title' => 'Platforms',
      'menu_name' => 'main',
      'link' => ['uri' => 'route:<nolink>'],
    ]);
    $parent->save();

    $atlas = $this->createSolution('Atlas', TRUE);
    $helios = $this->createSolution('Helios', TRUE);
    $unrelated = Node::create([
      'type' => 'solution',
      'title' => 'Curated extra',
      'status' => 1,
    ]);
    $unrelated->save();

    $existing_atlas = MenuLinkContent::create([
      'title' => 'Atlas (hand)',
      'menu_name' => 'main',
      'parent' => 'menu_link_content:' . $parent->uuid(),
      'link' => ['uri' => 'entity:node/' . $atlas->id()],
    ]);
    $existing_atlas->save();
    $existing_extra = MenuLinkContent::create([
      'title' => 'Curated extra',
      'menu_name' => 'main',
      'parent' => 'menu_link_content:' . $parent->uuid(),
      'link' => ['uri' => 'entity:node/' . $unrelated->id()],
    ]);
    $existing_extra->save();

    $this->enableAutomaticChildren($parent, 'add');

    $children = $this->childrenOf($parent);
    $this->assertCount(3, $children);
    $this->assertArrayHasKey((int) $existing_atlas->id(), $children);
    $this->assertArrayHasKey((int) $existing_extra->id(), $children);
    $this->assertFalse($this->isManagedLink($children[(int) $existing_atlas->id()]));
    $this->assertSame('Atlas (hand)', $children[(int) $existing_atlas->id()]->getTitle());
    $this->assertFalse($this->isManagedLink($children[(int) $existing_extra->id()]));

    $managed = array_filter($children, $this->isManagedLink(...));
    $this->assertCount(1, $managed);
    $helios_link = reset($managed);
    $this->assertSame('Helios', $helios_link->getTitle());
    $this->assertSame('entity:node/' . $helios->id(), $helios_link->get('link')->first()->getValue()['uri']);
  }

  /**
   * Replace deletes unmanaged children, then builds managed ones from scratch.
   *
   * @covers ::syncParent
   */
  public function testReplacePurgesUnmanagedChildren(): void {
    $parent = MenuLinkContent::create([
      'title' => 'Platforms',
      'menu_name' => 'main',
      'link' => ['uri' => 'route:<nolink>'],
    ]);
    $parent->save();

    $atlas = $this->createSolution('Atlas', TRUE);
    $this->createSolution('Helios', TRUE);
    $unrelated = Node::create([
      'type' => 'solution',
      'title' => 'Curated extra',
      'status' => 1,
    ]);
    $unrelated->save();

    $existing_atlas = MenuLinkContent::create([
      'title' => 'Atlas (hand)',
      'menu_name' => 'main',
      'parent' => 'menu_link_content:' . $parent->uuid(),
      'link' => ['uri' => 'entity:node/' . $atlas->id()],
    ]);
    $existing_atlas->save();
    $existing_extra = MenuLinkContent::create([
      'title' => 'Curated extra',
      'menu_name' => 'main',
      'parent' => 'menu_link_content:' . $parent->uuid(),
      'link' => ['uri' => 'entity:node/' . $unrelated->id()],
    ]);
    $existing_extra->save();

    $this->enableAutomaticChildren($parent, 'replace');

    $children = $this->childrenOf($parent);
    $this->assertCount(2, $children, 'Unmanaged children are gone; two source nodes remain.');
    $this->assertArrayNotHasKey((int) $existing_atlas->id(), $children);
    $this->assertArrayNotHasKey((int) $existing_extra->id(), $children);
    foreach ($children as $child) {
      $this->assertTrue($this->isManagedLink($child));
    }
  }

  /**
   * Adopt-and-prune keeps matching ids and drops curated extras.
   *
   * @covers ::syncParent
   */
  public function testAdoptPruneRemovesExtrasKeepsMatchingIds(): void {
    $parent = MenuLinkContent::create([
      'title' => 'Platforms',
      'menu_name' => 'main',
      'link' => ['uri' => 'route:<nolink>'],
    ]);
    $parent->save();

    $atlas = $this->createSolution('Atlas', TRUE);
    $unrelated = Node::create([
      'type' => 'solution',
      'title' => 'Curated extra',
      'status' => 1,
    ]);
    $unrelated->save();

    $existing_atlas = MenuLinkContent::create([
      'title' => 'Atlas (hand)',
      'menu_name' => 'main',
      'parent' => 'menu_link_content:' . $parent->uuid(),
      'link' => ['uri' => 'entity:node/' . $atlas->id()],
    ]);
    $existing_atlas->save();
    $existing_extra = MenuLinkContent::create([
      'title' => 'Curated extra',
      'menu_name' => 'main',
      'parent' => 'menu_link_content:' . $parent->uuid(),
      'link' => ['uri' => 'entity:node/' . $unrelated->id()],
    ]);
    $existing_extra->save();

    $this->enableAutomaticChildren($parent, 'adopt_prune');

    $children = $this->childrenOf($parent);
    $this->assertCount(1, $children);
    $this->assertArrayHasKey((int) $existing_atlas->id(), $children);
    $this->assertArrayNotHasKey((int) $existing_extra->id(), $children);
    $this->assertTrue($this->isManagedLink($children[(int) $existing_atlas->id()]));
  }

  /**
   * Reparenting moves a sibling match under the parent, then adopts it.
   *
   * @covers ::syncParent
   */
  public function testReparentMovesSiblingThenAdopts(): void {
    $parent = MenuLinkContent::create([
      'title' => 'Platforms',
      'menu_name' => 'main',
      'link' => ['uri' => 'route:<nolink>'],
    ]);
    $parent->save();

    $helios = $this->createSolution('Helios', TRUE);
    $sibling = MenuLinkContent::create([
      'title' => 'Helios (top-level)',
      'menu_name' => 'main',
      'link' => ['uri' => 'entity:node/' . $helios->id()],
    ]);
    $sibling->save();

    $this->enableAutomaticChildren($parent, 'adopt', TRUE);

    $children = $this->childrenOf($parent);
    $this->assertCount(1, $children);
    $this->assertArrayHasKey((int) $sibling->id(), $children);
    $this->assertTrue($this->isManagedLink($children[(int) $sibling->id()]));
    $this->assertSame(
      'menu_link_content:' . $parent->uuid(),
      $children[(int) $sibling->id()]->getParentId(),
    );
  }

  /**
   * Another automatic parent keeps its matching children.
   *
   * @covers ::syncParent
   */
  public function testReparentDoesNotStealFromAnotherDynamicParent(): void {
    $other = $this->createDynamicParent();
    $this->createSolution('Helios', TRUE);
    $other_children = $this->childrenOf($other);
    $this->assertCount(1, $other_children);
    $owned = reset($other_children);

    $parent = MenuLinkContent::create([
      'title' => 'Also platforms',
      'menu_name' => 'main',
      'link' => ['uri' => 'route:<nolink>'],
    ]);
    $parent->save();
    $this->enableAutomaticChildren($parent, 'adopt', TRUE);

    $this->assertSame(
      'menu_link_content:' . $other->uuid(),
      $this->reloadLink((int) $owned->id())->getParentId(),
    );
    $this->assertArrayNotHasKey((int) $owned->id(), $this->childrenOf($parent));
  }

  /**
   * A child stored as a path alias is treated as the same node.
   *
   * @covers ::syncParent
   */
  public function testAliasUriIsTreatedAsMatchingNode(): void {
    $parent = MenuLinkContent::create([
      'title' => 'Platforms',
      'menu_name' => 'main',
      'link' => ['uri' => 'route:<nolink>'],
    ]);
    $parent->save();

    $helios = $this->createSolution('Helios', TRUE);
    PathAlias::create([
      'path' => '/node/' . $helios->id(),
      'alias' => '/platforms/helios',
    ])->save();

    $existing = MenuLinkContent::create([
      'title' => 'Helios (alias)',
      'menu_name' => 'main',
      'parent' => 'menu_link_content:' . $parent->uuid(),
      'link' => ['uri' => 'internal:/platforms/helios'],
    ]);
    $existing->save();

    $this->enableAutomaticChildren($parent);

    $children = $this->childrenOf($parent);
    $this->assertCount(1, $children);
    $this->assertArrayHasKey((int) $existing->id(), $children);
    $this->assertTrue($this->isManagedLink($children[(int) $existing->id()]));
    $this->assertSame(
      'entity:node/' . $helios->id(),
      $children[(int) $existing->id()]->get('link')->first()->getValue()['uri'],
    );
  }

  /**
   * Editorial node link URIs are rewritten to canonical entity references.
   *
   * @covers ::normalizeNodeUris
   */
  public function testNormalizeNodeUris(): void {
    $node = $this->createSolution('Governed AI', TRUE);
    $link = MenuLinkContent::create([
      'title' => 'Governed AI',
      'menu_name' => 'main',
      'link' => ['uri' => 'internal:/node/' . $node->id() . '/latest'],
    ]);
    $link->save();

    /** @var \Drupal\menu_autopilot\NavSyncManager $sync */
    $sync = $this->container->get('menu_autopilot.sync_manager');
    $changed = $sync->normalizeNodeUris(['main']);

    $this->assertArrayHasKey((int) $link->id(), $changed);
    $storage = $this->container->get('entity_type.manager')->getStorage('menu_link_content');
    $reloaded = $storage->load($link->id());
    $this->assertSame('entity:node/' . $node->id(), $reloaded->get('link')->first()->getValue()['uri']);

    // Idempotent: a second pass changes nothing.
    $this->assertSame([], $sync->normalizeNodeUris(['main']));
  }

  /**
   * Turns a saved parent into a dynamic source and reconciles its children.
   */
  private function enableAutomaticChildren(MenuLinkContentInterface $parent, string $policy = 'adopt', bool $reparent = FALSE): void {
    $parent->set('menu_autopilot', [
      'source' => [
        'type' => 'term',
        'reference_field' => 'field_solution_type',
        'term' => (int) $this->platform->id(),
        'sort' => 'title_asc',
        'limit' => 0,
        'existing_children' => $policy,
        'reparent_matches' => $reparent,
      ],
    ]);
    $parent->save();
  }

  /**
   * Reloads a menu link from storage.
   */
  private function reloadLink(int $id): MenuLinkContentInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('menu_link_content');
    $storage->resetCache([$id]);
    $link = $storage->load($id);
    $this->assertInstanceOf(MenuLinkContentInterface::class, $link);
    return $link;
  }

  /**
   * Creates the "Platforms" dynamic parent link sourced from the Platform term.
   *
   * @param array $overrides
   *   Extra source-descriptor keys to merge in.
   *
   * @return \Drupal\menu_link_content\MenuLinkContentInterface
   *   The saved parent link.
   */
  private function createDynamicParent(array $overrides = []): MenuLinkContentInterface {
    $parent = MenuLinkContent::create([
      'title' => 'Platforms',
      'menu_name' => 'main',
      'link' => ['uri' => 'route:<nolink>'],
      'menu_autopilot' => [
        'source' => [
          'type' => 'term',
          'reference_field' => 'field_solution_type',
          'term' => (int) $this->platform->id(),
          'sort' => 'title_asc',
          'limit' => 0,
        ] + $overrides,
      ],
    ]);
    $parent->save();
    return $parent;
  }

  /**
   * Creates a solution node tagged Platform.
   */
  private function createSolution(string $title, bool $published): Node {
    $node = Node::create([
      'type' => 'solution',
      'title' => $title,
      'status' => $published,
      'field_solution_type' => ['target_id' => $this->platform->id()],
    ]);
    $node->save();
    return $node;
  }

  /**
   * Whether a link is flagged as a Menu Autopilot-managed child.
   */
  private function isManagedLink(MenuLinkContentInterface $link): bool {
    if ($link->get('menu_autopilot')->isEmpty()) {
      return FALSE;
    }
    return !empty($link->get('menu_autopilot')->first()->getValue()['managed']);
  }

  /**
   * Loads the child links under a parent.
   *
   * @return \Drupal\menu_link_content\MenuLinkContentInterface[]
   *   Child links keyed by entity id.
   */
  private function childrenOf(MenuLinkContentInterface $parent): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('menu_link_content');
    $ids = $storage->getQuery()
      ->condition('parent', 'menu_link_content:' . $parent->uuid())
      ->accessCheck(FALSE)
      ->execute();
    return $storage->loadMultiple($ids);
  }

}
