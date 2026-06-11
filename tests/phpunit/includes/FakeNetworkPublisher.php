<?php

declare(strict_types=1);

use Justbee\PostCaster\Services\HttpService;
use Justbee\PostCaster\Services\Networks\AbstractNetworkPublisher;

class FakeNetworkPublisher extends AbstractNetworkPublisher
{
    public string $key;
    public int $characterLimit = 500;

    /** @var array<int, array{post:WP_Post, options:array, asset:?array, text:string}> */
    public array $publishedCalls = [];

    /** @var mixed */
    public $nextResult = ['id' => 'remote-id', 'url' => 'https://example.test/post/1'];

    /** @var null|callable */
    public $preparePostTextCallback = null;

    /** @var array<int, array{options:array,text:string,context:array}> */
    public array $publishTestCalls = [];

    public function __construct(string $key = 'fake', ?HttpService $http = null)
    {
        parent::__construct($http ?? new HttpService());
        $this->key = $key;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getLabel(): string
    {
        return ucfirst($this->key);
    }

    public function getCharacterLimit(): int
    {
        return $this->characterLimit;
    }

    public function getGlobalDefaults(): array
    {
        return $this->defaultsWithEnabled([]);
    }

    public function sanitizeGlobal(array $input, array $defaults): array
    {
        return $this->filterToOwnKeys($input);
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
        return ($options[$this->optionKey('enabled')] ?? '0') === '1';
    }

    public function preparePostText(WP_Post $post, array $options, string $text): string
    {
        if (is_callable($this->preparePostTextCallback)) {
            return (string) call_user_func($this->preparePostTextCallback, $post, $options, $text);
        }

        return $text;
    }

    public function shouldRenderPreviewCard(WP_Post $post, string $text, array $options, bool $includeFeaturedImage): bool
    {
        return $this->postUrlIsPresentInText($post, $text);
    }

    public function shouldAttachAsset(WP_Post $post, string $renderedText, array $options, bool $includeFeaturedImage): bool
    {
        return $includeFeaturedImage;
    }

    public function publish(WP_Post $post, array $options, ?array $asset, string $text)
    {
        $this->publishedCalls[] = [
            'post' => $post,
            'options' => $options,
            'asset' => $asset,
            'text' => $text,
        ];

        return $this->nextResult;
    }

    public function publishTest(array $options, string $text, array $context = [])
    {
        $this->publishTestCalls[] = [
            'options' => $options,
            'text' => $text,
            'context' => $context,
        ];

        return $this->nextResult;
    }

    private function filterToOwnKeys(array $input): array
    {
        $prefix = $this->key . '_';
        $out = [];
        foreach ($input as $key => $value) {
            if (is_string($key) && str_starts_with($key, $prefix)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
