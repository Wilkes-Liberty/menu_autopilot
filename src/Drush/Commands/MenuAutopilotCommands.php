<?php

declare(strict_types=1);

namespace Drupal\menu_autopilot\Drush\Commands;

use Drupal\menu_autopilot\NavSyncManager;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush commands for Menu Autopilot.
 */
final class MenuAutopilotCommands extends DrushCommands {

  public function __construct(
    private readonly NavSyncManager $syncManager,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('menu_autopilot.sync_manager'),
    );
  }

  /**
   * Reconcile every dynamic parent: create, reorder, rename, and prune links.
   *
   * Safe to run at any time — it is idempotent, so a second run in a row makes
   * no changes. Useful after a bulk import, after changing a parent's source,
   * or on a schedule as a self-healing pass.
   */
  #[CLI\Command(name: 'menu-autopilot:rebuild', aliases: ['ma:rebuild'])]
  #[CLI\Usage(name: 'drush menu-autopilot:rebuild', description: 'Rebuild all automatic menu children from published content.')]
  public function rebuild(): void {
    $this->syncManager->reconcile();
    $this->logger()->success(dt('Menu Autopilot: rebuilt automatic children for menus @menus.', [
      '@menus' => implode(', ', $this->syncManager->managedMenus()),
    ]));
  }

}
