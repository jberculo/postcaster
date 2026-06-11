# PostCaster integration

This document is for theme and plugin developers who want to customize PostCaster behavior.

## Principle

PostCaster now treats its own target options as the default source of truth. Integration hooks should only provide deviations from those defaults.

That means:

* PostCaster builds the normal target options first.
* Your hook returns only the values that should change.
* PostCaster merges those overrides into the standard options.

## Filters

### `postcaster_network_publishers`

Customize the list of registered network publishers.

Arguments:

* `array $publishers`
* `HttpService $http`
* `MediaService $media`

Use this when you want to add or remove a network publisher implementation.

### `postcaster_post_image_attachment_id`

Override the attachment ID used for the post image.

Arguments:

* `int $attachmentId`
* `int $postId`
* `string $context` with value `preview` or `publish`

Return `0` to skip attachment lookup.

Example:

```php
add_filter('postcaster_post_image_attachment_id', function (int $attachmentId, int $postId, string $context): int {
    if ($context === 'publish') {
        $fallbackId = (int) get_post_meta($postId, '_custom_social_image_id', true);
        if ($fallbackId > 0) {
            return $fallbackId;
        }
    }

    return $attachmentId;
}, 10, 3);
```

### `postcaster_post_image_asset`

Override the complete asset array used for image handling.

Arguments:

* `?array $asset`
* `int $postId`
* `string $context`
* `int $attachmentId`

Return `null` to skip image use entirely, or return a full asset array.

Expected asset shape:

```php
[
    'path' => '/absolute/path/to/image.jpg',
    'mime' => 'image/jpeg',
    'alt' => 'Alt text',
    'width' => 1200,
    'height' => 630,
]
```

### `postcaster_should_publish`

Allow or block automatic publishing for a specific post.

Arguments:

* `bool $shouldPublish`
* `WP_Post $post`
* `array $options`

Example:

```php
add_filter('postcaster_should_publish', function (bool $shouldPublish, WP_Post $post): bool {
    if (has_category('internal', $post)) {
        return false;
    }

    return $shouldPublish;
}, 10, 2);
```

### `postcaster_network_target_options`

Return only the option overrides that should differ from PostCaster's standard target options.

Arguments:

* `array $overrides`
* `NetworkPublisherInterface $network`
* `string $targetKey`
* `WP_Post $post`
* `array $globalOptions`
* `array $baseTargetOptions`

Important:

* Do not rebuild the full target options array unless you have to.
* Prefer returning only the changed keys.
* PostCaster merges your returned array into `$baseTargetOptions`.

Example:

```php
add_filter('postcaster_network_target_options', function (
    array $overrides,
    $network,
    string $targetKey,
    WP_Post $post,
    array $globalOptions,
    array $baseTargetOptions
): array {
    if ($network->getKey() !== 'mastodon') {
        return $overrides;
    }

    if (has_category('members-only', $post)) {
        return [
            'mastodon_visibility' => 'private',
        ];
    }

    return $overrides;
}, 10, 6);
```

### `postcaster_post_text`

Post-process the rendered text for a target.

Arguments:

* `string $text`
* `string $networkKey`
* `WP_Post $post`

Example:

```php
add_filter('postcaster_post_text', function (string $text, string $networkKey): string {
    if ($networkKey === 'bluesky') {
        return str_replace('&', 'and', $text);
    }

    return $text;
}, 10, 2);
```

## Implementation guidance

Use hooks for:

* theme-specific image fallback logic
* per-category or per-post-type behavior changes
* target-specific visibility or account tweaks
* final text normalization

Avoid hooks for:

* replacing core publish flow without need
* duplicating PostCaster's own template logic
* rebuilding full target arrays when only one field differs

## Where user-facing docs belong

Keep developer examples here in `docs/integration.md`.

Keep user-facing explanations in:

* `readme.txt`
* plugin settings UI
* future troubleshooting docs if needed
