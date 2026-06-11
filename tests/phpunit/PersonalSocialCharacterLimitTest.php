<?php

declare(strict_types=1);

use Justbee\PostCaster\Services\HttpService;
use Justbee\PostCaster\Services\MediaService;
use Justbee\PostCaster\Services\Networks\BlueskyPublisher;
use Justbee\PostCaster\Services\Networks\LinkedInPublisher;
use Justbee\PostCaster\Services\Networks\MastodonPublisher;
use Justbee\PostCaster\Services\Networks\NetworkPublisherInterface;

final class PersonalSocialCharacterLimitTest extends WP_UnitTestCase
{
    /**
     * @return array<int, NetworkPublisherInterface>
     */
    private function makePublishers(): array
    {
        $http = new HttpService();
        $media = new MediaService();

        return [
            new BlueskyPublisher($http, $media),
            new MastodonPublisher($http, $media),
            new LinkedInPublisher($http, $media),
        ];
    }

    /**
     * Regression: when a user saves the personal-socials form with the
     * "include featured image" checkbox in its default-on state,
     * UserProfileModel::save deletes the user_meta (because the value
     * matches the default). On read, the profile array therefore has
     * no entry for that option — and mergeProfileIntoOptions used to
     * fall back to a hard-coded '0', flipping the effective value off
     * for every personal-socials post.
     */
    public function test_personal_social_inherits_global_include_featured_image_when_profile_omits_it(): void
    {
        foreach ($this->makePublishers() as $publisher) {
            $key = $publisher->optionKey('include_featured_image');

            $merged = $publisher->mergeProfileIntoOptions([$key => '1'], []);

            $this->assertSame(
                '1',
                (string) ($merged[$key] ?? ''),
                $publisher->getKey() . ' personal social must inherit the global include_featured_image=1 when the profile has no explicit override.'
            );
        }
    }

    public function test_personal_social_honours_explicit_profile_include_featured_image_override(): void
    {
        foreach ($this->makePublishers() as $publisher) {
            $key = $publisher->optionKey('include_featured_image');

            $mergedOff = $publisher->mergeProfileIntoOptions([$key => '1'], [$key => '0']);
            $this->assertSame('0', (string) $mergedOff[$key], $publisher->getKey() . ' explicit profile-off override must win over global-on.');

            $mergedOn = $publisher->mergeProfileIntoOptions([$key => '0'], [$key => '1']);
            $this->assertSame('1', (string) $mergedOn[$key], $publisher->getKey() . ' explicit profile-on override must win over global-off.');
        }
    }

    public function test_personal_social_inherits_custom_global_character_limit(): void
    {
        foreach ($this->makePublishers() as $publisher) {
            $key = $publisher->optionKey('character_limit');
            $globalOptions = [$key => '250'];
            $profile = [];

            $merged = $publisher->mergeProfileIntoOptions($globalOptions, $profile);

            $this->assertSame(
                '250',
                (string) $merged[$key],
                $publisher->getKey() . ' personal social must inherit the global character limit.'
            );
        }
    }

    public function test_personal_social_falls_back_to_publisher_default_when_global_unset(): void
    {
        foreach ($this->makePublishers() as $publisher) {
            $key = $publisher->optionKey('character_limit');
            $globalOptions = [];
            $profile = [];

            $merged = $publisher->mergeProfileIntoOptions($globalOptions, $profile);

            $this->assertSame(
                (string) $publisher->getCharacterLimit(),
                (string) $merged[$key],
                $publisher->getKey() . ' personal social must fall back to the publisher default character limit.'
            );
        }
    }

    public function test_personal_social_tracks_global_when_global_changes(): void
    {
        foreach ($this->makePublishers() as $publisher) {
            $key = $publisher->optionKey('character_limit');

            $first = $publisher->mergeProfileIntoOptions([$key => '180'], []);
            $second = $publisher->mergeProfileIntoOptions([$key => '420'], []);

            $this->assertSame('180', (string) $first[$key], $publisher->getKey() . ' first merge should reflect global 180.');
            $this->assertSame('420', (string) $second[$key], $publisher->getKey() . ' second merge should follow updated global 420.');
        }
    }

    public function test_runtime_character_limit_uses_global_for_personal_social(): void
    {
        foreach ($this->makePublishers() as $publisher) {
            $key = $publisher->optionKey('character_limit');
            $globalOptions = [$key => '275'];

            $merged = $publisher->mergeProfileIntoOptions($globalOptions, []);

            $limit = (int) ($merged[$key] ?? $publisher->getCharacterLimit());
            if ($limit <= 0) {
                $limit = $publisher->getCharacterLimit();
            }

            $this->assertSame(
                275,
                $limit,
                $publisher->getKey() . ' runtime resolution for personal social must use the global character limit.'
            );
        }
    }
}