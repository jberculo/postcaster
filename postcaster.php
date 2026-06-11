<?php

/**
 * Plugin Name: PostCaster
 * Plugin URI: https://justbee.nl/
 * Description: PostCaster publishes WordPress posts with featured images to Bluesky and Mastodon.
 * Version: 0.4.5
 * Author: Justbee
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: postcaster
 * Domain Path: /languages
 */

use Justbee\PostCaster\Plugin;
use Justbee\PostCaster\Support\SecretsCipher;

if (!defined('ABSPATH')) {
    exit;
}

const JUSTBEE_POSTCASTER_MIN_PHP_VERSION = '8.1';

if (!defined('JUSTBEE_POSTCASTER_VERSION')) {
    $justbee_postcaster_plugin_data = get_file_data(__FILE__, ['Version' => 'Version']);
    define('JUSTBEE_POSTCASTER_VERSION', $justbee_postcaster_plugin_data['Version'] !== '' ? $justbee_postcaster_plugin_data['Version'] : '0.0.0');
    unset($justbee_postcaster_plugin_data);
}

$justbee_postcaster_action_scheduler_bootstrap = __DIR__ . '/includes/vendor-prefixed/woocommerce/action-scheduler/action-scheduler.php';
if (
    is_readable($justbee_postcaster_action_scheduler_bootstrap)
    && !class_exists('Justbee_PostCaster_ActionScheduler_Versions', false)
    && !class_exists('Justbee_PostCaster_ActionScheduler', false)
    && !function_exists('as_schedule_single_action')
) {
    require_once $justbee_postcaster_action_scheduler_bootstrap;
}

require_once __DIR__ . '/includes/Plugin.php';

register_activation_hook(__FILE__, static function (): void {
    if (version_compare(PHP_VERSION, JUSTBEE_POSTCASTER_MIN_PHP_VERSION, '<')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            esc_html(sprintf(
                /* translators: 1: required PHP version, 2: current PHP version */
                __('PostCaster requires PHP %1$s or newer. This site runs PHP %2$s.', 'postcaster'),
                JUSTBEE_POSTCASTER_MIN_PHP_VERSION,
                PHP_VERSION
            )),
            esc_html__('PostCaster activation failed', 'postcaster'),
            ['back_link' => true]
        );
    }

    if (!extension_loaded('sodium') || !function_exists('sodium_crypto_secretbox')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            esc_html__(
                'PostCaster requires the PHP sodium extension to encrypt social network credentials. Ask your host to enable it before activating the plugin.',
                'postcaster'
            ),
            esc_html__('PostCaster activation failed', 'postcaster'),
            ['back_link' => true]
        );
    }

    if (!SecretsCipher::ensureKey()) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            esc_html__(
                'PostCaster could not generate an encryption key. Check that the PHP sodium extension is fully functional and try again.',
                'postcaster'
            ),
            esc_html__('PostCaster activation failed', 'postcaster'),
            ['back_link' => true]
        );
    }

    Plugin::activate();
});

Plugin::instance();
