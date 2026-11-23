<?php

namespace IvyForms\Common\Sanitizer\Slack;

use IvyForms\Common\Exceptions\InvalidArgumentException;
use IvyForms\Common\Sanitizer\HtmlSanitizer;
use IvyForms\Common\Sanitizer\Sanitizer;
use IvyForms\Services\Translations\BackendStrings;

/**
 * Sanitization for Slack REST request payloads.
 *
 * Feed params are partial-update friendly: only keys present in the request
 * are returned, so toggling `enabled` never overwrites the rest of the config.
 */
class SlackRequestSanitizer
{
    /** Same limit as the `name` column; the editor input stops at it too. */
    private const MAX_FEED_NAME_LENGTH = 255;

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    public static function sanitizeFeedParams(array $params, ?int $maxChannels = null): array
    {
        $sanitized = self::sanitizeOptionalStringKeys($params);

        if (array_key_exists('name', $params)) {
            $sanitized['name'] = self::sanitizeFeedName($params['name']);
        }

        $smartLogic = self::firstPresentParam($params, ['smart_logic', 'smartLogic']);
        $sanitizedSmartLogic = $smartLogic !== null ? self::sanitizeSmartLogic($smartLogic) : null;
        if ($sanitizedSmartLogic !== null) {
            $sanitized['smartLogic'] = $sanitizedSmartLogic;
        }

        $messageTitle = self::sanitizeOptionalMessageTitle($params);
        if ($messageTitle !== null) {
            $sanitized['messageTitle'] = $messageTitle;
        }

        if (array_key_exists('fields', $params)) {
            $sanitized['fields'] = self::sanitizeFeedFields($params['fields']);
        }

        $channels = self::firstPresentParam($params, ['channels']);
        if ($channels !== null) {
            $sanitized['channels'] = self::sanitizeFeedChannels($channels, $maxChannels);
        }

        if (array_key_exists('enabled', $params)) {
            $sanitized['enabled'] = Sanitizer::sanitizeBool($params['enabled']);
        }

        return $sanitized;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @throws InvalidArgumentException
     */
    public static function sanitizeFormId(array $params): int
    {
        try {
            return Sanitizer::sanitizePositiveIntParam($params['form_id'] ?? $params['formId'] ?? 0);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidArgumentException(BackendStrings::getExceptionStrings()['invalid_form_id']);
        }
    }

    /**
     * @param mixed $value
     *
     * @throws InvalidArgumentException
     */
    public static function sanitizeFeedId($value): int
    {
        try {
            return Sanitizer::sanitizePositiveIntParam($value);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidArgumentException(BackendStrings::getSlackStrings()['slack_feed_not_found']);
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array{
     *     access_token: string,
     *     team_id: string,
     *     team_name: string,
     *     bot_user_id: string,
     *     callback_url: string,
     *     state: string
     * }
     */
    public static function sanitizeTokenDeliveryParams(array $params): array
    {
        $callbackUrl = $params['callback_url'] ?? null;

        return [
            'access_token' => self::sanitizeTokenDeliveryString($params['access_token'] ?? null),
            'team_id'      => self::sanitizeTokenDeliveryString($params['team_id'] ?? null),
            'team_name'    => self::sanitizeTokenDeliveryString($params['team_name'] ?? null),
            'bot_user_id'  => self::sanitizeTokenDeliveryString($params['bot_user_id'] ?? null),
            'callback_url' => is_string($callbackUrl) ? esc_url_raw($callbackUrl) : '',
            'state'        => self::sanitizeTokenDeliveryString($params['state'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, string>
     *
     * @throws InvalidArgumentException
     */
    private static function sanitizeOptionalStringKeys(array $params): array
    {
        $sanitized = [];
        $stringKeys = [
            'channelId'   => ['channel_id', 'channelId'],
            'channelName' => ['channel_name', 'channelName'],
        ];

        foreach ($stringKeys as $target => $sources) {
            $value = self::firstPresentParam($params, $sources);
            if ($value === null) {
                continue;
            }

            $sanitized[$target] = self::requireStringParam($value);
        }

        return $sanitized;
    }

    /**
     * @param mixed $name
     *
     * @throws InvalidArgumentException
     */
    private static function sanitizeFeedName($name): string
    {
        $feedName = mb_substr(trim(self::requireStringParam($name)), 0, self::MAX_FEED_NAME_LENGTH);
        if ($feedName === '') {
            throw new InvalidArgumentException(BackendStrings::getSlackStrings()['slack_feed_name_required']);
        }

        return $feedName;
    }

    /**
     * Null while Pro does not allow smart logic: the rules are then left out of
     * the save, so rules stored earlier are kept instead of being overwritten.
     *
     * @param mixed $smartLogic
     * @return array<string, mixed>|null
     */
    private static function sanitizeSmartLogic($smartLogic): ?array
    {
        /**
         * Whether Slack feed smart logic may be persisted.
         * Closed by default; Pro enables when licensed.
         *
         * @param bool $allowed
         */
        if (!apply_filters('ivyforms/slack/allow_smart_logic', false)) {
            return null;
        }

        return Sanitizer::sanitizeNotificationSmartLogic($smartLogic);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @throws InvalidArgumentException
     */
    private static function sanitizeOptionalMessageTitle(array $params): ?string
    {
        $title = self::firstPresentParam($params, ['message_title', 'messageTitle']);
        if ($title === null) {
            return null;
        }

        return HtmlSanitizer::sanitizeEditorContent(self::requireStringParam($title));
    }

    /**
     * @param array<string, mixed> $params
     * @param array<int, string> $sources
     * @return mixed|null
     */
    private static function firstPresentParam(array $params, array $sources)
    {
        foreach ($sources as $source) {
            if (array_key_exists($source, $params)) {
                return $params[$source];
            }
        }

        return null;
    }

    /**
     * @param mixed $value
     *
     * @throws InvalidArgumentException
     */
    private static function requireStringParam($value): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException(
                BackendStrings::getCommonStrings()['invalid_request_data']
            );
        }

        return (string) Sanitizer::sanitizeText($value);
    }

    /**
     * @param mixed $fields
     * @return array<int, string>
     *
     * @throws InvalidArgumentException
     */
    private static function sanitizeFeedFields($fields): array
    {
        if (!is_array($fields)) {
            throw new InvalidArgumentException(
                BackendStrings::getCommonStrings()['invalid_request_data']
            );
        }

        $sanitized = [];
        foreach ($fields as $field) {
            if (!is_scalar($field)) {
                continue;
            }

            $value = (string) Sanitizer::sanitizeText((string) $field);
            if ($value !== '') {
                $sanitized[] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * @param mixed $channels
     * @return array<int, array{id: string, name: string, type?: string}>
     *
     * @throws InvalidArgumentException
     */
    private static function sanitizeFeedChannels($channels, ?int $maxChannels = null): array
    {
        if (!is_array($channels)) {
            throw new InvalidArgumentException(
                BackendStrings::getCommonStrings()['invalid_request_data']
            );
        }

        $sanitized = [];
        foreach ($channels as $channel) {
            if (!is_array($channel)) {
                continue;
            }

            $id = (string) Sanitizer::sanitizeText((string) ($channel['id'] ?? ''));
            if ($id === '') {
                continue;
            }

            $entry = [
                'id'   => $id,
                'name' => (string) Sanitizer::sanitizeText((string) ($channel['name'] ?? '')),
            ];

            $type = (string) Sanitizer::sanitizeText((string) ($channel['type'] ?? ''));
            if ($type !== '') {
                $entry['type'] = $type;
            }

            $sanitized[] = $entry;
        }

        if ($maxChannels !== null && count($sanitized) > $maxChannels) {
            throw new InvalidArgumentException(
                BackendStrings::getSlackStrings()['slack_channel_limit_reached']
            );
        }

        return $sanitized;
    }

    /**
     * @param mixed $value
     */
    private static function sanitizeTokenDeliveryString($value): string
    {
        if (!is_string($value)) {
            return '';
        }

        return (string) Sanitizer::sanitizeText($value);
    }
}
