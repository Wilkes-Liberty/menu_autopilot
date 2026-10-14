<?php

declare(strict_types=1);

namespace Drupal\menu_autopilot_mcp\Plugin\tool\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\menu_autopilot\NavStatusReporter;
use Drupal\tool\Attribute\Tool;
use Drupal\tool\Tool\ToolOperation;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Reports the dynamic parents and what sits under each one.
 */
#[Tool(
  id: 'menu_autopilot_status',
  label: new TranslatableMarkup('Menu Autopilot status'),
  description: new TranslatableMarkup('List the dynamic parent links in the managed menus. For each: title, UUID, menu, source type, existing-children policy, and counts of owned, adoptable, extra, disabled children and stale translations. Disabled automatic children are listed by title and node id, and marked when a sync save, not an editor, left them disabled. Translations of automatic children are listed by node id, language code and title, and marked stale when the node has no translation in that language. At most 50 parents, 25 disabled children and 25 translations per parent. Reports what is there now; it does not predict the next sync. Never returns a label pattern or a node field value.'),
  operation: ToolOperation::Read,
  input_definitions: [],
)]
final class StatusTool extends MenuAutopilotToolBase {

  /**
   * The most parents one call describes.
   */
  private const MAX_PARENTS = 50;

  /**
   * The most disabled children, and the most translations, listed per parent.
   */
  private const MAX_LISTED = 25;

  /**
   * Read-only parent status report.
   */
  protected NavStatusReporter $statusReporter;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->statusReporter = $container->get('menu_autopilot.status_reporter');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function run(array $values): array {
    return $this->statusReporter->parentStatus(self::MAX_PARENTS, self::MAX_LISTED);
  }

}
