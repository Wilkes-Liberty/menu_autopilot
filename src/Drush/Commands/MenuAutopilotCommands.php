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

    // A disabled automatic child is a published node no menu shows. The
    // rebuild has just tried to enable the ones it may enable, so whatever is
    // listed here needs an operator.
    $disabled = $this->syncManager->disabledManagedChildren();
    foreach ($disabled as $row) {
      $this->logger()->warning(dt('Disabled automatic child: "@title" (link @link, node @nid) under "@parent" (link @parent_id). @reason', [
        '@title' => $row['title'],
        '@link' => $row['link_id'],
        '@nid' => $row['node'],
        '@parent' => $row['parent_title'],
        '@parent_id' => $row['parent_id'],
        '@reason' => $row['disabled_by_save']
          ? dt('Another module disabled it while Menu Autopilot was saving it, and this run could not enable it. Run the rebuild as an account that may enable menu links.')
          : dt('It was disabled outside Menu Autopilot, so it stays disabled until an editor enables it.'),
      ]));
    }
    if ($disabled !== []) {
      $this->logger()->warning(dt('Menu Autopilot: @count automatic child link(s) are disabled and do not show in the menu.', [
        '@count' => count($disabled),
      ]));
    }
  }

  /**
   * Rewrite editorial node link URIs (e.g. /node/12/latest) to clean ones.
   *
   * Scans the managed menus and rewrites any link that targets an editorial or
   * internal node route to a canonical entity:node/<nid> URI, so it resolves to
   * the node's real path alias instead of 404-ing a decoupled front end. Safe
   * to run repeatedly; already-canonical links are left untouched.
   */
  #[CLI\Command(name: 'menu-autopilot:normalize-uris', aliases: ['ma:fix-uris'])]
  #[CLI\Usage(name: 'drush menu-autopilot:normalize-uris', description: 'Rewrite editorial node link URIs to canonical entity references.')]
  public function normalizeUris(): void {
    $changed = $this->syncManager->normalizeNodeUris();
    if (!$changed) {
      $this->logger()->success(dt('Menu Autopilot: all menu links already use canonical node URIs.'));
      return;
    }
    foreach ($changed as $id => $description) {
      $this->logger()->notice(dt('Link @id: @change', ['@id' => $id, '@change' => $description]));
    }
    $this->logger()->success(dt('Menu Autopilot: normalized @count menu link URI(s).', [
      '@count' => count($changed),
    ]));
  }

}
