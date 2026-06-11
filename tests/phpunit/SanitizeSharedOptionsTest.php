<?php

declare(strict_types=1);

use Justbee\PostCaster\Services\Networks\AbstractNetworkPublisher;
use Justbee\PostCaster\Services\HttpService;

final class SanitizeSharedOptionsTest extends WP_UnitTestCase
{
    private AbstractNetworkPublisher $publisher;

    public function set_up(): void
    {
        parent::set_up();

        $this->publisher = new class(new HttpService()) extends AbstractNetworkPublisher {
            public function getKey(): string
            {
                return 'stub';
            }

            public function getLabel(): string
            {
                return 'Stub';
            }

            public function getCharacterLimit(): int
            {
                return 500;
            }

            public function getGlobalDefaults(): array
            {
                return $this->defaultsWithEnabled([]);
            }

            public function sanitizeGlobal(array $input, array $defaults): array
            {
                return $this->sanitizeSharedOptions($input, $defaults);
            }

            public function sanitizeProfile(array $input, array $defaults): array
            {
                return $this->sanitizeSharedOptions($input, $defaults);
            }

            public function mergeProfileIntoOptions(array $globalOptions, array $profile): array
            {
                return array_merge($globalOptions, $profile);
            }

            public function getAdminFields(): array
            {
                return [];
            }

            public function getProfileFields(): array
            {
                return [];
            }

            public function isConfigured(array $options): bool
            {
                return true;
            }

            public function publish(WP_Post $post, array $options, ?array $asset, string $text)
            {
                return true;
            }

            public function publishTest(array $options, string $text, array $context = [])
            {
                return true;
            }
        };
    }

    public function test_enabled_flag_is_normalized_to_zero_or_one(): void
    {
        $result = $this->publisher->sanitizeGlobal(['stub_enabled' => 'on'], $this->publisher->getGlobalDefaults());
        $this->assertSame('1', $result['stub_enabled']);

        $result = $this->publisher->sanitizeGlobal([], $this->publisher->getGlobalDefaults());
        $this->assertSame('0', $result['stub_enabled']);
    }

    public function test_template_override_is_preserved_when_equal_to_fallback(): void
    {
        $defaults = array_merge($this->publisher->getGlobalDefaults(), [
            'template_effective' => "Plugin default\ntext",
        ]);

        $result = $this->publisher->sanitizeGlobal([
            'stub_template_enabled' => '1',
            'stub_template' => "Plugin default\ntext",
        ], $defaults);

        $this->assertSame('1', $result['stub_template_enabled'], 'Explicit override flag must be respected even when value matches fallback.');
        $this->assertSame("Plugin default\ntext", $result['stub_template'], 'Stored template must reflect what the user submitted.');
    }

    public function test_template_survives_when_different_from_fallback(): void
    {
        $defaults = array_merge($this->publisher->getGlobalDefaults(), [
            'template_effective' => 'Plugin default',
        ]);

        $result = $this->publisher->sanitizeGlobal([
            'stub_template_enabled' => '1',
            'stub_template' => 'Custom network text',
        ], $defaults);

        $this->assertSame('1', $result['stub_template_enabled']);
        $this->assertSame('Custom network text', $result['stub_template']);
    }

    public function test_character_limit_falls_back_to_default_for_invalid_input(): void
    {
        foreach (['0', '-17', 'abc', ''] as $invalid) {
            $result = $this->publisher->sanitizeGlobal(
                ['stub_character_limit' => $invalid],
                $this->publisher->getGlobalDefaults()
            );
            $this->assertSame('500', $result['stub_character_limit'], "Input {$invalid} should fall back to the network default.");
        }
    }

    public function test_character_limit_accepts_positive_value(): void
    {
        $result = $this->publisher->sanitizeGlobal(['stub_character_limit' => '280'], $this->publisher->getGlobalDefaults());
        $this->assertSame('280', $result['stub_character_limit']);
    }

    public function test_crlf_in_template_is_normalized_to_lf(): void
    {
        $result = $this->publisher->sanitizeGlobal([
            'stub_template_enabled' => '1',
            'stub_template' => "line one\r\nline two\rline three",
        ], $this->publisher->getGlobalDefaults());

        $this->assertSame("line one\nline two\nline three", $result['stub_template']);
    }
}
