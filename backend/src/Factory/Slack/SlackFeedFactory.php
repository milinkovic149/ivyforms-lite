<?php

namespace IvyForms\Factory\Slack;

use IvyForms\Entity\Slack\SlackFeed as SlackFeedEntity;
use IvyForms\ValueObjects\Slack\SlackFeed;

class SlackFeedFactory
{
    /** No rules: the feed sends for every submission. */
    private const DEFAULT_SMART_LOGIC = [
        'enabled' => false,
        'match'   => 'any',
        'rules'   => [],
    ];

    /**
     * @param array<string, mixed> $data
     */
    public static function create(array $data): SlackFeedEntity
    {
        $fields = self::decodeArray($data['fields'] ?? null);
        $channels = self::normalizeChannels($data);

        $channelId = isset($data['channelId']) ? (string) $data['channelId'] : null;
        $channelName = isset($data['channelName']) ? (string) $data['channelName'] : null;

        if ($channels !== []) {
            $channelId = $channels[0]['id'];
            $channelName = $channels[0]['name'];
        }

        $valueObject = new SlackFeed(
            isset($data['id']) ? (int) $data['id'] : null,
            (int) ($data['formId'] ?? 0),
            (string) ($data['name'] ?? ''),
            $channelId !== '' ? $channelId : null,
            $channelName !== '' ? $channelName : null,
            $channels,
            (string) ($data['messageTitle'] ?? ''),
            $fields,
            self::decodeSmartLogic($data['smartLogic'] ?? null),
            !array_key_exists('enabled', $data) || !empty($data['enabled'])
        );

        return new SlackFeedEntity($valueObject);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, array{id: string, name: string, type?: string}>
     */
    private static function normalizeChannels(array $data): array
    {
        $rawChannels = $data['channels'] ?? null;
        if (is_string($rawChannels)) {
            $decoded = json_decode($rawChannels, true);
            $rawChannels = is_array($decoded) ? $decoded : [];
        }

        $channels = [];
        if (is_array($rawChannels)) {
            foreach ($rawChannels as $channel) {
                if (!is_array($channel)) {
                    continue;
                }

                $id = (string) ($channel['id'] ?? '');
                if ($id === '') {
                    continue;
                }

                $normalized = [
                    'id'   => $id,
                    'name' => (string) ($channel['name'] ?? ''),
                ];

                $type = isset($channel['type']) ? (string) $channel['type'] : '';
                if ($type !== '') {
                    $normalized['type'] = $type;
                }

                $channels[] = $normalized;
            }
        }

        if ($channels !== []) {
            return $channels;
        }

        $legacyId = isset($data['channelId']) ? (string) $data['channelId'] : '';
        if ($legacyId === '') {
            return [];
        }

        return [
            [
                'id'   => $legacyId,
                'name' => isset($data['channelName']) ? (string) $data['channelName'] : '',
            ],
        ];
    }

    /**
     * Always the {enabled, match, rules} shape, so the API returns one shape.
     * Feeds saved before the smartLogic column existed (NULL or `[]`) read as
     * the default: no rules, the feed always sends.
     *
     * @param mixed $value
     * @return array<string, mixed>
     */
    private static function decodeSmartLogic($value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        if (!is_array($value) || $value === []) {
            return self::DEFAULT_SMART_LOGIC;
        }

        return array_merge(self::DEFAULT_SMART_LOGIC, $value);
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private static function decodeArray($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode((string) ($value ?? '[]'), true);

        return is_array($decoded) ? $decoded : [];
    }
}
