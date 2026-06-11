<?php

declare(strict_types=1);

use Justbee\PostCaster\Services\HttpService;
use Justbee\PostCaster\Services\MediaService;
use Justbee\PostCaster\Services\Networks\BlueskyPublisher;
use Justbee\PostCaster\Services\Networks\LinkedInPublisher;
use Justbee\PostCaster\Services\Networks\MastodonPublisher;
use Justbee\PostCaster\Services\Networks\NetworkPublisherInterface;

final class NetworkProfileFieldsTest extends WP_UnitTestCase
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

    public function test_profile_fields_do_not_expose_character_limit(): void
    {
        foreach ($this->makePublishers() as $publisher) {
            $keys = array_map(
                static fn(array $field): string => (string) ($field['key'] ?? ''),
                $publisher->getProfileFields()
            );

            $this->assertNotContains(
                $publisher->optionKey('character_limit'),
                $keys,
                $publisher->getKey() . ' profile fields should use the global character limit.'
            );
        }
    }

    public function test_admin_fields_still_expose_character_limit(): void
    {
        foreach ($this->makePublishers() as $publisher) {
            $keys = array_map(
                static fn(array $field): string => (string) ($field['key'] ?? ''),
                $publisher->getAdminFields()
            );

            $this->assertContains(
                $publisher->optionKey('character_limit'),
                $keys,
                $publisher->getKey() . ' admin fields should still allow changing the global character limit.'
            );
        }
    }
}
