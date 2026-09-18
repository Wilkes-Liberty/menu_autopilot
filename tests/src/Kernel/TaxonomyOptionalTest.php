<?php

declare(strict_types=1);

namespace Drupal\Tests\menu_autopilot\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\menu_link_content\MenuLinkContentInterface;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;

/**
 * Taxonomy is optional: bundle and manual sources must work without it.
 *
 * @group menu_autopilot
 */
final class TaxonomyOptionalTest extends KernelTestBase {

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
    'link',
    'menu_link_content',
    'menu_autopilot',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('menu_link_content');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'filter', 'node']);

    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
  }

  /**
   * The parent form does not target taxonomy_term when Taxonomy is absent.
   */
  public function testParentFormOmitsTermWidgetsWithoutTaxonomy(): void {
    $this->assertFalse(
      $this->container->get('entity_type.manager')->hasDefinition('taxonomy_term'),
    );

    $link = MenuLinkContent::create([
      'title' => 'Platforms',
      'menu_name' => 'main',
      'link' => ['uri' => 'route:<nolink>'],
    ]);
    $link->save();

    $form = $this->container->get('entity.form_builder')->getForm($link);
    $this->assertParentFormHasNoTaxonomyUi($form);
    $this->assertSame('none', $form['menu_autopilot']['source_type']['#default_value']);
    $this->assertArrayHasKey('bundle', $form['menu_autopilot']['source_type']['#options']);
    $this->assertArrayHasKey('manual', $form['menu_autopilot']['source_type']['#options']);
    $this->assertArrayNotHasKey('token_help', $form['menu_autopilot']);
  }

  /**
   * Reconcile must not delete managed children of a leftover term parent.
   *
   * When Taxonomy is absent, resolve() returns [] for type=term. That empty
   * list is the same signal as “every published member is gone”, so leftover
   * term parents must be skipped before the reconcile delete path. The stored
   * descriptor stays type=term; the form converts leftover→none on save.
   */
  public function testLeftoverTermReconcileDoesNotWipeManagedChild(): void {
    $node = Node::create([
      'type' => 'page',
      'title' => 'Kept child',
      'status' => 1,
    ]);
    $node->save();

    $parent = MenuLinkContent::create([
      'title' => 'Leftover term parent',
      'menu_name' => 'main',
      'link' => ['uri' => 'route:<nolink>'],
      'menu_autopilot' => [
        'source' => [
          'type' => 'term',
          'term' => 1,
          'reference_field' => 'field_gone',
        ],
      ],
    ]);
    $parent->save();

    $child = MenuLinkContent::create([
      'title' => 'Kept child',
      'menu_name' => 'main',
      'parent' => 'menu_link_content:' . $parent->uuid(),
      'link' => ['uri' => 'entity:node/' . $node->id()],
      'menu_autopilot' => [
        'managed' => TRUE,
        'node' => (int) $node->id(),
      ],
    ]);
    $child->save();
    $child_id = (int) $child->id();

    $this->container->get('menu_autopilot.sync_manager')->reconcile();

    $storage = $this->container->get('entity_type.manager')->getStorage('menu_link_content');
    $storage->resetCache([$child_id, (int) $parent->id()]);
    $kept = $storage->load($child_id);
    $this->assertInstanceOf(MenuLinkContentInterface::class, $kept);
    $this->assertSame('Kept child', $kept->getTitle());
    $data = $kept->get('menu_autopilot')->first()->getValue();
    $this->assertNotEmpty($data['managed']);
    $this->assertSame((int) $node->id(), (int) ($data['node'] ?? 0));

    $reloaded = $storage->load((int) $parent->id());
    $this->assertInstanceOf(MenuLinkContentInterface::class, $reloaded);
    $source = $reloaded->get('menu_autopilot')->first()->getValue()['source'] ?? [];
    $this->assertSame('term', $source['type'] ?? NULL);
  }

  /**
   * A leftover term descriptor does not load taxonomy_term storage.
   */
  public function testLeftoverTermDescriptorDoesNotFatal(): void {
    $link = MenuLinkContent::create([
      'title' => 'Leftover term parent',
      'menu_name' => 'main',
      'link' => ['uri' => 'route:<nolink>'],
      'menu_autopilot' => [
        'source' => [
          'type' => 'term',
          'term' => 1,
          'reference_field' => 'field_gone',
        ],
      ],
    ]);
    $link->save();

    $form = $this->container->get('entity.form_builder')->getForm($link);
    $this->assertParentFormHasNoTaxonomyUi($form);
    $this->assertSame('none', $form['menu_autopilot']['source_type']['#default_value']);
  }

  /**
   * A term source resolves to no nodes when Taxonomy is not installed.
   */
  public function testTermSourceResolvesEmptyWithoutTaxonomy(): void {
    $ids = $this->container->get('menu_autopilot.source_resolver')->resolve([
      'type' => 'term',
      'term' => 1,
      'reference_field' => 'field_solution_type',
    ]);
    $this->assertSame([], $ids);
  }

  /**
   * Bundle sources still generate children without Taxonomy.
   */
  public function testBundleSourceStillSyncsWithoutTaxonomy(): void {
    $parent = MenuLinkContent::create([
      'title' => 'Pages',
      'menu_name' => 'main',
      'link' => ['uri' => 'route:<nolink>'],
      'menu_autopilot' => [
        'source' => [
          'type' => 'bundle',
          'bundle' => 'page',
          'sort' => 'title_asc',
          'limit' => 0,
        ],
      ],
    ]);
    $parent->save();

    $node = Node::create([
      'type' => 'page',
      'title' => 'About',
      'status' => 1,
    ]);
    $node->save();

    $this->container->get('menu_autopilot.sync_manager')->reconcile();

    $storage = $this->container->get('entity_type.manager')->getStorage('menu_link_content');
    $ids = $storage->getQuery()
      ->condition('parent', 'menu_link_content:' . $parent->uuid())
      ->accessCheck(FALSE)
      ->execute();
    $this->assertCount(1, $ids);
    $child = $storage->load(reset($ids));
    $this->assertInstanceOf(MenuLinkContentInterface::class, $child);
    $this->assertSame('About', $child->getTitle());
    $this->assertSame('entity:node/' . $node->id(), $child->get('link')->first()->getValue()['uri']);
  }

  /**
   * Asserts the Autopilot parent form has no taxonomy option or widgets.
   */
  private function assertParentFormHasNoTaxonomyUi(array $form): void {
    $this->assertArrayHasKey('menu_autopilot', $form);
    $this->assertArrayNotHasKey('term', $form['menu_autopilot']['source_type']['#options']);
    $this->assertArrayNotHasKey('term', $form['menu_autopilot']);
    $this->assertArrayNotHasKey('reference_field', $form['menu_autopilot']);
    $this->assertFalse($this->formHasTaxonomyTermTarget($form));
  }

  /**
   * Whether any form element targets the taxonomy_term entity type.
   */
  private function formHasTaxonomyTermTarget(array $element): bool {
    if (($element['#target_type'] ?? NULL) === 'taxonomy_term') {
      return TRUE;
    }
    foreach ($element as $key => $child) {
      if (is_string($key) && $key !== '' && $key[0] !== '#' && is_array($child)) {
        if ($this->formHasTaxonomyTermTarget($child)) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

}
