<?php

/**
 * Class Justbee_PostCaster_ActionScheduler_CronSchedule
 *
 * @license GPL-3.0-or-later
 * Modified by justbee on 08-May-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */
class Justbee_PostCaster_ActionScheduler_CronSchedule extends Justbee_PostCaster_ActionScheduler_Abstract_RecurringSchedule implements Justbee_PostCaster_ActionScheduler_Schedule {

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
	private $cron = null;

	/**
	 * Wrapper for parent constructor to accept a cron expression string and map it to a Justbee_PostCaster_CronExpression for this
	 * objects $recurrence property.
	 *
	 * @param DateTime              $start The date & time to run the action at or after. If $start aligns with the CronSchedule passed via $recurrence, it will be used. If it does not align, the first matching date after it will be used.
	 * @param Justbee_PostCaster_CronExpression|string $recurrence The Justbee_PostCaster_CronExpression used to calculate the schedule's next instance.
	 * @param DateTime|null         $first (Optional) The date & time the first instance of this interval schedule ran. Default null, meaning this is the first instance.
	 */
	public function __construct( DateTime $start, $recurrence, ?DateTime $first = null ) {
		if ( ! is_a( $recurrence, 'Justbee_PostCaster_CronExpression' ) ) {
			$recurrence = Justbee_PostCaster_CronExpression::factory( $recurrence );
		}

		// For backward compatibility, we need to make sure the date is set to the first matching cron date, not whatever date is passed in. Importantly, by passing true as the 3rd param, if $start matches the cron expression, then it will be used. This was previously handled in the now deprecated next() method.
		$date = $recurrence->getNextRunDate( $start, 0, true );

		// parent::__construct() will set this to $date by default, but that may be different to $start now.
		$first = empty( $first ) ? $start : $first;

		parent::__construct( $date, $recurrence, $first );
	}

	/**
	 * Calculate when an instance of this schedule would start based on a given
	 * date & time using its the Justbee_PostCaster_CronExpression.
	 *
	 * @param DateTime $after Timestamp.
	 * @return DateTime
	 */
	protected function calculate_next( DateTime $after ) {
		return $this->recurrence->getNextRunDate( $after, 0, false );
	}

	/**
	 * Get the schedule's recurrence.
	 *
	 * @return string
	 */
	public function get_recurrence() {
		return strval( $this->recurrence );
	}

	/**
	 * Serialize cron schedules with data required prior to AS 3.0.0
	 *
	 * Prior to Action Scheduler 3.0.0, recurring schedules used different property names to
	 * refer to equivalent data. For example, Justbee_PostCaster_ActionScheduler_IntervalSchedule::start_timestamp
	 * was the same as Justbee_PostCaster_ActionScheduler_SimpleSchedule::timestamp. Action Scheduler 3.0.0
	 * aligned properties and property names for better inheritance. To guard against the
	 * possibility of infinite loops if downgrading to Action Scheduler < 3.0.0, we need to
	 * also store the data with the old property names so if it's unserialized in AS < 3.0,
	 * the schedule doesn't end up with a null recurrence.
	 *
	 * @return array
	 */
	public function __sleep() {

		$sleep_params = parent::__sleep();

		$this->start_timestamp = $this->scheduled_timestamp;
		$this->cron            = $this->recurrence;

		return array_merge(
			$sleep_params,
			array(
				'start_timestamp',
				'cron',
			)
		);
	}

	/**
	 * Unserialize cron schedules serialized/stored prior to AS 3.0.0
	 *
	 * For more background, @see Justbee_PostCaster_ActionScheduler_Abstract_RecurringSchedule::__wakeup().
	 */
	public function __wakeup() {
		if ( is_null( $this->scheduled_timestamp ) && ! is_null( $this->start_timestamp ) ) {
			$this->scheduled_timestamp = $this->start_timestamp;
			unset( $this->start_timestamp );
		}

		if ( is_null( $this->recurrence ) && ! is_null( $this->cron ) ) {
			$this->recurrence = $this->cron;
			unset( $this->cron );
		}
		parent::__wakeup();
	}
}

