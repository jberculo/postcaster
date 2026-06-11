<?php

/**
 * Class Justbee_PostCaster_ActionScheduler_Abstract_Schedule
 *
 * @license GPL-3.0-or-later
 * Modified by justbee on 08-May-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */
abstract class Justbee_PostCaster_ActionScheduler_Abstract_Schedule extends Justbee_PostCaster_ActionScheduler_Schedule_Deprecated {

	/**
	 * The date & time the schedule is set to run.
	 *
	 * @var DateTime
	 */
	private $scheduled_date = null;

	/**
	 * Timestamp equivalent of @see $this->scheduled_date
	 *
	 * @var int
	 */
	protected $scheduled_timestamp = null;

	/**
	 * Construct.
	 *
	 * @param DateTime $date The date & time to run the action.
	 */
	public function __construct( DateTime $date ) {
		$this->scheduled_date = $date;
	}

	/**
	 * Check if a schedule should recur.
	 *
	 * @return bool
	 */
	abstract public function is_recurring();

	/**
	 * Calculate when the next instance of this schedule would run based on a given date & time.
	 *
	 * @param DateTime $after Start timestamp.
	 * @return DateTime
	 */
	abstract protected function calculate_next( DateTime $after );

	/**
	 * Get the next date & time when this schedule should run after a given date & time.
	 *
	 * @param DateTime $after Start timestamp.
	 * @return DateTime|null
	 */
	public function get_next( DateTime $after ) {
		$after = clone $after;
		if ( $after > $this->scheduled_date ) {
			$after = $this->calculate_next( $after );
			return $after;
		}
		return clone $this->scheduled_date;
	}

	/**
	 * Get the date & time the schedule is set to run.
	 *
	 * @return DateTime|null
	 */
	public function get_date() {
		return $this->scheduled_date;
	}

	/**
	 * For PHP 5.2 compat, because DateTime objects can't be serialized
	 *
	 * @return array
	 */
	public function __sleep() {
		$this->scheduled_timestamp = $this->scheduled_date->getTimestamp();
		return array(
			'scheduled_timestamp',
		);
	}

	/**
	 * Wakeup.
	 */
	public function __wakeup() {
		$this->scheduled_date = as_get_datetime_object( $this->scheduled_timestamp );
		unset( $this->scheduled_timestamp );
	}
}
