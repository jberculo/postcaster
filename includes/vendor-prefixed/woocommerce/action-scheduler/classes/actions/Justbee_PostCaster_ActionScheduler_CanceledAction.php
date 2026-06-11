<?php

/**
 * Class Justbee_PostCaster_ActionScheduler_CanceledAction
 *
 * Stored action which was canceled and therefore acts like a finished action but should always return a null schedule,
 * regardless of schedule passed to its constructor.
 *
 * @license GPL-3.0-or-later
 * Modified by justbee on 08-May-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */
class Justbee_PostCaster_ActionScheduler_CanceledAction extends Justbee_PostCaster_ActionScheduler_FinishedAction {

	/**
	 * Construct.
	 *
	 * @param string                        $hook Action's hook.
	 * @param array                         $args Action's arguments.
	 * @param null|Justbee_PostCaster_ActionScheduler_Schedule $schedule Action's schedule.
	 * @param string                        $group Action's group.
	 */
	public function __construct( $hook, array $args = array(), ?Justbee_PostCaster_ActionScheduler_Schedule $schedule = null, $group = '' ) {
		parent::__construct( $hook, $args, $schedule, $group );
		if ( is_null( $schedule ) ) {
			$this->set_schedule( new Justbee_PostCaster_ActionScheduler_NullSchedule() );
		}
	}
}
