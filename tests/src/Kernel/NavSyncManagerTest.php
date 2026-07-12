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
    $this->assertSame('entity:node/' . $node->id(), $child->get('link')->first()->uri, 'The child stores a canonical node URI, never an editorial path.');

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
   * Loads the managed child links under a parent.
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
