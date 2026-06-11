<?php

namespace Justbee\PostCaster\Controllers;

use Justbee\PostCaster\Models\DebugLogModel;
use Justbee\PostCaster\Models\PostMetaModel;
use Justbee\PostCaster\Models\SettingsModel;
use Justbee\PostCaster\Models\UserProfileModel;
use Justbee\PostCaster\Services\NetworkRegistry;
use Justbee\PostCaster\Services\PublishQueueService;
use Justbee\PostCaster\Services\PublisherService;
use Justbee\PostCaster\Services\TemplateDescriptionService;
use Justbee\PostCaster\Services\TestPostService;
use Justbee\PostCaster\Support\TemplateEditorFieldDecorator;
use Justbee\PostCaster\Views\NoticeRenderer;

if (!defined('ABSPATH')) {
    exit;
}

final class AdminController
{
    public const ROOT_MENU_SLUG = 'justbee-postcaster';
    private const NOTICE_META_KEY = '_justbee_postcaster_admin_notice_';
    private const SETTINGS_NOTICE_META_KEY = '_justbee_postcaster_settings_notice';
    private const SETTINGS_NOTICE_TTL = 3600;
    private const MENU_SLUG_GLOBAL_SOCIALS = 'justbee-postcaster-global-socials';
    private const PAGINATION_PER_PAGE = 25;
    private SettingsModel $settings;
    private NetworkRegistry $networks;
    private PostMetaModel $postMeta;
    private DebugLogModel $debugLog;
    private PublisherService $publisher;
    private PublishQueueService $queue;
    private UserProfileModel $profiles;
    private TemplateDescriptionService $templates;
    private TemplateEditorFieldDecorator $templateFields;
    private TestPostService $tests;
    private string $viewsPath;

    public function __construct(
        SettingsModel $settings,
        NetworkRegistry $networks,
        PostMetaModel $postMeta,
        DebugLogModel $debugLog,
        PublisherService $publisher,
        PublishQueueService $queue,
        UserProfileModel $profiles,
        TemplateDescriptionService $templates,
        TemplateEditorFieldDecorator $templateFields,
        TestPostService $tests,
        string $viewsPath
    )
    {
        $this->settings = $settings;
        $this->networks = $networks;
        $this->postMeta = $postMeta;
        $this->debugLog = $debugLog;
        $this->publisher = $publisher;
        $this->queue = $queue;
        $this->profiles = $profiles;
        $this->templates = $templates;
        $this->templateFields = $templateFields;
        $this->tests = $tests;
        $this->viewsPath = $viewsPath;

        add_action('admin_menu', [$this, 'registerPage']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_post_justbee_postcaster_send_test', [$this, 'handleSendTestPost']);
        add_action('admin_post_justbee_postcaster_clear_debug_logs', [$this, 'handleClearDebugLogs']);
        add_action('admin_notices', [$this, 'renderAdminNotices']);
        add_filter('plugin_action_links_' . plugin_basename(dirname(__DIR__, 2) . '/postcaster.php'), [$this, 'addSettingsLink']);
    }

    public static function queuePostNotice(int $postId, string $message, string $type = 'info'): void
    {
        $userId = get_current_user_id();
        if ($userId <= 0 || $postId <= 0) {
            return;
        }

        update_user_meta($userId, self::NOTICE_META_KEY . $postId, [
            'type' => $type,
            'message' => $message,
            'timestamp' => gmdate('c'),
        ]);
    }

    public static function consumePostNotice(int $postId, int $userId): ?array
    {
        if ($postId <= 0 || $userId <= 0) {
            return null;
        }

        $metaKey = self::NOTICE_META_KEY . $postId;
        $notice = get_user_meta($userId, $metaKey, true);
        if (!is_array($notice) || empty($notice['message'])) {
            return null;
        }

        delete_user_meta($userId, $metaKey);
        return $notice;
    }

    public static function queueSettingsNotice(string $message, string $type = 'info'): void
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return;
        }

        update_user_meta($userId, self::SETTINGS_NOTICE_META_KEY, self::buildSettingsNoticePayload($message, $type));
    }

    public function registerPage(): void
    {
        $personalNetworksEnabled = ($this->settings->get()['personal_networks_enabled'] ?? '1') === '1';
        $rootCapability = $personalNetworksEnabled ? 'edit_posts' : 'manage_options';

        add_menu_page(
            __('PostCaster', 'postcaster'),
            __('PostCaster', 'postcaster'),
            $rootCapability,
            self::ROOT_MENU_SLUG,
            [$this, 'renderRootPage'],
            $this->getMenuIcon(),
            65
        );

        add_submenu_page(
            self::ROOT_MENU_SLUG,
            __('Global socials', 'postcaster'),
            __('Global socials', 'postcaster'),
            'manage_options',
            self::MENU_SLUG_GLOBAL_SOCIALS,
            [$this, 'renderGlobalSocialsPage']
        );

        global $submenu;
        if (
            current_user_can('manage_options')
            && isset($submenu[self::ROOT_MENU_SLUG][0][0], $submenu[self::ROOT_MENU_SLUG][0][1])
            && $submenu[self::ROOT_MENU_SLUG][0][1] === $rootCapability
        ) {
            $submenu[self::ROOT_MENU_SLUG][0][0] = __('Settings', 'postcaster');
        }
    }

    public function registerSettings(): void
    {
        register_setting(
            'justbee_postcaster_options_group',
            SettingsModel::OPTION_NAME,
            [
                'type'              => 'array',
                'sanitize_callback' => [$this, 'sanitizeRegisteredOptions'],
                'default'           => [],
                'show_in_rest'      => false,
            ]
        );
    }

    /**
     * Settings API sanitization entry point for the PostCaster option array.
     *
     * SettingsModel::sanitize() performs the field-by-field work with WordPress
     * core sanitizers such as sanitize_key(), sanitize_text_field(),
     * sanitize_textarea_field(), and esc_url_raw() depending on the field.
     *
     * @param mixed $input Raw option payload from register_setting().
     * @return array<string, mixed>
     */
    public function sanitizeRegisteredOptions($input): array
    {
        return $this->settings->sanitize($input);
    }

    public function addSettingsLink(array $links): array
    {
        array_unshift($links, '<a href="' . esc_url(admin_url('admin.php?page=' . self::ROOT_MENU_SLUG)) . '">' . esc_html__('Settings', 'postcaster') . '</a>');
        return $links;
    }

    public function renderRootPage(): void
    {
        if (current_user_can('manage_options')) {
            $this->renderSettingsPage();
            return;
        }

        wp_safe_redirect(admin_url('admin.php?page=justbee-postcaster-my-socials'));
        exit;
    }

    public function renderSettingsPage(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $justbee_postcaster_options = $this->settings->get();
        $justbee_postcaster_option_name = SettingsModel::OPTION_NAME;
        $justbee_postcaster_debug_enabled = ($justbee_postcaster_options['debug'] ?? '0') === '1';
        $justbee_postcaster_selected_post_types = is_array($justbee_postcaster_options['post_types'] ?? null) ? $justbee_postcaster_options['post_types'] : ['post'];
        $justbee_postcaster_available_post_types = self::getSelectablePostTypes();
        $justbee_postcaster_networks = $this->networks->all();
        $justbee_postcaster_system_debug_entries = $justbee_postcaster_debug_enabled ? array_reverse($this->debugLog->getAll()) : [];
        $justbee_postcaster_debug_entries = $justbee_postcaster_debug_enabled ? $this->postMeta->getAllLogs() : [];
        $justbee_postcaster_network_labels = [];
        foreach ($justbee_postcaster_networks as $justbee_postcaster_network) {
            $justbee_postcaster_network_labels[$justbee_postcaster_network->getKey()] = $justbee_postcaster_network->getLabel();
        }
        $justbee_postcaster_failure_rows = $this->buildFailureRows();
        $justbee_postcaster_failure_rows_pagination = $this->paginateRows(
            $justbee_postcaster_failure_rows,
            'justbee_postcaster_failures_page',
            '#postcaster-tab-debug'
        );
        $justbee_postcaster_failure_rows = $justbee_postcaster_failure_rows_pagination['items'];
        $justbee_postcaster_system_debug_entries_pagination = $this->paginateRows(
            $justbee_postcaster_system_debug_entries,
            'justbee_postcaster_system_logs_page',
            '#postcaster-tab-debug'
        );
        $justbee_postcaster_system_debug_entries = $justbee_postcaster_system_debug_entries_pagination['items'];
        $justbee_postcaster_debug_entries_pagination = $this->paginateRows(
            $justbee_postcaster_debug_entries,
            'justbee_postcaster_post_logs_page',
            '#postcaster-tab-debug'
        );
        $justbee_postcaster_debug_entries = $justbee_postcaster_debug_entries_pagination['items'];
        $justbee_postcaster_queue_rows = $this->buildQueueRows();
        $justbee_postcaster_queue_rows_pagination = $this->paginateRows(
            $justbee_postcaster_queue_rows,
            'justbee_postcaster_queue_page',
            '#postcaster-tab-queue'
        );
        $justbee_postcaster_queue_rows = $justbee_postcaster_queue_rows_pagination['items'];
        require $this->viewsPath . '/admin-settings-page.php';
    }

    public function renderGlobalSocialsPage(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $justbee_postcaster_options = $this->settings->get();
        $justbee_postcaster_option_name = SettingsModel::OPTION_NAME;
        $justbee_postcaster_networks = $this->networks->all();
        $justbee_postcaster_network_warnings = [];
        $justbee_postcaster_has_enabled_networks = false;
        $justbee_postcaster_network_fields = [];
        $justbee_postcaster_effective_general_template = $this->templates->describeGeneralTemplate($justbee_postcaster_options);
        $justbee_postcaster_fallback_general_template = $this->templates->describeGeneralFallbackTemplate();
        $justbee_postcaster_general_preview = $this->publisher->buildExamplePreviewData(null, null, 'global');
        $justbee_postcaster_general_preview_items = $this->publisher->buildExamplePreviewItems(null, null, 'global');

        foreach ($justbee_postcaster_networks as $justbee_postcaster_network) {
            if (($justbee_postcaster_options[$justbee_postcaster_network->optionKey('enabled')] ?? '0') === '1') {
                $justbee_postcaster_has_enabled_networks = true;
                if (($justbee_postcaster_options['enabled'] ?? '0') !== '1') {
                    $justbee_postcaster_network_warnings[$justbee_postcaster_network->getKey()] = sprintf(
                        /* translators: %s: social network label. */
                        __('PostCaster is disabled in the general settings, so %s will not post until you enable PostCaster under General.', 'postcaster'),
                        $justbee_postcaster_network->getLabel()
                    );
                }
            }

            $justbee_postcaster_network_preview = $this->publisher->buildExamplePreviewData($justbee_postcaster_network->getKey(), null, 'global');
            $justbee_postcaster_network_fields[$justbee_postcaster_network->getKey()] = $this->templateFields->decorate(
                $justbee_postcaster_network->getAdminFields(),
                $this->templates->describeNetworkTemplate($justbee_postcaster_network->getKey(), $justbee_postcaster_options),
                $this->templates->describeNetworkFallbackTemplate($justbee_postcaster_network->getKey(), $justbee_postcaster_options),
                $justbee_postcaster_network->getKey(),
                'global',
                0,
                (string) ($justbee_postcaster_network_preview['text'] ?? ''),
                (string) (($justbee_postcaster_network_preview['image']['url'] ?? '')),
                (string) (($justbee_postcaster_network_preview['image']['alt'] ?? '')),
                is_array($justbee_postcaster_network_preview['card'] ?? null) ? $justbee_postcaster_network_preview['card'] : null,
                $this->publisher->buildExamplePreviewItems($justbee_postcaster_network->getKey(), null, 'global'),
                NoticeRenderer::buildTestPostConfig($justbee_postcaster_network, [
                    'type' => 'submit',
                    'form_id' => 'postcaster-test-form-' . $justbee_postcaster_network->getKey(),
                ])
            );
        }

        $justbee_postcaster_global_disabled_warning = ($justbee_postcaster_options['enabled'] ?? '0') !== '1' && $justbee_postcaster_has_enabled_networks;
        $justbee_postcaster_general_preview_initial_text = (string) ($justbee_postcaster_general_preview['text'] ?? '');
        $justbee_postcaster_general_preview_initial_image_url = (string) (($justbee_postcaster_general_preview['image']['url'] ?? ''));
        $justbee_postcaster_general_preview_initial_image_alt = (string) (($justbee_postcaster_general_preview['image']['alt'] ?? ''));
        $justbee_postcaster_general_preview_initial_items = $justbee_postcaster_general_preview_items;
        require $this->viewsPath . '/admin-global-socials-page.php';
    }

    public function handleSendTestPost(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to run a PostCaster test post.', 'postcaster'));
        }

        check_admin_referer('justbee_postcaster_send_test_post');
        $networkKey = isset($_POST['network']) ? sanitize_key((string) wp_unslash($_POST['network'])) : '';
        $notice = $this->tests->send($networkKey, $this->settings->get(), [
            'scope' => 'general',
            'preview_scope' => 'global',
        ]);
        self::queueSettingsNotice($notice['message'], $notice['type']);

        wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG_GLOBAL_SOCIALS));
        exit;
    }

    public function renderAdminNotices(): void
    {
        if (!is_admin()) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen) {
            return;
        }

        $userId = get_current_user_id();
        if ($userId <= 0) {
            return;
        }

        if ($this->isPostCasterAdminPage($screen)) {
            $notice = self::consumeSettingsNotice($userId);
            if ($notice !== null) {
                $this->printNotice($notice, $this->formatTimestampedMessage($notice));
            }
        }

        if (in_array($screen->base, ['post', 'post-new'], true)) {
            $postId = $this->getCurrentEditPostId();
            if ($postId > 0) {
                $notice = self::consumePostNotice($postId, $userId);
                if ($notice !== null) {
                    $this->printNotice($notice, (string) $notice['message']);
                }
            }
        }
    }

    private function printNotice(array $notice, string $message): void
    {
        if ($message === '') {
            return;
        }

        $type = sanitize_key((string) ($notice['type'] ?? 'info'));
        if (!in_array($type, ['success', 'warning', 'error', 'info'], true)) {
            $type = 'info';
        }

        printf(
            '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
            esc_attr($type),
            esc_html($message)
        );
    }

    private function getCurrentEditPostId(): int
    {
        // Called during admin_notices. There is no nonce to verify here — we're
        // just identifying which post edit screen we're currently on, mirroring
        // what WordPress core itself does in wp-admin/post.php.
        // Casts to int are full sanitizers for integer fields; phpcs's list of recognized sanitizers doesn't include them.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
        if (isset($_GET['post'])) {
            return (int) $_GET['post'];
        }

        if (isset($_POST['post_ID'])) {
            return (int) $_POST['post_ID'];
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash

        return 0;
    }

    public function handleClearDebugLogs(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to clear the PostCaster logs.', 'postcaster'));
        }

        check_admin_referer('justbee_postcaster_clear_debug_logs');
        $this->postMeta->clearAllLogs();
        $this->debugLog->clear();
        self::queueSettingsNotice(__('PostCaster logging cleared.', 'postcaster'), 'success');

        wp_safe_redirect(admin_url('admin.php?page=' . self::ROOT_MENU_SLUG));
        exit;
    }

    private function isPostCasterAdminPage($screen): bool
    {
        if (!is_object($screen) || !isset($screen->base)) {
            return false;
        }

        return in_array((string) $screen->base, [
            'toplevel_page_' . self::ROOT_MENU_SLUG,
            self::ROOT_MENU_SLUG . '_page_' . self::MENU_SLUG_GLOBAL_SOCIALS,
            self::ROOT_MENU_SLUG . '_page_' . ProfileController::MENU_SLUG,
        ], true);
    }

    private function getMenuIcon(): string
    {
        $iconPath = $this->viewsPath . '/postcaster-menu-icon.svg';
        if (!is_file($iconPath)) {
            return 'dashicons-share';
        }

        $svg = file_get_contents($iconPath);
        if ($svg === false || $svg === '') {
            return 'dashicons-share';
        }

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    private function formatTimestampedMessage(array $message): string
    {
        $timestamp = (string) ($message['timestamp'] ?? '');
        if ($timestamp === '') {
            return (string) ($message['message'] ?? '');
        }

        return sprintf('[%s] %s', $timestamp, (string) ($message['message'] ?? ''));
    }

    public static function consumeSettingsNotice(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $notice = get_user_meta($userId, self::SETTINGS_NOTICE_META_KEY, true);
        if (!is_array($notice)) {
            return null;
        }

        delete_user_meta($userId, self::SETTINGS_NOTICE_META_KEY);

        if (empty($notice['message'])) {
            return null;
        }

        $expiresAt = isset($notice['expires_at']) ? (int) $notice['expires_at'] : 0;
        if ($expiresAt > 0 && $expiresAt < time()) {
            return null;
        }

        return $notice;
    }

    private static function buildSettingsNoticePayload(string $message, string $type): array
    {
        $createdAt = time();
        return [
            'type' => $type,
            'message' => $message,
            'timestamp' => gmdate('c', $createdAt),
            'expires_at' => $createdAt + self::SETTINGS_NOTICE_TTL,
        ];
    }

    public static function getSelectablePostTypes(): array
    {
        $postTypes = get_post_types([
            'show_ui' => true,
        ], 'objects');
        $postTypes = array_filter($postTypes, static function ($postType): bool {

                return $postType instanceof \WP_Post_Type && is_post_type_viewable($postType);
        });
        unset($postTypes['attachment']);
        return $postTypes;
    }

    /**
     * @return array<int, array{
     *   post: \WP_Post,
     *   errors: array<int, array{network: string, target_key: string, message: string}>,
     *   retry_count: int,
     *   next_retry: int|null,
     *   edit_url: string,
     * }>
     */
    private function buildFailureRows(int $limit = 200): array
    {
        $rows = [];
        foreach ($this->postMeta->getPostsWithErrors($limit) as $postId) {
            $post = get_post($postId);
            if (!$post instanceof \WP_Post) {
                continue;
            }

            $errors = $this->postMeta->getErrors($postId);
            if ($errors === []) {
                continue;
            }

            $retrySummary = $this->queue->getRetrySummaryForPost($postId);
            $rows[] = [
                'post' => $post,
                'errors' => $errors,
                'retry_count' => (int) ($retrySummary['retry_count'] ?? 0),
                'next_retry' => is_int($retrySummary['next_retry'] ?? null) ? (int) $retrySummary['next_retry'] : null,
                'edit_url' => (string) get_edit_post_link($postId, 'raw'),
            ];
        }

        return $rows;
    }

    /**
     * Turn a queue target key into a human-friendly label. Personal
     * targets are stored as `user_<id>`; show the network handle taken
     * from the user's profile (e.g. @yoast.bsky.social) followed by the
     * WordPress user_login. Falls back to whatever piece is available.
     */
    private function resolveQueueTargetLabel(string $networkKey, string $targetKey): string
    {
        if ($targetKey === '' || $targetKey === 'global') {
            return __('Global accounts', 'postcaster');
        }

        if (!str_starts_with($targetKey, 'user_')) {
            return $targetKey;
        }

        $userId = (int) substr($targetKey, 5);
        if ($userId <= 0) {
            return $targetKey;
        }

        $user = get_user_by('id', $userId);
        if (!$user instanceof \WP_User) {
            return $targetKey;
        }

        $login = (string) $user->user_login;

        $handle = '';
        $network = $this->networks->get($networkKey);
        if ($network !== null) {
            $reference = $network->getAccountReference($this->profiles->get($userId));
            if ($reference !== '') {
                $handle = $network->formatAccountReference($reference);
            }
        }

        if ($handle !== '' && $login !== '') {
            return $handle . ' / ' . $login;
        }

        if ($handle !== '') {
            return $handle;
        }

        return $login !== '' ? $login : $targetKey;
    }

    /**
     * @return array<int, array{
     *   action_id:int,
     *   status:string,
     *   scheduled_at:?int,
     *   attempt:int,
     *   trigger:string,
     *   post:? \WP_Post,
     *   post_title:string,
     *   edit_url:string,
     *   network_label:string,
     *   target_label:string,
     *   error_message:string
     * }>
     */
    private function buildQueueRows(int $limit = 200): array
    {
        $rows = [];
        $statusLabels = [
            \Justbee_PostCaster_ActionScheduler_Store::STATUS_PENDING => __('Pending', 'postcaster'),
            \Justbee_PostCaster_ActionScheduler_Store::STATUS_RUNNING => __('Running', 'postcaster'),
            \Justbee_PostCaster_ActionScheduler_Store::STATUS_FAILED => __('Failed', 'postcaster'),
            \Justbee_PostCaster_ActionScheduler_Store::STATUS_COMPLETE => __('Published', 'postcaster'),
        ];
        $triggerLabels = [
            'auto_publish' => __('Automatic publish', 'postcaster'),
            'manual_publish' => __('Manual publish', 'postcaster'),
            'retry' => __('Retry', 'postcaster'),
        ];

        foreach ($this->queue->getQueueRows($limit) as $queueRow) {
            $postId = (int) ($queueRow['post_id'] ?? 0);
            $post = $postId > 0 ? get_post($postId) : null;
            $postTitle = $post instanceof \WP_Post ? get_the_title($post) : '';
            if ($postTitle === '' && $postId > 0) {
                $postTitle = sprintf('#%d', $postId);
            }

            $networkKey = (string) ($queueRow['network_key'] ?? '');
            $targetKey = (string) ($queueRow['target_key'] ?? '');
            $network = $this->networks->get($networkKey);
            $publications = $postId > 0 ? $this->postMeta->getRemotePublications($postId) : [];
            $hasRemotePublication = false;
            $errors = $postId > 0 ? $this->postMeta->getErrors($postId) : [];
            $errorMessage = '';

            foreach ($publications as $publication) {
                if (
                    (string) ($publication['network'] ?? '') === $networkKey
                    && (string) ($publication['target_key'] ?? '') === $targetKey
                ) {
                    $hasRemotePublication = true;
                    break;
                }
            }

            foreach ($errors as $error) {
                if (
                    (string) ($error['network'] ?? '') === $networkKey
                    && (string) ($error['target_key'] ?? '') === $targetKey
                ) {
                    $errorMessage = (string) ($error['message'] ?? '');
                    break;
                }
            }

            $rawStatus = (string) ($queueRow['status'] ?? '');
            $displayStatus = (string) ($statusLabels[$rawStatus] ?? $rawStatus);
            if ($hasRemotePublication && !in_array($rawStatus, [
                \Justbee_PostCaster_ActionScheduler_Store::STATUS_PENDING,
                \Justbee_PostCaster_ActionScheduler_Store::STATUS_RUNNING,
            ], true)) {
                $displayStatus = __('Published', 'postcaster');
                $errorMessage = '';
            }

            $rows[] = [
                'action_id' => (int) ($queueRow['action_id'] ?? 0),
                'status' => $displayStatus,
                'scheduled_at' => is_int($queueRow['scheduled_at'] ?? null) ? (int) $queueRow['scheduled_at'] : null,
                'attempt' => (int) ($queueRow['attempt'] ?? 1),
                'trigger' => (string) ($triggerLabels[(string) ($queueRow['trigger'] ?? '')] ?? ($queueRow['trigger'] ?? '')),
                'post' => $post instanceof \WP_Post ? $post : null,
                'post_title' => $postTitle,
                'edit_url' => $postId > 0 ? (string) get_edit_post_link($postId, 'raw') : '',
                'network_label' => $network ? $network->getLabel() : $networkKey,
                'target_label' => $this->resolveQueueTargetLabel($networkKey, $targetKey),
                'error_message' => $errorMessage,
            ];
        }

        return $rows;
    }

    /**
     * @param array<int, mixed> $rows
     * @return array{
     *   items: array<int, mixed>,
     *   current_page: int,
     *   total_pages: int,
     *   total_items: int,
     *   per_page: int,
     *   page_arg: string,
     *   base_url: string,
     *   fragment: string
     * }
     */
    private function paginateRows(array $rows, string $pageArg, string $fragment): array
    {
        $perPage = self::PAGINATION_PER_PAGE;
        $totalItems = count($rows);
        $totalPages = max(1, (int) ceil($totalItems / $perPage));
        $currentPage = min($this->getRequestedPage($pageArg), $totalPages);
        $offset = ($currentPage - 1) * $perPage;

        return [
            'items' => array_slice($rows, $offset, $perPage),
            'current_page' => $currentPage,
            'total_pages' => $totalPages,
            'total_items' => $totalItems,
            'per_page' => $perPage,
            'page_arg' => $pageArg,
            'base_url' => (string) menu_page_url(self::ROOT_MENU_SLUG, false),
            'fragment' => $fragment,
        ];
    }

    private function getRequestedPage(string $pageArg): int
    {
        // Read-only pagination argument on an admin settings page.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $page = isset($_GET[$pageArg]) ? absint(wp_unslash($_GET[$pageArg])) : 1;
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        return max(1, $page);
    }
}
