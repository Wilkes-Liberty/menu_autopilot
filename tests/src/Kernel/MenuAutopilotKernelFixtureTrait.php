<?php

declare(strict_types=1);

namespace Drupal\Tests\menu_autopilot\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\menu_link_content\MenuLinkContentInterface;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;

/**
 * Shared solution-type / Platform-term fixture for kernel tests.
 *
 * NavSyncManagerTest, NavStatusReporterTest, and
 * MenuAutopilotFormAndInstallTest all build the same bundle, vocabulary,
 * term, and reference field. Keeping that in one place stops a fourth
 * copy the next time a case moves between those classes.
 * DisabledBySaveChildTest stays out: it uses a page bundle and a
 * different module list on purpose.
 *
 * @phpstan-require-extends \Drupal\KernelTests\KernelTestBase
 */
trait MenuAutopilotKernelFixtureTrait {

  /**
   * The taxonomy term whose members become menu children.
   */
  protected Term $platform;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // The three kernel classes all save solution nodes and menu links, so
    // they share this schema set. path_alias is unused by two of them and
    // stays so the fixture does not drift from what they installed before.
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

    // Term membership is an entity-reference on the solution bundle. The
    // dynamic parent created below points at this field and this term.
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
   * Reloads a menu link from storage.
   *
   * Kernel tests keep a stale entity in memory after a later save, so
   * callers go through this instead of using the object they already hold.
   *
   * @param int $id
   *   The menu_link_content entity id.
   *
   * @return \Drupal\menu_link_content\MenuLinkContentInterface
   *   The freshly loaded link.
   */
  protected function reloadLink(int $id): MenuLinkContentInterface {
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
  protected function createDynamicParent(array $overrides = []): MenuLinkContentInterface {
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
   *
   * @param string $title
   *   The node title.
   * @param bool $published
   *   Whether the node is published.
   *
   * @return \Drupal\node\Entity\Node
   *   The saved node.
   */
  protected function createSolution(string $title, bool $published): Node {
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
   * @param \Drupal\menu_link_content\MenuLinkContentInterface $parent
   *   The parent link whose children should be loaded.
   *
   * @return \Drupal\menu_link_content\MenuLinkContentInterface[]
   *   Child links keyed by entity id.
   */
  protected function childrenOf(MenuLinkContentInterface $parent): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('menu_link_content');
    // menu_link_content stores parent as the plugin id, not the entity id.
    $ids = $storage->getQuery()
      ->condition('parent', 'menu_link_content:' . $parent->uuid())
      ->accessCheck(FALSE)
      ->execute();
    return $storage->loadMultiple($ids);
  }

}
