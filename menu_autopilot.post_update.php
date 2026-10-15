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

/**
 * Rebuild the container: the sync manager constructor changed in 1.4.9.
 *
 * 1.4.9 added NavStatusReporter as a constructor argument with no post-update,
 * so an updb-only site TypeErrors. parentStatus() moved to that reporter. The
 * manager also no longer takes config.factory. Running any update rebuilds the
 * container.
 */
function menu_autopilot_post_update_status_reporter(): void {
  // Empty on purpose. Running any update rebuilds the container, which is all
  // this needs.
}
