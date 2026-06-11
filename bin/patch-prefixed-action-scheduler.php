<?php

declare(strict_types=1);

$candidates = array(
	__DIR__ . '/../includes/vendor-prefixed/woocommerce/action-scheduler/classes/abstracts/ActionScheduler.php',
	__DIR__ . '/../includes/vendor-prefixed/woocommerce/action-scheduler/classes/abstracts/Justbee_PostCaster_ActionScheduler.php',
);

$target = null;

foreach ( $candidates as $candidate ) {
	if ( file_exists( $candidate ) ) {
		$target = $candidate;
		break;
	}
}

if ( null === $target ) {
	fwrite( STDERR, "Prefixed Action Scheduler autoloader file not found.\n" );
	exit( 1 );
}

$contents = file_get_contents( $target );

if ( false === $contents ) {
	fwrite( STDERR, "Unable to read prefixed Action Scheduler autoloader file.\n" );
	exit( 1 );
}

$search = <<<'PHP'
		if ( false !== $separator ) {
			if ( 0 !== strpos( $class, 'Action_Scheduler' ) ) {
				return;
			}
			$class = substr( $class, $separator + 1 );
		}
PHP;

$replace = <<<'PHP'
		if ( false !== $separator ) {
			if (
				0 !== strpos( $class, 'Action_Scheduler\\' )
				&& 0 !== strpos( $class, 'Justbee\\PostCaster\\Vendor\\Action_Scheduler\\' )
			) {
				return;
			}
			$class = substr( $class, $separator + 1 );
		}
PHP;

if ( ! str_contains( $contents, $search ) ) {
	fwrite( STDERR, "Expected Action Scheduler autoload block not found.\n" );
	exit( 1 );
}

$contents = str_replace( $search, $replace, $contents );

$prefixed_autoload_search = <<<'PHP'
		} elseif ( strpos( $class, 'Justbee_PostCaster_ActionScheduler' ) === 0 ) {
			$segments = explode( '_', $class );
			$type     = isset( $segments[1] ) ? $segments[1] : '';
PHP;

$prefixed_autoload_replace = <<<'PHP'
		} elseif ( strpos( $class, 'Justbee_PostCaster_ActionScheduler' ) === 0 ) {
			$relative = preg_replace( '/^Justbee_PostCaster_ActionScheduler_?/', '', $class );
			$segments = '' === $relative ? array() : explode( '_', $relative );
			$type     = isset( $segments[0] ) ? $segments[0] : '';
PHP;

if ( ! str_contains( $contents, $prefixed_autoload_search ) ) {
	fwrite( STDERR, "Expected prefixed Action Scheduler autoload segment block not found.\n" );
	exit( 1 );
}

$contents = str_replace( $prefixed_autoload_search, $prefixed_autoload_replace, $contents );

$migration_search = <<<'PHP'
		$segments = explode( '_', $class );
		$segment  = isset( $segments[1] ) ? $segments[1] : $class;
PHP;

$migration_replace = <<<'PHP'
		if ( 0 === strpos( $class, 'Justbee_PostCaster_ActionScheduler_' ) ) {
			$class = preg_replace( '/^Justbee_PostCaster_ActionScheduler_/', '', $class );
		}

		$segments = explode( '_', $class );
		$segment  = isset( $segments[1] ) ? $segments[1] : $class;
PHP;

$contents = preg_replace(
	'/' . preg_quote( $migration_search, '/' ) . '/',
	addcslashes( $migration_replace, '\\$' ),
	$contents,
	1,
	$migration_block_replacements
);

if ( 1 !== $migration_block_replacements ) {
	fwrite( STDERR, "Expected Action Scheduler migration segment block not found.\n" );
	exit( 1 );
}

if ( false === file_put_contents( $target, $contents ) ) {
	fwrite( STDERR, "Unable to patch prefixed Action Scheduler autoloader file.\n" );
	exit( 1 );
}

$migration_controller = __DIR__ . '/../includes/vendor-prefixed/woocommerce/action-scheduler/classes/migration/Controller.php';

if ( ! file_exists( $migration_controller ) ) {
	fwrite( STDERR, "Prefixed Action Scheduler migration controller file not found.\n" );
	exit( 1 );
}

$migration_contents = file_get_contents( $migration_controller );

if ( false === $migration_contents ) {
	fwrite( STDERR, "Unable to read prefixed Action Scheduler migration controller file.\n" );
	exit( 1 );
}

$migration_contents = str_replace(
	"return 'ActionScheduler_HybridStore';",
	"return 'Justbee_PostCaster_ActionScheduler_HybridStore';",
	$migration_contents,
	$migration_replacements
);

if ( 1 !== $migration_replacements ) {
	fwrite( STDERR, "Expected Action Scheduler migration class string not found.\n" );
	exit( 1 );
}

if ( false === file_put_contents( $migration_controller, $migration_contents ) ) {
	fwrite( STDERR, "Unable to patch prefixed Action Scheduler migration controller file.\n" );
	exit( 1 );
}

$namespaced_files = array(
	__DIR__ . '/../includes/vendor-prefixed/woocommerce/action-scheduler/classes/migration/Config.php',
	__DIR__ . '/../includes/vendor-prefixed/woocommerce/action-scheduler/classes/migration/BatchFetcher.php',
);

$namespaced_replacements = array(
	'use ActionScheduler_Logger as Logger;' => 'use Justbee_PostCaster_ActionScheduler_Logger as Logger;',
	'use ActionScheduler_Store as Store;'   => 'use Justbee_PostCaster_ActionScheduler_Store as Store;',
);

foreach ( $namespaced_files as $namespaced_file ) {
	if ( ! file_exists( $namespaced_file ) ) {
		fwrite( STDERR, "Prefixed Action Scheduler namespaced file not found: {$namespaced_file}\n" );
		exit( 1 );
	}

	$namespaced_contents = file_get_contents( $namespaced_file );

	if ( false === $namespaced_contents ) {
		fwrite( STDERR, "Unable to read prefixed Action Scheduler namespaced file: {$namespaced_file}\n" );
		exit( 1 );
	}

	$patched_contents = strtr( $namespaced_contents, $namespaced_replacements );

	if ( $patched_contents === $namespaced_contents ) {
		fwrite( STDERR, "Expected Action Scheduler import replacements not found in {$namespaced_file}\n" );
		exit( 1 );
	}

	if ( false === file_put_contents( $namespaced_file, $patched_contents ) ) {
		fwrite( STDERR, "Unable to patch prefixed Action Scheduler namespaced file: {$namespaced_file}\n" );
		exit( 1 );
	}
}

fwrite( STDOUT, "Patched prefixed Action Scheduler autoload and migration class handling.\n" );
