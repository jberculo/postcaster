<?php

/**
 * Class Justbee_PostCaster_ActionScheduler_Abstract_Schedule
 *
 * @license GPL-3.0-or-later
 * Modified by justbee on 08-May-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */
abstract class Justbee_PostCaster_ActionScheduler_Schedule_Deprecated implements Justbee_PostCaster_ActionScheduler_Schedule {

	/**
	 * Get the date & time this schedule was created to run, or calculate when it should be run
	 * after a given date & time.
	 *
	 * @param DateTime $after DateTime to calculate against.
	 *
	 * @return DateTime|null
	 */
	public function next( ?DateTime $after = null ) {
		if ( empty( $after ) ) {
			$return_value       = $this->get_date();
			$replacement_method = 'get_date()';
		} else {
			$return_value       = $this->get_next( $after );
			$replacement_method = 'get_next( $after )';
		}

		_deprecated_function( __METHOD__, '3.0.0', __CLASS__ . '::' . $replacement_method ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		return $return_value;
	}
}
