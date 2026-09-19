<?php

/**
 * @file
 * Post update functions for Menu Autopilot.
 */

/**
 * Rebuild the container: the sync manager takes two new service arguments.
 */
function menu_autopilot_post_update_sync_manager_logger_and_account(): void {
  // Empty on purpose. Running any update rebuilds the container, which is all
  // this needs. The `disabled_by_save` flag lives in the existing
  // `menu_autopilot` map field, so there is no schema or data change.
}
