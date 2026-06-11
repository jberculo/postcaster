<?php

/**
 * Class Justbee_PostCaster_ActionScheduler_Store_Deprecated
 *
 * @codeCoverageIgnore
 *
 * @license GPL-3.0-or-later
 * Modified by justbee on 08-May-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */
abstract class Justbee_PostCaster_ActionScheduler_Store_Deprecated {

	/**
	 * Mark an action that failed to fetch correctly as failed.
	 *
	 * @since 2.2.6
	 *
	 * @param int $action_id The ID of the action.
	 */
	public function mark_failed_fetch_action( $action_id ) {
		_deprecated_function( __METHOD__, '3.0.0', 'Justbee_PostCaster_ActionScheduler_Store::mark_failure()' );
		self::$store->mark_failure( $action_id );
	}

	/**
	 * Add base hooks
	 *
	 * @since 2.2.6
	 */
	protected static function hook() {
		_deprecated_function( __METHOD__, '3.0.0' );
	}

	/**
	 * Remove base hooks
	 *
	 * @since 2.2.6
	 */
	protected static function unhook() {
		_deprecated_function( __METHOD__, '3.0.0' );
	}

	/**
	 * Get the site's local time.
	 *
	 * @deprecated 2.1.0
	 * @return DateTimeZone
	 */
	protected function get_local_timezone() {
		_deprecated_function( __FUNCTION__, '2.1.0', 'Justbee_PostCaster_ActionScheduler_TimezoneHelper::set_local_timezone()' );
		return Justbee_PostCaster_ActionScheduler_TimezoneHelper::get_local_timezone();
	}
}
