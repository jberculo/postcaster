<?php

/**
 * Class Justbee_PostCaster_ActionScheduler_FinishedAction
 *
 * @license GPL-3.0-or-later
 * Modified by justbee on 08-May-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */
class Justbee_PostCaster_ActionScheduler_FinishedAction extends Justbee_PostCaster_ActionScheduler_Action {

	/**
	 * Execute action.
	 */
	public function execute() {
		// don't execute.
	}

	/**
	 * Get finished state.
	 */
	public function is_finished() {
		return true;
	}
}
