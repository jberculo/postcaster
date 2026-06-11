<?php

/**
 * Class Justbee_PostCaster_ActionScheduler_IntervalSchedule
 *
 * @license GPL-3.0-or-later
 * Modified by justbee on 08-May-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */
class Justbee_PostCaster_ActionScheduler_IntervalSchedule extends Justbee_PostCaster_ActionScheduler_Abstract_RecurringSchedule implements Justbee_PostCaster_ActionScheduler_Schedule {

	/**
	 * Deprecated property @see $this->__wakeup() for details.
	 *
	 * @var null
	 */
	private $start_timestamp = null;

	/**
	 * Deprecated property @see $this->__wakeup() for details.
	 *
	 * @var null
	 */
	private $interval_in_seconds = null;

	/**
	 * Calculate when this schedule should start after a given date & time using
	 * the number of seconds between recurrences.
	 *
	 * @param DateTime $after Timestamp.
	 * @return DateTime
	 */
	protected function calculate_next( DateTime $after ) {
		$after->modify( '+' . (int) $this->get_recurrence() . ' seconds' );
		return $after;
	}

	/**
	 * Schedule interval in seconds.
	 *
	 * @return int
	 */
	public function interval_in_seconds() {
		_deprecated_function( __METHOD__, '3.0.0', '(int)Justbee_PostCaster_ActionScheduler_Abstract_RecurringSchedule::get_recurrence()' );
		return (int) $this->get_recurrence();
	}

	/**
	 * Serialize interval schedules with data required prior to AS 3.0.0
	 *
	 * Prior to Action Scheduler 3.0.0, recurring schedules used different property names to
	 * refer to equivalent data. For example, Justbee_PostCaster_ActionScheduler_IntervalSchedule::start_timestamp
	 * was the same as Justbee_PostCaster_ActionScheduler_SimpleSchedule::timestamp. Action Scheduler 3.0.0
	 * aligned properties and property names for better inheritance. To guard against the
	 * possibility of infinite loops if downgrading to Action Scheduler < 3.0.0, we need to
	 * also store the data with the old property names so if it's unserialized in AS < 3.0,
	 * the schedule doesn't end up with a null/false/0 recurrence.
	 *
	 * @return array
	 */
	public function __sleep() {

		$sleep_params = parent::__sleep();

		$this->start_timestamp     = $this->scheduled_timestamp;
		$this->interval_in_seconds = $this->recurrence;

		return array_merge(
			$sleep_params,
			array(
				'start_timestamp',
				'interval_in_seconds',
			)
		);
	}

	/**
	 * Unserialize interval schedules serialized/stored prior to AS 3.0.0
	 *
	 * For more background, @see Justbee_PostCaster_ActionScheduler_Abstract_RecurringSchedule::__wakeup().
	 */
	public function __wakeup() {
		if ( is_null( $this->scheduled_timestamp ) && ! is_null( $this->start_timestamp ) ) {
			$this->scheduled_timestamp = $this->start_timestamp;
			unset( $this->start_timestamp );
		}

		if ( is_null( $this->recurrence ) && ! is_null( $this->interval_in_seconds ) ) {
			$this->recurrence = $this->interval_in_seconds;
			unset( $this->interval_in_seconds );
		}
		parent::__wakeup();
	}
}
