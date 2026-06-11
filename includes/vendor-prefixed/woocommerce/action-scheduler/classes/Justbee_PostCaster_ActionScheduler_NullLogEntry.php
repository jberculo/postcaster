<?php

/**
 * Class Justbee_PostCaster_ActionScheduler_NullLogEntry
 *
 * @license GPL-3.0-or-later
 * Modified by justbee on 08-May-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */
class Justbee_PostCaster_ActionScheduler_NullLogEntry extends Justbee_PostCaster_ActionScheduler_LogEntry {

	/**
	 * Construct.
	 *
	 * @param string $action_id Action ID.
	 * @param string $message   Log entry.
	 */
	public function __construct( $action_id = '', $message = '' ) {
		// nothing to see here.
	}

}
