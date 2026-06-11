<?php

/**
 * Class Justbee_PostCaster_ActionScheduler_NullAction
 *
 * @license GPL-3.0-or-later
 * Modified by justbee on 08-May-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */
class Justbee_PostCaster_ActionScheduler_NullAction extends Justbee_PostCaster_ActionScheduler_Action {

	/**
	 * Construct.
	 *
	 * @param string                        $hook Action hook.
	 * @param mixed[]                       $args Action arguments.
	 * @param null|Justbee_PostCaster_ActionScheduler_Schedule $schedule Action schedule.
	 */
	public function __construct( $hook = '', array $args = array(), ?Justbee_PostCaster_ActionScheduler_Schedule $schedule = null ) {
		$this->set_schedule( new Justbee_PostCaster_ActionScheduler_NullSchedule() );
	}

	/**
	 * Execute action.
	 */
	public function execute() {
		// don't execute.
	}
}
