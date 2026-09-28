<?php

declare(strict_types=1);

namespace Drupal\Tests\menu_autopilot\Kernel;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\menu_autopilot\Form\MenuAutopilotSettingsForm;
use Drupal\menu_link_content\Entity\MenuLinkContent;

/**
 * Operator copy for managed menus must name the real sync split.
 *
 * The Menu Autopilot section is offered on any non-owned link. Parent save
 * syncs that link. Node changes and `drush menu-autopilot:rebuild` do not,
 * until the menu is managed. Settings, help, and the unmanaged-menu
 * parent-form warning must say that; they must not claim the section is
 * managed-only or that unmanaged children "will not sync".
 *
 * @group menu_autopilot
 */
final class ManagedMenusOperatorCopyTest extends KernelTestBase {

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
    $this->installEntitySchema('menu_link_content');
    $this->installConfig(['system', 'menu_autopilot']);
    $this->config('menu_autopilot.settings')->set('managed_menus', ['main'])->save();
  }

  /**
   * Settings must not say the section appears only on managed menus.
   */
  public function testSettingsDescriptionNamesTheSyncSplit(): void {
    $form = $this->container->get('form_builder')->getForm(MenuAutopilotSettingsForm::class);
    $description = (string) $form['managed_menus']['#description'];

    $this->assertStringNotContainsString(
      'Only links in these menus show the Menu Autopilot section',
      $description,
      'Settings must not say the section appears only on managed menus.',
    );
    $this->assertStringContainsString(
      'The Menu Autopilot section is offered on any link that is not an automatic child.',
      $description,
    );
    $this->assertStringContainsString(
      'Saving that parent syncs its children even if its menu is not listed here.',
      $description,
    );
    $this->assertStringContainsString(
      'when nodes change or when the rebuild command runs',
      $description,
    );
  }

  /**
   * An unmanaged-menu parent form must name save vs node-change/rebuild.
   */
  public function testUnmanagedMenuParentFormDescriptionNamesTheSyncSplit(): void {
    $link = MenuLinkContent::create([
      'title' => 'Footer platforms',
      'menu_name' => 'footer',
      'link' => ['uri' => 'route:<nolink>'],
    ]);
    $link->save();

    $this->assertNotContains(
      'footer',
      $this->config('menu_autopilot.settings')->get('managed_menus') ?? [],
    );

    $form = $this->container->get('entity.form_builder')->getForm($link);
    $this->assertSame('details', $form['menu_autopilot']['#type']);
    $this->assertNotFalse($form['menu_autopilot']['#access'] ?? TRUE, 'The section is offered on a non-owned unmanaged-menu link.');

    $description = (string) $form['menu_autopilot']['#description'];
    $this->assertStringNotContainsString(
      'will not sync',
      $description,
      'The unmanaged-menu warning must not say children will not sync.',
    );
    $this->assertStringContainsString('not a managed menu', $description);
    $this->assertStringContainsString('Saving this link syncs its children.', $description);
    $this->assertStringContainsString(
      'Node changes and the rebuild command do not',
      $description,
    );
  }

  /**
   * Help must not say the section appears only on managed menus.
   */
  public function testHelpDoesNotSaySectionIsManagedMenuOnly(): void {
    $help = (string) $this->container->get('module_handler')->invoke(
      'menu_autopilot',
      'help',
      [
        'help.page.menu_autopilot',
        $this->createMock(RouteMatchInterface::class),
      ],
    );

    $this->assertStringNotContainsString(
      'in a managed menu',
      $help,
      'Help must not say the section appears only on managed menus.',
    );
    $this->assertStringContainsString(
      'Edit any link that is not an automatic child',
      $help,
    );
  }

}
