<?php

declare(strict_types=1);

namespace Drupal\Tests\menu_autopilot\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\content_translation\Traits\ContentTranslationTestTrait;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\menu_link_content\MenuLinkContentInterface;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;

/**
 * Automatic child labels follow each translation of the source node.
 *
 * The menu link plugin reads the title through the entity repository for
 * the current language. These tests assert that lookup, not a private
 * helper.
 *
 * @group menu_autopilot
 * @coversDefaultClass \Drupal\menu_autopilot\NavSyncManager
 */
final class TranslatedChildLabelTest extends KernelTestBase {

  use ContentTranslationTestTrait;

  /**
   * Token pattern stored on the dynamic parent.
   */
  private const PATTERN = '[node:title] / [node:langcode]';

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
    'language',
    'content_translation',
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
    $this->installEntitySchema('configurable_language');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'filter', 'node', 'language']);

    ConfigurableLanguage::createFromLangcode('fr')->save();
    ConfigurableLanguage::createFromLangcode('de')->save();
    ConfigurableLanguage::createFromLangcode('es')->save();
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();

    // Menu links are translatable only after content translation is enabled
    // for the bundle. That is the site setup the bug report describes.
    $this->enableContentTranslation('node', 'page');
    $this->enableContentTranslation('menu_link_content', 'menu_link_content');
    $this->container->get('entity_type.bundle.info')->clearCachedBundles();
  }

  /**
   * Create, update, and remove link translations as the node changes.
   *
   * An editor-disabled link stays disabled while its translated title is
   * updated. A link translation the node does not have is reported stale,
   * then removed on the next sync. Saving only the French translation does
   * not replace the English title with the French one.
   *
   * @covers ::syncNode
   * @covers ::parentStatus
   */
  public function testSyncWritesAndRemovesLinkTranslations(): void {
    $parent = $this->createParent();
    $node = Node::create([
      'type' => 'page',
      'title' => 'Helios',
      'status' => 1,
    ]);
    $node->addTranslation('fr', ['title' => 'Helios FR']);
    $node->save();

    $link = $this->onlyChild($parent);
    $this->assertSame('en', $link->language()->getId());
    $this->assertSame('Helios / en', $link->getTitle());
    $this->assertSame('Helios FR / fr', $link->getTranslation('fr')->getTitle());
    $this->assertSame(
      'entity:node/' . $node->id(),
      $link->getTranslation('fr')->get('link')->first()->getValue()['uri'],
    );
    $french = $this->container->get('entity.repository')->getTranslationFromContext($link, 'fr');
    $this->assertInstanceOf(MenuLinkContentInterface::class, $french);
    $this->assertSame('Helios FR / fr', $french->getTitle());

    $node->setTitle('Helios 2');
    $node->getTranslation('fr')->setTitle('Helios FR 2');
    $node->getTranslation('fr')->save();
    $link = $this->reloadLink((int) $link->id());
    $this->assertSame('Helios 2 / en', $link->getTitle());
    $this->assertSame('Helios FR 2 / fr', $link->getTranslation('fr')->getTitle());

    $link->set('enabled', FALSE)->save();
    $node = $this->reloadNode((int) $node->id());
    $node->getTranslation('fr')->setTitle('Helios FR disabled')->save();
    $link = $this->reloadLink((int) $link->id());
    $this->assertFalse($link->isEnabled());
    $this->assertSame('Helios 2 / en', $link->getTitle());
    $this->assertSame('Helios FR disabled / fr', $link->getTranslation('fr')->getTitle());

    $link->set('enabled', TRUE);
    $link->addTranslation('es', ['title' => 'Viejo']);
    $link->save();

    /** @var \Drupal\menu_autopilot\NavSyncManager $sync */
    $sync = $this->container->get('menu_autopilot.sync_manager');
    $row = $this->statusRow($sync->parentStatus(), $parent);
    $this->assertSame(1, $row['counts']['stale_translations']);
    $this->assertSame([
      [
        'node' => (int) $node->id(),
        'langcode' => 'es',
        'title' => 'Viejo',
        'stale' => TRUE,
      ],
      [
        'node' => (int) $node->id(),
        'langcode' => 'fr',
        'title' => 'Helios FR disabled / fr',
        'stale' => FALSE,
      ],
    ], $row['translations']);
    $cut = $this->statusRow($sync->parentStatus(50, 0), $parent);
    $this->assertSame([], $cut['translations']);
    $this->assertTrue($cut['translations_truncated']);
    $this->assertSame(1, $cut['counts']['stale_translations']);

    $node = $this->reloadNode((int) $node->id());
    $node->save();
    $link = $this->reloadLink((int) $link->id());
    $this->assertFalse($link->hasTranslation('es'));
    $this->assertTrue($link->hasTranslation('fr'));
    $this->assertSame(0, $this->statusRow($sync->parentStatus(), $parent)['counts']['stale_translations']);

    $node = $this->reloadNode((int) $node->id());
    $node->removeTranslation('fr');
    $node->save();
    $link = $this->reloadLink((int) $link->id());
    $this->assertFalse($link->hasTranslation('fr'));
    $this->assertSame('Helios 2 / en', $link->getTitle());
    $this->assertSame([], $this->statusRow($sync->parentStatus(), $parent)['translations']);
  }

  /**
   * A French node gets a French link.
   *
   * @covers ::syncNode
   */
  public function testNewLinkUsesTheNodeDefaultLanguage(): void {
    $parent = $this->createParent();
    $node = Node::create([
      'type' => 'page',
      'title' => 'Helios FR',
      'langcode' => 'fr',
      'status' => 1,
    ]);
    $node->save();

    $link = $this->onlyChild($parent);
    $this->assertSame('fr', $link->language()->getId());
    $this->assertSame('Helios FR / fr', $link->getTitle());
    $this->assertSame(['fr'], array_keys($link->getTranslationLanguages()));
    $this->assertSame((int) $node->id(), $this->nodeId($link));
  }

  /**
   * Adopting a link leaves its language in place and fills in the node's.
   *
   * The German link has no German node translation, so its own title falls
   * back to the node's default language. English and French are added
   * because the node has them.
   *
   * @covers ::syncNode
   */
  public function testAdoptedLinkKeepsItsLanguage(): void {
    $parent = $this->createParent();
    $node = Node::create([
      'type' => 'page',
      'title' => 'Helios',
    ]);
    $node->setUnpublished();
    $node->save();
    $this->assertCount(0, $this->childrenOf($parent));

    $hand = MenuLinkContent::create([
      'title' => 'Old',
      'langcode' => 'de',
      'menu_name' => 'main',
      'parent' => 'menu_link_content:' . $parent->uuid(),
      'link' => ['uri' => 'entity:node/' . $node->id()],
    ]);
    $hand->save();
    $hand_id = (int) $hand->id();

    $node = $this->reloadNode((int) $node->id());
    $node->addTranslation('fr', ['title' => 'Helios FR']);
    $node->setPublished();
    $node->save();
    $link = $this->onlyChild($parent);
    $this->assertSame($hand_id, (int) $link->id());
    $this->assertSame('de', $link->language()->getId());
    $this->assertTrue($this->isManaged($link));
    $this->assertSame('Helios / en', $link->getTitle());
    $this->assertSame('Helios / en', $link->getTranslation('en')->getTitle());
    $this->assertSame('Helios FR / fr', $link->getTranslation('fr')->getTitle());
    $this->assertSame(
      'entity:node/' . $node->id(),
      $link->get('link')->first()->getValue()['uri'],
    );
  }

  /**
   * A dynamic parent of every published page, with the token pattern.
   */
  private function createParent(): MenuLinkContentInterface {
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
          'title_pattern' => self::PATTERN,
        ],
      ],
    ]);
    $parent->save();
    return $parent;
  }

  /**
   * The one child under a parent.
   */
  private function onlyChild(MenuLinkContentInterface $parent): MenuLinkContentInterface {
    $children = $this->childrenOf($parent);
    $this->assertCount(1, $children);
    $link = reset($children);
    $this->assertInstanceOf(MenuLinkContentInterface::class, $link);
    return $link;
  }

  /**
   * Child links under a parent, keyed by entity id.
   *
   * @return \Drupal\menu_link_content\MenuLinkContentInterface[]
   *   The children.
   */
  private function childrenOf(MenuLinkContentInterface $parent): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('menu_link_content');
    $ids = $storage->getQuery()
      ->condition('parent', 'menu_link_content:' . $parent->uuid())
      ->accessCheck(FALSE)
      ->execute();
    return $storage->loadMultiple($ids);
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
   * Reloads a node from storage.
   */
  private function reloadNode(int $id): NodeInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache([$id]);
    $node = $storage->load($id);
    $this->assertInstanceOf(NodeInterface::class, $node);
    return $node;
  }

  /**
   * The status row for one parent.
   */
  private function statusRow(array $report, MenuLinkContentInterface $parent): array {
    $rows = array_column($report['parents'], NULL, 'uuid');
    $this->assertArrayHasKey($parent->uuid(), $rows);
    return $rows[$parent->uuid()];
  }

  /**
   * Whether the link is an automatic child.
   */
  private function isManaged(MenuLinkContentInterface $link): bool {
    if ($link->get('menu_autopilot')->isEmpty()) {
      return FALSE;
    }
    return !empty($link->get('menu_autopilot')->first()->getValue()['managed']);
  }

  /**
   * The node id stored on a managed link.
   */
  private function nodeId(MenuLinkContentInterface $link): int {
    return (int) ($link->get('menu_autopilot')->first()->getValue()['node'] ?? 0);
  }

}
