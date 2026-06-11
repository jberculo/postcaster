<?php

/**
 * Class Justbee_PostCaster_ActionScheduler_FatalErrorMonitor
 *
 * @license GPL-3.0-or-later
 * Modified by justbee on 08-May-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */
class Justbee_PostCaster_ActionScheduler_FatalErrorMonitor {

	/**
	 * Justbee_PostCaster_ActionScheduler_ActionClaim instance.
	 *
	 * @var Justbee_PostCaster_ActionScheduler_ActionClaim
	 */
	private $claim = null;

	/**
	 * Justbee_PostCaster_ActionScheduler_Store instance.
	 *
	 * @var Justbee_PostCaster_ActionScheduler_Store
	 */
	private $store = null;

	/**
	 * Current action's ID.
	 *
	 * @var int
	 */
	private $action_id = 0;

	/**
	 * Construct.
	 *
	 * @param Justbee_PostCaster_ActionScheduler_Store $store Action store.
	 */
	public function __construct( Justbee_PostCaster_ActionScheduler_Store $store ) {
		$this->store = $store;
	}

	/**
	 * Start monitoring.
	 *
	 * @param Justbee_PostCaster_ActionScheduler_ActionClaim $claim Claimed actions.
	 */
	public function attach( Justbee_PostCaster_ActionScheduler_ActionClaim $claim ) {
		$this->claim = $claim;
		add_action( 'shutdown', array( $this, 'handle_unexpected_shutdown' ) );
		add_action( 'action_scheduler_before_execute', array( $this, 'track_current_action' ), 0, 1 );
		add_action( 'action_scheduler_after_execute', array( $this, 'untrack_action' ), 0, 0 );
		add_action( 'action_scheduler_execution_ignored', array( $this, 'untrack_action' ), 0, 0 );
		add_action( 'action_scheduler_failed_execution', array( $this, 'untrack_action' ), 0, 0 );
	}

	/**
	 * Stop monitoring.
	 */
	public function detach() {
		$this->claim = null;
		$this->untrack_action();
		remove_action( 'shutdown', array( $this, 'handle_unexpected_shutdown' ) );
		remove_action( 'action_scheduler_before_execute', array( $this, 'track_current_action' ), 0 );
		remove_action( 'action_scheduler_after_execute', array( $this, 'untrack_action' ), 0 );
		remove_action( 'action_scheduler_execution_ignored', array( $this, 'untrack_action' ), 0 );
		remove_action( 'action_scheduler_failed_execution', array( $this, 'untrack_action' ), 0 );
	}

	/**
	 * Track specified action.
	 *
	 * @param int $action_id Action ID to track.
	 */
	public function track_current_action( $action_id ) {
		$this->action_id = $action_id;
	}

	/**
	 * Un-track action.
	 */
	public function untrack_action() {
		$this->action_id = 0;
	}

	/**
	 * Handle unexpected shutdown.
	 */
	public function handle_unexpected_shutdown() {
		$error = error_get_last();

		if ( $error ) {
			if ( in_array( $error['type'], array( E_ERROR, E_PARSE, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ), true ) ) {
				if ( ! empty( $this->action_id ) ) {
					$this->store->mark_failure( $this->action_id );
					do_action( 'action_scheduler_unexpected_shutdown', $this->action_id, $error );
				}
			}

			$this->store->release_claim( $this->claim );
		}
	}
}
