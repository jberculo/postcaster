<?php
/**
 * @license GPL-3.0-or-later
 *
 * Modified by justbee on 08-May-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */


namespace Justbee\PostCaster\Vendor\Action_Scheduler\Migration;

/**
 * Class DryRun_ActionMigrator
 *
 * @package Action_Scheduler\Migration
 *
 * @since 3.0.0
 *
 * @codeCoverageIgnore
 */
class DryRun_ActionMigrator extends ActionMigrator {
	/**
	 * Simulate migrating an action.
	 *
	 * @param int $source_action_id Action ID.
	 *
	 * @return int
	 */
	public function migrate( $source_action_id ) {
		do_action( 'action_scheduler/migrate_action_dry_run', $source_action_id ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores

		return 0;
	}
}
