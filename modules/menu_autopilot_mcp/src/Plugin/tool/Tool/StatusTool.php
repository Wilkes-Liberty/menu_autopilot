<?php

declare(strict_types=1);

namespace Drupal\menu_autopilot_mcp\Plugin\tool\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\tool\Attribute\Tool;
use Drupal\tool\Tool\ToolOperation;

/**
 * Reports the dynamic parents and what sits under each one.
 */
#[Tool(
  id: 'menu_autopilot_status',
  label: new TranslatableMarkup('Menu Autopilot status'),
  description: new TranslatableMarkup('List the dynamic parent links in the managed menus. For each: title, UUID, menu, source type, existing-children policy, and counts of owned, adoptable, extra and disabled children. Disabled automatic children are listed by title and node id, and marked when a sync save, not an editor, left them disabled. At most 50 parents and 25 disabled children per parent. Reports what is there now; it does not predict the next sync. Never returns a label pattern or a node field value.'),
  operation: ToolOperation::Read,
  input_definitions: [],
)]
final class StatusTool extends MenuAutopilotToolBase {

  /**
   * The most parents one call describes.
   */
  private const MAX_PARENTS = 50;

  /**
   * The most disabled children listed under one parent.
   */
  private const MAX_LISTED = 25;

  /**
   * {@inheritdoc}
   */
  protected function run(array $values): array {
    return $this->syncManager->parentStatus(self::MAX_PARENTS, self::MAX_LISTED);
  }

}
