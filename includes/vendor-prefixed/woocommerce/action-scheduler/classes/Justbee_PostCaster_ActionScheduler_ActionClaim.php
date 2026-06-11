<?php

/**
 * Class Justbee_PostCaster_ActionScheduler_ActionClaim
 *
 * @license GPL-3.0-or-later
 * Modified by justbee on 08-May-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */
class Justbee_PostCaster_ActionScheduler_ActionClaim {
	/**
	 * Claim ID.
	 *
	 * @var string
	 */
	private $id = '';

	/**
	 * Claimed action IDs.
	 *
	 * @var int[]
	 */
	private $action_ids = array();

	/**
	 * Construct.
	 *
	 * @param string $id Claim ID.
	 * @param int[]  $action_ids Action IDs.
	 */
	public function __construct( $id, array $action_ids ) {
		$this->id         = $id;
		$this->action_ids = $action_ids;
	}

	/**
	 * Get claim ID.
	 */
	public function get_id() {
		return $this->id;
	}

	/**
	 * Get IDs of claimed actions.
	 */
	public function get_actions() {
		return $this->action_ids;
	}
}
