<?php

namespace Justbee\PostCaster\Controllers;

use Justbee\PostCaster\Models\SettingsModel;
use Justbee\PostCaster\Models\UserProfileModel;
use Justbee\PostCaster\Services\NetworkRegistry;
use Justbee\PostCaster\Services\PublisherService;
use Justbee\PostCaster\Services\TemplateDescriptionService;
use Justbee\PostCaster\Services\TestPostService;
use Justbee\PostCaster\Support\TemplateEditorFieldDecorator;
use Justbee\PostCaster\Views\NoticeRenderer;
use WP_User;

if (!defined('ABSPATH')) {
    exit;
}

final class ProfileController
{
    public const MENU_SLUG = 'justbee-postcaster-my-socials';

    private SettingsModel $settings;
    private UserProfileModel $profiles;
    private NetworkRegistry $networks;
    private PublisherService $publisher;
    private TemplateDescriptionService $templates;
    private TemplateEditorFieldDecorator $templateFields;
    private TestPostService $tests;
    private string $viewsPath;

    public function __construct(SettingsModel $settings, UserProfileModel $profiles, NetworkRegistry $networks, PublisherService $publisher, TemplateDescriptionService $templates, TemplateEditorFieldDecorator $templateFields, TestPostService $tests, string $viewsPath)
    {
        $this->settings = $settings;
        $this->profiles = $profiles;
        $this->networks = $networks;
        $this->publisher = $publisher;
        $this->templates = $templates;
        $this->templateFields = $templateFields;
        $this->tests = $tests;
        $this->viewsPath = $viewsPath;
        add_action('admin_menu', [$this, 'registerPage']);
        add_action('admin_post_justbee_postcaster_save_my_socials', [$this, 'handleSaveMySocials']);
        add_action('admin_post_justbee_postcaster_send_profile_test', [$this, 'handleSendTestPost']);
    }

    public function registerPage(): void
    {
        if (($this->settings->get()['personal_networks_enabled'] ?? '1') !== '1') {
            return;
        }

        add_submenu_page(
            AdminController::ROOT_MENU_SLUG,
            __('My socials', 'postcaster'),
            __('My socials', 'postcaster'),
            'edit_posts',
            self::MENU_SLUG,
            [$this, 'renderPage']
        );
    }

    public function renderPage(): void
    {
        $justbee_postcaster_user = wp_get_current_user();
        if (!$justbee_postcaster_user instanceof WP_User || $justbee_postcaster_user->ID <= 0 || !current_user_can('edit_user', $justbee_postcaster_user->ID)) {
            return;
        }

        $justbee_postcaster_global_options = $this->settings->get();
        $justbee_postcaster_personal_networks_disabled_message = null;
        if (($justbee_postcaster_global_options['personal_networks_enabled'] ?? '1') !== '1') {
            $justbee_postcaster_personal_networks_disabled_message = __('Personal PostCaster accounts are disabled in the general settings.', 'postcaster');
        }

        $justbee_postcaster_profile = $this->profiles->get($justbee_postcaster_user->ID);
        $justbee_postcaster_networks = $this->settings->getAvailablePersonalNetworks($justbee_postcaster_global_options);
        $justbee_postcaster_profile_general_fields = [
            [
                'key' => 'profile_template',
                'label' => __('Use a custom template', 'postcaster'),
                'type' => 'textarea',
                'rows' => 6,
                'toggle' => 'profile_template_enabled',
                'template_help' => true,
            ],
        ];
        $justbee_postcaster_profile_general_fields = $this->templateFields->decorate(
            $justbee_postcaster_profile_general_fields,
            $this->templates->describeGeneralTemplate($justbee_postcaster_global_options, $justbee_postcaster_profile),
            $this->templates->describeGeneralTemplate($justbee_postcaster_global_options),
            '',
            'personal',
            $justbee_postcaster_user->ID,
            $this->publisher->buildExampleStatusText(null, null, 'personal', $justbee_postcaster_user->ID),
            '',
            '',
            null,
            $this->publisher->buildExamplePreviewItems(null, null, 'personal', $justbee_postcaster_user->ID)
        );
        $justbee_postcaster_enabled_network_labels = [];
        $justbee_postcaster_network_warnings = [];
        $justbee_postcaster_network_fields = [];

        foreach ($justbee_postcaster_networks as $justbee_postcaster_network) {
            $justbee_postcaster_network_fields[$justbee_postcaster_network->getKey()] = $this->templateFields->decorate(
                $justbee_postcaster_network->getProfileFields(),
                $this->templates->describeNetworkTemplate($justbee_postcaster_network->getKey(), $justbee_postcaster_global_options, $justbee_postcaster_profile),
                $this->templates->describeNetworkFallbackTemplate($justbee_postcaster_network->getKey(), $justbee_postcaster_global_options, $justbee_postcaster_profile),
                $justbee_postcaster_network->getKey(),
                'personal',
                $justbee_postcaster_user->ID,
                $this->publisher->buildExampleStatusText($justbee_postcaster_network->getKey(), null, 'personal', $justbee_postcaster_user->ID),
                '',
                '',
                null,
                $this->publisher->buildExamplePreviewItems($justbee_postcaster_network->getKey(), null, 'personal', $justbee_postcaster_user->ID),
                NoticeRenderer::buildTestPostConfig($justbee_postcaster_network, [
                    'type' => 'button',
                    'attributes' => [
                        'data-postcaster-profile-test-button' => '1',
                        'data-postcaster-network' => $justbee_postcaster_network->getKey(),
                        'data-postcaster-user-id' => (string) $justbee_postcaster_user->ID,
                        'data-postcaster-action-url' => admin_url('admin-post.php'),
                    ],
                ])
            );

            if (($justbee_postcaster_profile[$justbee_postcaster_network->optionKey('enabled')] ?? '0') === '1') {
                $justbee_postcaster_enabled_network_labels[] = $justbee_postcaster_network->getLabel();
                $justbee_postcaster_network_warnings[$justbee_postcaster_network->getKey()] = sprintf(
                    /* translators: %s: social network label. */
                    __('Personal accounts are disabled, so %s will not post until you enable personal accounts under General.', 'postcaster'),
                    $justbee_postcaster_network->getLabel()
                );
            }
        }

        $justbee_postcaster_personal_accounts_warning = null;
        if (($justbee_postcaster_profile['enabled'] ?? '0') !== '1' && $justbee_postcaster_enabled_network_labels !== []) {
            $justbee_postcaster_personal_accounts_warning = sprintf(
                /* translators: %s: comma-separated list of enabled social networks. */
                __('Personal accounts are disabled, so nothing will be posted even though these networks are enabled: %s', 'postcaster'),
                implode(', ', $justbee_postcaster_enabled_network_labels)
            );
        } else {
            $justbee_postcaster_network_warnings = [];
        }

        require $this->viewsPath . '/my-socials-page.php';
    }

    public function handleSaveMySocials(): void
    {
        $userId = get_current_user_id();
        if (!current_user_can('edit_user', $userId)) {
            wp_die(esc_html__('You are not allowed to edit your PostCaster social settings.', 'postcaster'));
        }

        if (($this->settings->get()['personal_networks_enabled'] ?? '1') !== '1') {
            AdminController::queueSettingsNotice(__('Personal PostCaster accounts are disabled in the general settings.', 'postcaster'), 'warning');
            wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG));
            exit;
        }

        $nonce = isset($_POST['justbee_postcaster_user_profile_nonce']) ? sanitize_text_field(wp_unslash((string) $_POST['justbee_postcaster_user_profile_nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'justbee_postcaster_user_profile')) {
            wp_die(esc_html__('You are not allowed to edit your PostCaster social settings.', 'postcaster'));
        }

        $input = [];
        if (isset($_POST['justbee_postcaster_user']) && is_array($_POST['justbee_postcaster_user'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitization is handled centrally in UserProfileModel::sanitize() to preserve textarea content correctly.
            $input = wp_unslash($_POST['justbee_postcaster_user']);
        }

        $this->profiles->save($userId, $input);
        AdminController::queueSettingsNotice(__('Your PostCaster social settings were saved.', 'postcaster'), 'success');
        wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG));
        exit;
    }

    public function handleSendTestPost(): void
    {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- cast to int is a full sanitizer for an integer field.
        $userId = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
        if ($userId <= 0 || !current_user_can('edit_user', $userId)) {
            wp_die(esc_html__('You are not allowed to run a PostCaster test post.', 'postcaster'));
        }

        if (($this->settings->get()['personal_networks_enabled'] ?? '1') !== '1') {
            wp_die(esc_html__('Personal PostCaster accounts are disabled in the general settings.', 'postcaster'));
        }

        check_admin_referer('justbee_postcaster_send_profile_test_post', 'justbee_postcaster_profile_test_post_nonce');

        $networkKey = isset($_POST['network']) ? sanitize_key((string) wp_unslash($_POST['network'])) : '';
        $options = $this->settings->get();
        if (!$this->settings->isPersonalNetworkAvailable($networkKey, $options)) {
            wp_die(esc_html__('This personal PostCaster network is not available.', 'postcaster'));
        }

        $profile = $this->profiles->getExplicit($userId);
        $options = $this->profiles->mergeIntoOptions($options, $profile);
        $network = $this->networks->get($networkKey);
        if ($network) {
            $options = $network->mergeProfileIntoOptions($options, $profile);
        }
        $user = get_userdata($userId);
        $scopeUser = $user instanceof WP_User && $user->user_login !== ''
            ? 'user_' . sanitize_user($user->user_login, true)
            : 'user_' . $userId;

        $notice = $this->tests->send($networkKey, $options, [
            'scope' => 'profile:' . $scopeUser,
            'preview_scope' => 'personal',
            'user_id' => $userId,
        ]);
        AdminController::queueSettingsNotice($notice['message'], $notice['type']);

        wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG));
        exit;
    }
}
