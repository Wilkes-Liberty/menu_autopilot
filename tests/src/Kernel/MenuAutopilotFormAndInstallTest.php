<?php

declare(strict_types=1);

namespace Drupal\Tests\menu_autopilot\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\menu_link_content\MenuLinkContentInterface;
use Drupal\node\Entity\Node;

/**
 * Covers node-form alter, install backfill, and operator-copy helpers.
 *
 * These cases call hook and .module helpers (after-build, existing-children
 * copy, the 10201 marker backfill). They are not the sync engine.
 *
 * @group menu_autopilot
 */
final class MenuAutopilotFormAndInstallTest extends KernelTestBase {

  use MenuAutopilotKernelFixtureTrait;

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
   * Every Existing children policy has an operator-facing explanation.
   */
  public function testExistingChildrenHelpCoversEveryPolicy(): void {
    $help = _menu_autopilot_existing_children_help();
    $this->assertSame(
      ['adopt', 'adopt_prune', 'add', 'replace'],
      array_keys($help),
    );
    foreach ($help as $text) {
      $this->assertNotSame('', trim(strip_tags((string) $text)));
    }
    $this->assertSame(
      ['title_asc', 'title_desc', 'created_desc', 'created_asc', 'preserve'],
      array_keys(_menu_autopilot_sort_options()),
    );
    $named = MenuLinkContent::create([
      'title' => 'Platforms',
      'menu_name' => 'main',
      'link' => ['uri' => 'route:<nolink>'],
    ]);
    $this->assertSame(
      'Menu Autopilot: children of Platforms',
      (string) _menu_autopilot_parent_settings_title($named),
    );
    $untitled = MenuLinkContent::create([
      'title' => '',
      'menu_name' => 'main',
      'link' => ['uri' => 'route:<nolink>'],
    ]);
    $this->assertSame(
      'Menu Autopilot: children of this item',
      (string) _menu_autopilot_parent_settings_title($untitled),
    );
  }

  /**
   * Node-form after-build hides a managed child and flushes after menu_ui.
   *
   * The form alter runs before menu_ui, so this work cannot happen there.
   */
  public function testNodeFormAfterBuildHidesManagedMenuAndFlushesLast(): void {
    $parent = $this->createDynamicParent();
    $this->createSolution('Atlas', TRUE);
    $children = $this->childrenOf($parent);
    $child = reset($children);
    $this->assertInstanceOf(MenuLinkContentInterface::class, $child);

    $menu_ui_submit = 'Drupal\menu_ui\Hook\MenuUiHooks:formNodeFormSubmit';
    $form = [
      'actions' => [
        'submit' => [
          '#type' => 'submit',
          '#submit' => [$menu_ui_submit],
        ],
        'preview' => [
          '#type' => 'submit',
          '#submit' => ['::submitForm'],
        ],
      ],
      'menu' => [
        'link' => [
          'entity_id' => ['#value' => $child->id()],
        ],
      ],
    ];
    $form = _menu_autopilot_node_form_after_build($form, new FormState());

    $this->assertFalse($form['menu']['#access']);
    $this->assertSame(
      ['_menu_autopilot_flush_queued_node_sync'],
      $form['actions']['submit']['#submit'],
    );
    $this->assertNotContains($menu_ui_submit, $form['actions']['submit']['#submit']);
    $this->assertSame(['::submitForm'], $form['actions']['preview']['#submit']);
    $this->assertSame('Menu link', (string) $form['menu_autopilot_owned']['#title']);
    $this->assertStringContainsString('Menu Autopilot', (string) $form['menu_autopilot_owned']['#markup']);
  }

  /**
   * The owned-child notice must be a processed element (#parents).
   *
   * FormErrorHandler calls FormState::getError() on every child. An
   * after-build item without #parents warns whenever any other field fails
   * validation (the editor sees only the PHP warning; the real error is
   * often on the hidden menu widget).
   */
  public function testOwnedNoticeHasParentsSoFormErrorsDoNotWarn(): void {
    $parent = $this->createDynamicParent();
    $this->createSolution('Atlas', TRUE);
    $children = $this->childrenOf($parent);
    $child = reset($children);
    $this->assertInstanceOf(MenuLinkContentInterface::class, $child);

    $form = [
      'actions' => [
        'submit' => [
          '#type' => 'submit',
          '#submit' => ['Drupal\menu_ui\Hook\MenuUiHooks:formNodeFormSubmit'],
        ],
      ],
      'menu' => [
        'link' => [
          'entity_id' => ['#value' => $child->id()],
        ],
      ],
    ];
    $form_state = new FormState();
    $form = _menu_autopilot_node_form_after_build($form, $form_state);

    $this->assertSame(['menu_autopilot_owned'], $form['menu_autopilot_owned']['#parents']);
    $this->assertSame(['menu_autopilot_owned'], $form['menu_autopilot_owned']['#array_parents']);

    // FormErrorHandler calls getError() on every child. The production
    // warning was this call on the unprocessed notice while another field
    // had an error. Do not walk the stub form tree (its menu children are
    // also unprocessed).
    $form_state->setErrorByName('title', 'Required.');
    $warnings = [];
    set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
      $warnings[] = $message;
      return TRUE;
    });
    $form_state->getError($form['menu_autopilot_owned']);
    restore_error_handler();

    foreach ($warnings as $warning) {
      $this->assertStringNotContainsString('#parents', $warning);
    }
  }

  /**
   * Hiding the widget must not look like removing the menu link.
   *
   * Menu UI processes input before #after_build. An unrendered checkbox is
   * enabled=0, and a pending revision then fails with "You can only remove
   * the menu link in the published version of this content."
   */
  public function testHidingManagedMenuDoesNotLookLikeRemoval(): void {
    $parent = $this->createDynamicParent();
    $node = $this->createSolution('Atlas', TRUE);
    $children = $this->childrenOf($parent);
    $child = reset($children);
    $this->assertInstanceOf(MenuLinkContentInterface::class, $child);

    $menu_ui_submit = 'Drupal\menu_ui\Hook\MenuUiHooks:formNodeFormSubmit';
    $form_state = new FormState();
    $form_state->setValue('menu', [
      'enabled' => 0,
      'entity_id' => $child->id(),
      'title' => '',
      'description' => '',
      'menu_parent' => 'main:',
      'weight' => 0,
    ]);
    $form = [
      'actions' => [
        'submit' => [
          '#type' => 'submit',
          '#submit' => [$menu_ui_submit],
        ],
      ],
      'menu' => [
        'link' => [
          'entity_id' => ['#value' => $child->id()],
        ],
      ],
    ];
    $form = _menu_autopilot_node_form_after_build($form, $form_state);

    $this->assertFalse($form['menu']['#access']);
    $this->assertSame(1, (int) $form_state->getValue(['menu', 'enabled']));
    $this->assertSame((string) $child->id(), (string) $form_state->getValue(['menu', 'entity_id']));
    $this->assertSame($child->getTitle(), $form_state->getValue(['menu', 'title']));
    $this->assertSame((int) $child->getWeight(), (int) $form_state->getValue(['menu', 'weight']));
    $this->assertSame(
      $child->getMenuName() . ':' . $child->getParentId(),
      $form_state->getValue(['menu', 'menu_parent']),
    );
    $this->assertNotContains($menu_ui_submit, $form['actions']['submit']['#submit']);
    $this->assertContains('_menu_autopilot_flush_queued_node_sync', $form['actions']['submit']['#submit']);

    $this->enableModules(['menu_ui']);
    $node->setNewRevision();
    $node->isDefaultRevision(FALSE);
    $node->menu = $form_state->getValue('menu');
    $messages = [];
    foreach ($node->validate() as $violation) {
      $messages[] = (string) $violation->getMessage();
    }
    $this->assertNotContains(
      'You can only remove the menu link in the published version of this content.',
      $messages,
    );
    foreach ($messages as $message) {
      $this->assertStringNotContainsString('menu', strtolower($message));
    }
  }

  /**
   * The marker backfill does not save parents (which would reconcile).
   */
  public function testUpdate10201DoesNotReconcileParents(): void {
    $parent = $this->createDynamicParent();
    $this->createSolution('Atlas', TRUE);

    $stale_node = Node::create([
      'type' => 'solution',
      'title' => 'Stale',
      'status' => 0,
    ]);
    $stale_node->save();
    $stale = MenuLinkContent::create([
      'title' => 'Stale',
      'menu_name' => 'main',
      'parent' => 'menu_link_content:' . $parent->uuid(),
      'link' => ['uri' => 'entity:node/' . $stale_node->id()],
      'menu_autopilot' => ['managed' => TRUE, 'node' => (int) $stale_node->id()],
    ]);
    $stale->save();
    $this->assertCount(2, $this->childrenOf($parent));

    $this->container->get('module_handler')->loadInclude('menu_autopilot', 'install');
    menu_autopilot_update_10201();

    $this->assertCount(2, $this->childrenOf($parent), 'A marker backfill must not sync dynamic parents.');
    $this->assertTrue((bool) $this->reloadLink((int) $parent->id())->get('menu_autopilot_dynamic')->value);
  }

  /**
   * The dynamic-parent marker is queryable and skips managed children.
   */
  public function testDynamicParentMarkerIsQueryable(): void {
    $parent = $this->createDynamicParent();
    $this->createSolution('Atlas', TRUE);
    $children = $this->childrenOf($parent);
    $child = reset($children);
    $this->assertInstanceOf(MenuLinkContentInterface::class, $child);
    $this->assertFalse((bool) $child->get('menu_autopilot_dynamic')->value);

    $storage = $this->container->get('entity_type.manager')->getStorage('menu_link_content');
    $ids = $storage->getQuery()
      ->condition('menu_name', 'main')
      ->condition('menu_autopilot_dynamic', TRUE)
      ->accessCheck(FALSE)
      ->execute();
    $this->assertSame(
      [(int) $parent->id()],
      array_values(array_map('intval', $ids)),
    );
  }

}
