<?php

/**
 * Class Justbee_PostCaster_ActionScheduler_wpPostStore_TaxonomyRegistrar
 *
 * @codeCoverageIgnore
 *
 * @license GPL-3.0-or-later
 * Modified by justbee on 08-May-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */
class Justbee_PostCaster_ActionScheduler_wpPostStore_TaxonomyRegistrar {

	/**
	 * Registrar.
	 */
	public function register() {
		register_taxonomy( Justbee_PostCaster_ActionScheduler_wpPostStore::GROUP_TAXONOMY, Justbee_PostCaster_ActionScheduler_wpPostStore::POST_TYPE, $this->taxonomy_args() );
	}

	/**
	 * Get taxonomy arguments.
	 */
	protected function taxonomy_args() {
		$args = array(
			'label'             => __( 'Action Group', 'action-scheduler' ),
			'public'            => false,
			'hierarchical'      => false,
			'show_admin_column' => true,
			'query_var'         => false,
			'rewrite'           => false,
		);

		$args = apply_filters( 'action_scheduler_taxonomy_args', $args );
		return $args;
	}
}
