<?php

namespace Justbee\PostCaster\Services;

use Justbee\PostCaster\Models\SettingsModel;
use Justbee\PostCaster\Models\UserProfileModel;
use WP_Post;
use WP_User;

if (!defined('ABSPATH')) {
    exit;
}

final class PostTemplateContextBuilder
{
    private SettingsModel $settings;
    private UserProfileModel $profiles;
    private NetworkRegistry $networks;

    public function __construct(SettingsModel $settings, UserProfileModel $profiles, NetworkRegistry $networks)
    {
        $this->settings = $settings;
        $this->profiles = $profiles;
        $this->networks = $networks;
    }

    public function buildTestValues(string $networkKey, array $options): array
    {
        $title = __('PostCaster test post', 'postcaster');
        $excerpt = __('This is a template-based test post from PostCaster.', 'postcaster');

        return [
            'title' => $title,
            'site' => $this->decodeText((string) get_bloginfo('name')),
            'post' => $excerpt,
            'excerpt' => $excerpt,
            'category' => '',
            'cat_desc' => '',
            'date' => wp_date(get_option('date_format')),
            'modified' => wp_date(get_option('date_format') . ' ' . get_option('time_format')),
            'url' => home_url('/postcaster-test/'),
            'author' => '',
            '@site' => $this->buildExampleSiteReference($networkKey, $options),
            '@author' => '',
            'tags' => '',
        ];
    }

    public function buildExampleValues(?string $networkKey, array $options = [], string $scope = 'global', int $userId = 0): array
    {
        $examplePost = $this->getExamplePost($scope, $userId);
        if ($examplePost instanceof WP_Post) {
            return $this->buildPostValues($examplePost, $networkKey, null, $options);
        }

        $accountReference = $networkKey !== null ? $this->buildNetworkReference($networkKey, $options) : '';
        $authorReference = $accountReference !== '' ? $accountReference : '@janedoe';
        $excerpt = 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.';

        return [
            'title' => 'Lorem ipsum dolor sit amet',
            'site' => $this->decodeText((string) get_bloginfo('name')),
            'post' => $excerpt,
            'excerpt' => $excerpt,
            'category' => 'Example category',
            'cat_desc' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit.',
            'date' => wp_date(get_option('date_format')),
            'modified' => wp_date(get_option('date_format') . ' ' . get_option('time_format')),
            'url' => home_url('/lorem-ipsum/'),
            'author' => 'Jane Doe',
            '@site' => $accountReference !== '' ? $accountReference : $this->decodeText((string) get_bloginfo('name')),
            '@author' => $authorReference !== '' ? $authorReference : 'Jane Doe',
            'tags' => '#Lorem #Ipsum #Dolor',
        ];
    }

    public function getExamplePost(string $scope = 'global', int $userId = 0): ?WP_Post
    {
        return $this->getLatestPublishedExamplePost($scope, $userId);
    }

    private function getLatestPublishedExamplePost(string $scope, int $userId): ?WP_Post
    {
        $queryArgs = [
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'orderby' => 'date',
            'order' => 'DESC',
            'no_found_rows' => true,
            'suppress_filters' => false,
        ];

        if ($scope === 'personal' && $userId > 0) {
            $queryArgs['author'] = $userId;
        }

        $posts = get_posts($queryArgs);
        $post = $posts[0] ?? null;

        return $post instanceof WP_Post ? $post : null;
    }

    public function buildPostValues(WP_Post $post, ?string $networkKey, ?string $targetKey, array $options): array
    {
        $title = $this->decodeText(wp_strip_all_tags(get_the_title($post)));
        $url = get_permalink($post);
        $postExcerpt = has_excerpt($post)
            ? get_the_excerpt($post)
            : wp_trim_words(wp_strip_all_tags(strip_shortcodes((string) $post->post_content)), 30, '...');
        $postExcerpt = $this->decodeText(trim($postExcerpt));
        $primaryCategory = $this->getPrimaryCategory($post);
        $authorContext = $this->buildAuthorContext($post, $networkKey, $targetKey, $options);

        return [
            'title' => $title,
            'site' => $this->decodeText((string) get_bloginfo('name')),
            'post' => $postExcerpt,
            'excerpt' => $postExcerpt,
            'category' => $primaryCategory['name'],
            'cat_desc' => $primaryCategory['description'],
            'date' => get_the_date('', $post),
            'modified' => get_the_modified_date('', $post),
            'url' => $url,
            'author' => $authorContext['displayname'],
            'displayname' => $authorContext['displayname'],
            'account' => $authorContext['account'],
            '@site' => $networkKey !== null
                ? ($this->buildGlobalNetworkReference($networkKey) !== '' ? $this->buildGlobalNetworkReference($networkKey) : $this->decodeText((string) get_bloginfo('name')))
                : $this->decodeText((string) get_bloginfo('name')),
            '@author' => $authorContext['reference'] !== '' ? $authorContext['reference'] : $authorContext['displayname'],
            'tags' => $this->buildHashtagList($post),
        ];
    }

    /**
     * Return only real network account references for mention-capable placeholders.
     *
     * Unlike buildPostValues(), this never falls back to the plain site or author
     * name. Empty strings mean "no mention candidate for this placeholder".
     *
     * @return array{'@site': string, '@author': string}
     */
    public function buildPlaceholderMentionValues(WP_Post $post, ?string $networkKey, ?string $targetKey, array $options): array
    {
        if ($networkKey === null) {
            return [
                '@site' => '',
                '@author' => '',
            ];
        }

        $siteReference = $this->buildGlobalNetworkReference($networkKey);
        $authorContext = $this->buildAuthorContext($post, $networkKey, $targetKey, $options);

        return [
            '@site' => $siteReference,
            '@author' => (string) ($authorContext['reference'] ?? ''),
        ];
    }

    private function buildExampleSiteReference(?string $networkKey, array $options): string
    {
        $reference = $networkKey !== null ? $this->buildGlobalNetworkReference($networkKey) : '';

        return $reference !== '' ? $reference : $this->decodeText((string) get_bloginfo('name'));
    }

    private function buildAuthorContext(WP_Post $post, ?string $networkKey, ?string $targetKey, array $options): array
    {
        $author = get_user_by('id', (int) $post->post_author);
        $displayName = $author instanceof WP_User ? $this->decodeText((string) $author->display_name) : '';
        $reference = '';

        if ($author instanceof WP_User && $networkKey !== null) {
            $profile = $this->profiles->get($author->ID);
            $reference = $this->buildNetworkReference($networkKey, $profile);
        }

        $account = $networkKey !== null
            ? $this->buildNetworkReference($networkKey, $options !== [] ? $options : $this->settings->get())
            : '';

        if ($reference === '' && str_starts_with((string) $targetKey, 'user_')) {
            $targetUserId = (int) substr((string) $targetKey, 5);
            if ($targetUserId > 0) {
                $profile = $this->profiles->get($targetUserId);
                $reference = $networkKey !== null ? $this->buildNetworkReference($networkKey, $profile) : '';
            }
        }

        return [
            'displayname' => $displayName,
            'reference' => $reference,
            'account' => $account,
        ];
    }

    private function buildGlobalNetworkReference(string $networkKey): string
    {
        return $this->buildNetworkReference($networkKey, $this->settings->get());
    }

    private function buildNetworkReference(string $networkKey, array $options): string
    {
        $network = $this->networks->get($networkKey);
        if ($network === null) {
            return '';
        }

        return $network->formatAccountReference($network->getAccountReference($options));
    }

    private function getPrimaryCategory(WP_Post $post): array
    {
        $categories = get_the_category($post->ID);
        $category = $categories[0] ?? null;

        if (!$category) {
            return ['name' => '', 'description' => ''];
        }

        return [
            'name' => $this->decodeText((string) $category->name),
            'description' => $this->decodeText(trim((string) $category->description)),
        ];
    }

    private function buildHashtagList(WP_Post $post): string
    {
        $tags = wp_get_post_tags($post->ID, ['fields' => 'names']);
        $hashtags = [];

        foreach ($tags as $tag) {
            $normalized = preg_replace('/[^\p{L}\p{N}_]+/u', '', str_replace(' ', '', $this->decodeText((string) $tag)));
            if ($normalized === null || $normalized === '') {
                continue;
            }

            $hashtags[] = '#' . $normalized;
        }

        return implode(' ', array_values(array_unique($hashtags)));
    }

    private function decodeText(string $text): string
    {
        $decoded = $text;

        for ($i = 0; $i < 3; $i++) {
            $next = html_entity_decode(wp_specialchars_decode($decoded, ENT_QUOTES), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($next === $decoded) {
                break;
            }

            $decoded = $next;
        }

        return $decoded;
    }
}
