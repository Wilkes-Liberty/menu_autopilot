<?php

declare(strict_types=1);

namespace Drupal\menu_autopilot_mcp\Plugin\tool\Tool;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\menu_link_content\MenuLinkContentInterface;
use Drupal\tool\Attribute\Tool;
use Drupal\tool\Tool\ToolOperation;
use Drupal\tool\TypedData\InputDefinition;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Runs the module's URI normalisation for named managed menus.
 */
#[Tool(
  id: 'menu_autopilot_normalize_uris',
  label: new TranslatableMarkup('Normalise menu link URIs'),
  description: new TranslatableMarkup('Rewrite menu links that target an editorial or internal node route, such as /node/12/latest, to the canonical entity:node/12 form, in the named menus. Each name must be one of the managed menus from menu_autopilot_status. Touches every matching link in those menus, managed or not. Safe to repeat: canonical links are left alone. Each change is read back from storage and reported with `applied`. `applied` is false when the save did not reach the live link, for example when a governance rule turned it into a pending revision. `completed` is false when a save was refused part way: `failed_menu` names the menu that stopped, links in it may already be changed, and later menus were not started. Lists at most 100 changes.'),
  operation: ToolOperation::Write,
  input_definitions: [
    'menus' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Menus'),
      description: new TranslatableMarkup('One to ten menu machine names. Each must be a managed menu.'),
      required: TRUE,
      multiple: TRUE,
      // Tool API applies an input's constraints to each item of a multiple
      // input, so this checks every name. A Count constraint would be handed
      // a string and reject every value. run() enforces the list size.
      constraints: ['Regex' => ['pattern' => '/^[a-z0-9_-]{1,32}$/D']],
    ),
  ],
)]
final class NormalizeUrisTool extends MenuAutopilotToolBase {

  /**
   * The most menus one call names.
   */
  private const MAX_MENUS = 10;

  /**
   * The most changes one result lists.
   */
  private const MAX_LISTED = 100;

  /**
   * Entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function extraPermissions(): array {
    return ['normalize menu link uris via mcp'];
  }

  /**
   * {@inheritdoc}
   */
  protected function run(array $values): array {
    $menus = $this->menus($values['menus'] ?? NULL);

    // One menu at a time. Another module can refuse a save with an exception,
    // and the manager has then already saved the links before it. A refusal
    // for the whole call would hide those writes, so report what is known.
    $changed = [];
    $failed_menu = NULL;
    foreach ($menus as $menu) {
      try {
        $changed += $this->syncManager->normalizeNodeUris([$menu]);
      }
      catch (\Throwable $exception) {
        $failed_menu = $menu;
        $this->logger->warning('Menu Autopilot tool @tool stopped in menu @menu with @type at @source:@line. Links saved before that stay changed.', [
          '@tool' => $this->getPluginId(),
          '@menu' => $menu,
          '@type' => get_class($exception),
          '@source' => basename($exception->getFile()),
          '@line' => $exception->getLine(),
        ]);
        break;
      }
    }

    $storage = $this->entityTypeManager->getStorage('menu_link_content');
    $changes = [];
    $applied_total = 0;
    foreach ($changed as $id => $description) {
      $to = $this->canonicalTarget((string) $description);
      // The manager reports what it saved. Read the live link back: another
      // module can turn the save into a pending revision.
      $stored = $storage->loadUnchanged($id);
      $item = $stored instanceof MenuLinkContentInterface ? $stored->get('link')->first() : NULL;
      $applied = $to !== NULL && $item !== NULL && ($item->getValue()['uri'] ?? NULL) === $to;
      $applied_total += (int) $applied;
      if (count($changes) < self::MAX_LISTED) {
        $changes[] = [
          'link_id' => (int) $id,
          'to' => $to,
          'applied' => $applied,
        ];
      }
    }

    $this->logger->info('Normalised @applied of @count menu link URIs through MCP for uid @uid.', [
      '@applied' => $applied_total,
      '@count' => count($changed),
      '@uid' => (int) $this->currentUser->id(),
    ]);
    return [
      'menus' => $menus,
      'completed' => $failed_menu === NULL,
      'failed_menu' => $failed_menu,
      'changed_total' => count($changed),
      'applied_total' => $applied_total,
      'changes' => $changes,
      'changes_truncated' => count($changed) > self::MAX_LISTED,
    ];
  }

  /**
   * Validates the menu names against the managed menus.
   *
   * @return string[]
   *   The validated names, without duplicates.
   *
   * @throws \InvalidArgumentException
   */
  private function menus(mixed $value): array {
    if (!is_array($value) || $value === [] || count($value) > self::MAX_MENUS) {
      throw new \InvalidArgumentException('Invalid menu list.');
    }
    $managed = $this->syncManager->managedMenus();
    $menus = [];
    foreach ($value as $name) {
      if (!is_string($name) || !preg_match('/^[a-z0-9_-]{1,32}$/D', $name) || !in_array($name, $managed, TRUE)) {
        throw new \InvalidArgumentException('Not a managed menu.');
      }
      $menus[$name] = $name;
    }
    return array_values($menus);
  }

  /**
   * The canonical URI from a change description, if it is well formed.
   *
   * The manager describes a change as "old → new". The old URI is whatever
   * an editor typed and can carry a query string, so it is not returned. The
   * new one is always `entity:node/<nid>`.
   */
  private function canonicalTarget(string $description): ?string {
    $position = strrpos($description, ' → ');
    if ($position === FALSE) {
      return NULL;
    }
    $to = substr($description, $position + strlen(' → '));
    return preg_match('#^entity:node/\d+$#D', $to) ? $to : NULL;
  }

}
