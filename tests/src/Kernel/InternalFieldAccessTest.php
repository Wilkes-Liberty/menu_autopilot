<?php

declare(strict_types=1);

namespace Drupal\Tests\menu_autopilot\Kernel;

use Drupal\Core\Access\AccessResultReasonInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\KernelTests\KernelTestBase;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\menu_link_content\MenuLinkContentInterface;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\user\Traits\UserCreationTrait;

/**
 * Field access on the two internal fields, and the module's own writers.
 *
 * Field access forbids every operation on `menu_autopilot` and
 * `menu_autopilot_dynamic`. The module writes both in code, which field access
 * does not govern, so the form, the sync and entity validation must behave as
 * they did before the rule existed.
 *
 * @group menu_autopilot
 */
final class InternalFieldAccessTest extends KernelTestBase {

  use UserCreationTrait;

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
   * The first account created, which bypasses permission checks.
   */
  private AccountInterface $rootUser;

  /**
   * An account that may administer menus and nothing else.
   */
  private AccountInterface $menuAdmin;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('menu_link_content');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'filter', 'node', 'menu_autopilot']);

    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();

    $this->rootUser = $this->createUser();
    $this->menuAdmin = $this->createUser(['administer menu', 'access content']);
    $this->setCurrentUser($this->menuAdmin);
  }

  /**
   * Both operations are forbidden on both fields, for every account.
   */
  public function testEveryOperationIsForbiddenForEveryAccount(): void {
    $link = $this->createLink('Parent');
    $link->set('menu_autopilot', ['source' => ['type' => 'bundle', 'bundle' => 'page']])->save();
    $handler = $this->container->get('entity_type.manager')->getAccessControlHandler('menu_link_content');

    $accounts = [
      'uid 1' => $this->rootUser,
      'menu administrator' => $this->menuAdmin,
      'anonymous' => new AnonymousUserSession(),
    ];
    foreach (['menu_autopilot', 'menu_autopilot_dynamic'] as $field_name) {
      foreach (['view', 'edit'] as $operation) {
        foreach ($accounts as $label => $account) {
          $message = "$operation on $field_name as $label";

          // With the items, which is how an API resource asks.
          $result = $link->get($field_name)->access($operation, $account, TRUE);
          $this->assertTrue($result->isForbidden(), $message);
          $this->assertInstanceOf(AccessResultReasonInterface::class, $result);
          $this->assertStringContainsString('module-owned', (string) $result->getReason(), $message);

          // Without them, which is how a caller asks about the field itself.
          $definition = $link->getFieldDefinition($field_name);
          $this->assertNotNull($definition);
          $this->assertFalse($handler->fieldAccess($operation, $definition, $account), $message . ' (no items)');
        }
      }
    }
  }

  /**
   * The rule does not reach other fields or other entity types.
   */
  public function testOtherFieldsAreLeftAlone(): void {
    $link = $this->createLink('Plain');
    $this->assertTrue($link->get('title')->access('edit', $this->menuAdmin));
    $this->assertTrue($link->get('title')->access('view', $this->menuAdmin));

    $node = Node::create(['type' => 'page', 'title' => 'A page', 'status' => 1]);
    $node->save();
    $this->assertTrue($node->get('title')->access('view', $this->menuAdmin));
  }

  /**
   * The menu link form still shows the module's section and saves through it.
   */
  public function testTheLinkFormStillWritesTheDescriptor(): void {
    $page = Node::create(['type' => 'page', 'title' => 'About', 'status' => 1]);
    $page->save();
    $link = $this->createLink('Parent');

    $form = $this->container->get('entity.form_builder')->getForm($link);
    $this->assertSame('details', $form['menu_autopilot']['#type']);
    $this->assertNotFalse($form['menu_autopilot']['#access'] ?? TRUE, 'The Menu Autopilot section is not access-denied.');
    $this->assertSame('select', $form['menu_autopilot']['source_type']['#type']);
    $this->assertArrayNotHasKey('menu_autopilot_dynamic', $form, 'The marker has no form element.');

    $form_object = $this->container->get('entity_type.manager')
      ->getFormObject('menu_link_content', 'default')
      ->setEntity($this->reload($link));
    $form_state = (new FormState())->setValues([
      'title' => [['value' => 'Parent']],
      'link' => [['uri' => '/']],
      'menu_parent' => 'main:',
      'enabled' => ['value' => 1],
      'menu_autopilot' => [
        'source_type' => 'bundle',
        'bundle' => 'page',
        'existing_children' => 'adopt',
        'sort' => 'title_asc',
        'limit' => 0,
        'title_pattern' => '',
      ],
      'op' => 'Save',
    ]);
    $this->container->get('form_builder')->submitForm($form_object, $form_state);
    $this->assertSame([], $form_state->getErrors());

    $saved = $this->reload($link);
    $data = _menu_autopilot_link_data($saved);
    $this->assertSame('bundle', $data['source']['type'] ?? NULL, 'The form wrote the descriptor.');
    $this->assertSame('page', $data['source']['bundle'] ?? NULL);
    $this->assertTrue((bool) $saved->get('menu_autopilot_dynamic')->value, 'Presave still sets the marker.');

    $children = $this->childrenOf($saved);
    $this->assertCount(1, $children, 'Saving the form synced the children.');
    $this->assertTrue(!empty(_menu_autopilot_link_data(reset($children))['managed']), 'The sync wrote the managed map on the child it created.');
  }

  /**
   * The sync creates, adopts and updates children for a limited account.
   */
  public function testTheSyncStillWritesForLimitedAccount(): void {
    $adopted_page = Node::create(['type' => 'page', 'title' => 'Adopted', 'status' => 1]);
    $adopted_page->save();

    $parent = $this->createLink('Parent');
    $hand_made = MenuLinkContent::create([
      'title' => 'Adopted (hand)',
      'menu_name' => 'main',
      'parent' => 'menu_link_content:' . $parent->uuid(),
      'link' => ['uri' => 'entity:node/' . $adopted_page->id()],
    ]);
    $hand_made->save();

    $parent->set('menu_autopilot', [
      'source' => [
        'type' => 'bundle',
        'bundle' => 'page',
        'existing_children' => 'adopt',
        'sort' => 'title_asc',
        'limit' => 0,
      ],
    ])->save();

    $children = $this->childrenOf($parent);
    $this->assertCount(1, $children);
    $this->assertArrayHasKey((int) $hand_made->id(), $children, 'The hand-made link is adopted, not duplicated.');
    $this->assertTrue(!empty(_menu_autopilot_link_data($children[(int) $hand_made->id()])['managed']));

    $created_page = Node::create(['type' => 'page', 'title' => 'Created', 'status' => 1]);
    $created_page->save();
    $this->assertCount(2, $this->childrenOf($parent), 'Publishing a node creates a managed child.');

    $created_page->setTitle('Created, renamed')->save();
    $titles = array_map(static fn (MenuLinkContentInterface $child): string => (string) $child->getTitle(), $this->childrenOf($parent));
    $this->assertContains('Created, renamed', $titles, 'A rename updates the managed child.');
  }

  /**
   * Entity validation passes on links that carry the fields.
   */
  public function testValidationIsUnaffected(): void {
    $parent = $this->createLink('Parent');
    $parent->set('menu_autopilot', ['source' => ['type' => 'bundle', 'bundle' => 'page']])->save();
    Node::create(['type' => 'page', 'title' => 'About', 'status' => 1])->save();

    $links = [$this->reload($parent)] + $this->childrenOf($parent);
    $this->assertCount(2, $links);
    foreach ($links as $link) {
      $violations = $link->validate();
      $this->assertCount(0, $violations, (string) $link->getTitle());
      // What an entity form does before it reports violations.
      $this->assertCount(0, $violations->filterByFieldAccess($this->menuAdmin), (string) $link->getTitle());
    }
  }

  /**
   * Creates and saves a top-level link in the main menu.
   *
   * @param string $title
   *   The link title.
   *
   * @return \Drupal\menu_link_content\MenuLinkContentInterface
   *   The saved link.
   */
  private function createLink(string $title): MenuLinkContentInterface {
    $link = MenuLinkContent::create([
      'title' => $title,
      'menu_name' => 'main',
      'link' => ['uri' => 'route:<nolink>'],
    ]);
    $link->save();
    return $link;
  }

  /**
   * Loads a link fresh from storage.
   *
   * @param \Drupal\menu_link_content\MenuLinkContentInterface $link
   *   The link to reload.
   *
   * @return \Drupal\menu_link_content\MenuLinkContentInterface
   *   The stored link.
   */
  private function reload(MenuLinkContentInterface $link): MenuLinkContentInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('menu_link_content');
    $storage->resetCache([$link->id()]);
    $reloaded = $storage->load($link->id());
    $this->assertInstanceOf(MenuLinkContentInterface::class, $reloaded);
    return $reloaded;
  }

  /**
   * The stored children of a parent link, keyed by id.
   *
   * @param \Drupal\menu_link_content\MenuLinkContentInterface $parent
   *   The parent link.
   *
   * @return \Drupal\menu_link_content\MenuLinkContentInterface[]
   *   The children.
   */
  private function childrenOf(MenuLinkContentInterface $parent): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('menu_link_content');
    $storage->resetCache();
    $children = [];
    foreach ($storage->loadByProperties(['parent' => 'menu_link_content:' . $parent->uuid()]) as $child) {
      $children[(int) $child->id()] = $child;
    }
    return $children;
  }

}
