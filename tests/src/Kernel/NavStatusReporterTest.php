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
 * Covers the read-only status reporter.
 *
 * @group menu_autopilot
 * @coversDefaultClass \Drupal\menu_autopilot\NavStatusReporter
 */
final class NavStatusReporterTest extends KernelTestBase {

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
   * An explicit empty managed_menus list means no menus, not main.
   *
   * Only a missing value uses the install default. Saving [] (unchecking
   * every menu) must not fall back to main.
   *
   * @covers ::managedMenus
   */
  public function testEmptyManagedMenusMeansNoMenus(): void {
    /** @var \Drupal\menu_autopilot\NavStatusReporter $reporter */
    $reporter = $this->container->get('menu_autopilot.status_reporter');
    $this->assertSame(['main'], $reporter->managedMenus(), 'A missing value uses the install default.');

    $this->config('menu_autopilot.settings')->set('managed_menus', [])->save();
    $this->assertSame([], $reporter->managedMenus());
  }

  /**
   * The status report counts each kind of child and is bounded.
   *
   * @covers ::parentStatus
   */
  public function testParentStatusCountsChildrenAndIsBounded(): void {
    $parent = $this->createDynamicParent([
      'existing_children' => 'add',
      'title_pattern' => '[node:title] pattern-marker',
    ]);
    $this->createDynamicParent();
    $owned = $this->createSolution('Atlas', TRUE);
    $disabled = $this->createSolution('Beacon', TRUE);
    $unpublished = $this->createSolution('Draft', FALSE);
    foreach ($this->childrenOf($parent) as $link) {
      if (str_starts_with($link->getTitle(), 'Beacon')) {
        $link->set('enabled', FALSE)->save();
      }
    }
    // Add-only leaves a hand-made node link unmanaged, so it stays adoptable.
    MenuLinkContent::create([
      'title' => 'Hand made',
      'menu_name' => 'main',
      'parent' => 'menu_link_content:' . $parent->uuid(),
      'link' => ['uri' => 'entity:node/' . $unpublished->id()],
    ])->save();
    MenuLinkContent::create([
      'title' => 'Curated',
      'menu_name' => 'main',
      'parent' => 'menu_link_content:' . $parent->uuid(),
      'link' => ['uri' => 'route:<nolink>'],
    ])->save();

    /** @var \Drupal\menu_autopilot\NavStatusReporter $reporter */
    $reporter = $this->container->get('menu_autopilot.status_reporter');
    $report = $reporter->parentStatus();

    $this->assertSame(['main'], $report['managed_menus']);
    $this->assertSame(2, $report['parents_total']);
    $this->assertFalse($report['parents_truncated']);
    $row = array_column($report['parents'], NULL, 'uuid')[$parent->uuid()];
    $this->assertSame('Platforms', $row['title']);
    $this->assertSame('term', $row['source_type']);
    $this->assertSame('add', $row['existing_children']);
    $this->assertSame(
      [
        'owned' => 2,
        'adoptable' => 1,
        'extra' => 1,
        'disabled' => 1,
        'disabled_by_save' => 0,
        'stale_translations' => 0,
      ],
      $row['counts'],
    );
    $this->assertSame(
      [['title' => 'Beacon pattern-marker', 'node' => (int) $disabled->id(), 'disabled_by_save' => FALSE]],
      $row['disabled_children'],
    );
    $this->assertFalse($row['disabled_children_truncated']);
    $this->assertNotSame((int) $owned->id(), $row['disabled_children'][0]['node']);

    // The label pattern is never part of the report.
    $json = json_encode($report);
    $this->assertStringNotContainsString('title_pattern', $json);
    $this->assertStringNotContainsString('[node:', $json);

    $bounded = $reporter->parentStatus(1, 0);
    $this->assertSame(2, $bounded['parents_total']);
    $this->assertTrue($bounded['parents_truncated']);
    $this->assertCount(1, $bounded['parents']);
    $this->assertSame([], $bounded['parents'][0]['disabled_children']);
  }

  /**
   * The reporter reads menus, sources, order and disabled children.
   *
   * @covers ::managedMenus
   * @covers ::getSource
   * @covers ::existingChildrenPolicy
   * @covers ::isManaged
   * @covers ::getData
   * @covers ::menuLinkStorage
   * @covers ::preservesEditorOrder
   * @covers ::disabledManagedChildren
   */
  public function testReporterReadsMenusSourcesOrderAndDisabledChildren(): void {
    $parent = $this->createDynamicParent(['existing_children' => 'add']);
    $this->createSolution('Atlas', TRUE);
    $children = $this->childrenOf($parent);
    $child = reset($children);
    $this->assertInstanceOf(MenuLinkContentInterface::class, $child);

    /** @var \Drupal\menu_autopilot\NavStatusReporter $reporter */
    $reporter = $this->container->get('menu_autopilot.status_reporter');

    $this->assertSame(['main'], $reporter->managedMenus());

    $source = $reporter->getSource($parent);
    $this->assertSame('term', $source['type']);
    $this->assertSame('add', $source['existing_children']);
    $this->assertSame('add', $reporter->existingChildrenPolicy($source));
    $this->assertSame('adopt', $reporter->existingChildrenPolicy(['existing_children' => 'unknown']));

    $this->assertFalse($reporter->isManaged($parent));
    $this->assertTrue($reporter->isManaged($child));
    $data = $reporter->getData($child);
    $this->assertTrue($data['managed']);
    $this->assertGreaterThan(0, (int) ($data['node'] ?? 0));

    $this->assertSame('menu_link_content', $reporter->menuLinkStorage()->getEntityTypeId());

    $this->assertFalse($reporter->preservesEditorOrder($source));
    $this->assertTrue($reporter->preservesEditorOrder(['type' => 'bundle', 'sort' => 'preserve']));
    $this->assertTrue($reporter->preservesEditorOrder(['type' => 'term', 'sort' => 'preserve']));
    $this->assertFalse($reporter->preservesEditorOrder(['type' => 'manual', 'sort' => 'preserve']));
    $this->assertFalse($reporter->preservesEditorOrder(['type' => 'bundle', 'sort' => 'title_asc']));
    $this->assertFalse($reporter->preservesEditorOrder([]));

    $child->set('enabled', FALSE)->save();
    $rows = $reporter->disabledManagedChildren();
    $this->assertCount(1, $rows);
    $this->assertSame((int) $parent->id(), $rows[0]['parent_id']);
    $this->assertSame((int) $child->id(), $rows[0]['link_id']);
    $this->assertSame((int) ($data['node'] ?? 0), $rows[0]['node']);
    $this->assertFalse($rows[0]['disabled_by_save']);
    $this->assertSame($rows, $reporter->disabledManagedChildren($parent));
    $other = MenuLinkContent::create([
      'title' => 'Other',
      'menu_name' => 'main',
      'link' => ['uri' => 'route:<nolink>'],
    ]);
    $other->save();
    $this->assertSame([], $reporter->disabledManagedChildren($other));
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
        'source' => array_merge([
          'type' => 'term',
          'reference_field' => 'field_solution_type',
          'term' => (int) $this->platform->id(),
          'sort' => 'title_asc',
          'limit' => 0,
        ], $overrides),
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
