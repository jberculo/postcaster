<?php

namespace Justbee\PostCaster;

if (!defined('ABSPATH')) {
    exit;
}

spl_autoload_register(static function (string $class): void {
    $prefix = __NAMESPACE__ . '\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    if ($relativeClass === 'Plugin') {
        return;
    }

    $specialFiles = [
        'Cli\\Command' => __DIR__ . '/cli/CliCommand.php',
    ];

    if (isset($specialFiles[$relativeClass])) {
        require_once $specialFiles[$relativeClass];
        return;
    }

    $parts = explode('\\', $relativeClass);
    $className = array_pop($parts);
    $directory = $parts === [] ? '' : strtolower(implode('/', $parts)) . '/';
    $file = __DIR__ . '/' . $directory . $className . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});

final class Plugin
{
    /** @var self|null */
    private static $instance = null;

    private ?Services\NetworkRegistry $networks = null;
    private ?Models\SettingsModel $settings = null;
    private ?Models\UserProfileModel $profiles = null;

    public static function instance(): self
    {
        if (!self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function getNetworks(): ?Services\NetworkRegistry
    {
        return $this->networks;
    }

    public static function activate(): void
    {
        Support\LegacyMigration::run();
        Models\SettingsModel::activate();
    }

    private function __construct()
    {
        Support\LegacyMigration::run();
        $services = $this->buildServices();
        $this->networks = $services['networks'];
        $this->settings = $services['settings'];
        $this->profiles = $services['profiles'];

        $this->registerControllers($services);
        $this->registerHooks();
        $this->registerCliCommand($services);
    }

    /**
     * @return array<string, object>
     */
    private function buildServices(): array
    {
        $http = new Services\HttpService();
        $media = new Services\MediaService();
        $networks = new Services\NetworkRegistry($this->buildNetworkPublishers($http, $media));
        $debugLog = new Models\DebugLogModel();
        $settings = new Models\SettingsModel($networks);
        $profiles = new Models\UserProfileModel($settings, $networks);
        $postMeta = new Models\PostMetaModel();
        $contextBuilder = new Services\PostTemplateContextBuilder($settings, $profiles, $networks);
        $targets = new Services\PublishTargetResolver($profiles, $networks, $settings);
        $templateDescriptions = new Services\TemplateDescriptionService($networks, $settings->getDefaultTemplate());
        $templateFields = new Support\TemplateEditorFieldDecorator();
        $templateRenderer = new Templates\TemplateRenderer(new Templates\TemplateParser(), new Templates\TemplateFitter());
        $postRenderer = new Services\PostRenderer($templateRenderer);

        // Shared dependency cluster used by the three rendering/publishing
        // services. Named-argument unpacking keeps the wiring DRY without
        // forcing a value-object refactor on the service signatures.
        $shared = [
            'settings' => $settings,
            'postMeta' => $postMeta,
            'networks' => $networks,
            'targets' => $targets,
            'contextBuilder' => $contextBuilder,
            'templateDescriptions' => $templateDescriptions,
            'renderer' => $postRenderer,
        ];

        $targetContext = new Services\TargetContextResolver(...$shared);
        $previewBuilder = new Services\PreviewBuilder(
            ...$shared,
            media: $media,
            context: $targetContext,
        );
        $publisher = new Services\PublisherService(
            ...$shared,
            media: $media,
            context: $targetContext,
            previews: $previewBuilder,
        );
        $queue = new Services\PublishQueueService($publisher, $postMeta);
        $tests = new Services\TestPostService($networks, $debugLog, $publisher);

        return compact(
            'networks',
            'settings',
            'profiles',
            'postMeta',
            'debugLog',
            'templateDescriptions',
            'templateFields',
            'publisher',
            'queue',
            'tests'
        );
    }

    /** @param array<string, object> $s */
    private function registerControllers(array $s): void
    {
        new Controllers\AdminController(
            $s['settings'], $s['networks'], $s['postMeta'], $s['debugLog'],
            $s['publisher'], $s['queue'], $s['profiles'], $s['templateDescriptions'],
            $s['templateFields'], $s['tests'], __DIR__ . '/../views'
        );
        new Controllers\ProfileController(
            $s['settings'], $s['profiles'], $s['networks'], $s['publisher'],
            $s['templateDescriptions'], $s['templateFields'], $s['tests'],
            __DIR__ . '/../views'
        );
        new Controllers\PublishController($s['publisher'], $s['postMeta'], $s['queue']);
    }

    private function registerHooks(): void
    {
        add_action('admin_init', [$this, 'ensureEncryptionKey']);
        add_action('admin_notices', [$this, 'maybeRenderSodiumNotice']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);
        add_action('deleted_user', [$this, 'handleDeletedUser']);
        add_action('wpmu_delete_user', [$this, 'handleDeletedUser']);
    }

    /**
     * Self-heal: make sure the encryption key option exists on every admin
     * request so the plugin keeps working even when the activation hook did
     * not run (e.g. backup restore, fork install activated via WP-CLI).
     * ensureKey() short-circuits when the key is already there or when
     * sodium is unavailable, so the cost is one option lookup per request.
     */
    public function ensureEncryptionKey(): void
    {
        Support\SecretsCipher::ensureKey();
    }

    /** @param array<string, object> $s */
    private function registerCliCommand(array $s): void
    {
        if (!defined('WP_CLI') || !WP_CLI) {
            return;
        }

        $command = new Cli\Command(
            $s['settings'], $s['networks'], $s['profiles'], $s['postMeta'],
            $s['debugLog'], $s['publisher'], $s['tests']
        );
        \WP_CLI::add_command('justbee-postcaster', $command);
    }

    public function enqueueAdminAssets(string $hook): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $isPostScreen = $screen && in_array((string) $screen->base, ['post', 'post-new'], true);
        $isPluginScreen = $hook === 'toplevel_page_' . Controllers\AdminController::ROOT_MENU_SLUG
            || str_contains($hook, 'justbee-postcaster');

        if (!$isPostScreen && !$isPluginScreen) {
            return;
        }

        $version = defined('JUSTBEE_POSTCASTER_VERSION') ? JUSTBEE_POSTCASTER_VERSION : false;
        $base = plugin_dir_url(dirname(__DIR__) . '/postcaster.php') . 'assets/';

        wp_enqueue_style('justbee-postcaster-admin', $base . 'css/postcaster-admin.css', [], $version);
        wp_enqueue_script('justbee-postcaster-admin-ui', $base . 'js/postcaster-admin-ui.js', [], $version, true);
        wp_enqueue_script('justbee-postcaster-modal', $base . 'js/postcaster-modal.js', [], $version, true);
        wp_enqueue_script('justbee-postcaster-template-editor', $base . 'js/postcaster-template-editor.js', [], $version, true);

        $palettes = $this->networks instanceof Services\NetworkRegistry ? $this->networks->getAvatarPalettes() : [];
        wp_localize_script('justbee-postcaster-template-editor', 'justbeePostcasterAdmin', [
            'avatarPalettes' => $palettes,
        ]);

        if ($isPostScreen) {
            wp_enqueue_script('justbee-postcaster-compose-box', $base . 'js/postcaster-compose-box.js', [], $version, true);
            wp_localize_script('justbee-postcaster-compose-box', 'justbeePostcasterCompose', [
                'i18n' => [
                    'noPreview' => __('No preview', 'postcaster'),
                    /* translators: %s: social network label. */
                    'customizeForNetwork' => __('Customize for %s', 'postcaster'),
                    'customizeForAll' => __('Customize for all networks', 'postcaster'),
                    'confirmPublish' => __('Post this article now to the selected target?', 'postcaster'),
                ],
            ]);
        }
    }

    public function maybeRenderSodiumNotice(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (!Support\SecretsCipher::sodiumExtensionLoaded()) {
            printf(
                '<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
                esc_html__('PostCaster:', 'postcaster'),
                esc_html__('PHP libsodium is not available, so social network credentials cannot be encrypted. Ask your host to enable the sodium extension.', 'postcaster')
            );
        } elseif (!Support\SecretsCipher::isAvailable()) {
            printf(
                '<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
                esc_html__('PostCaster:', 'postcaster'),
                esc_html__('PostCaster has no encryption key yet. Re-activate the plugin from the Plugins screen, or define JUSTBEE_POSTCASTER_ENCRYPTION_KEY in wp-config.php.', 'postcaster')
            );
        }

        if (!$this->settings instanceof Models\SettingsModel) {
            return;
        }

        $this->settings->get();
        if (!$this->settings->hasSecretDecryptionFailures()) {
            return;
        }

        printf(
            '<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
            esc_html__('PostCaster:', 'postcaster'),
            esc_html__('One or more encrypted PostCaster credentials could not be decrypted. Re-save the affected credentials in Global socials before publishing.', 'postcaster')
        );
    }

    public function handleDeletedUser(int $userId): void
    {
        if ($this->profiles instanceof Models\UserProfileModel) {
            $this->profiles->removeFromSubscribedOtherPostsIndex($userId);
        }
    }

    private function buildNetworkPublishers(Services\HttpService $http, Services\MediaService $media): array
    {
        $publishers = [
            new Services\Networks\BlueskyPublisher($http, $media),
            new Services\Networks\MastodonPublisher($http, $media),
        ];

        return apply_filters('justbee_postcaster_network_publishers', $publishers, $http, $media);
    }
}
