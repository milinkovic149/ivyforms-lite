<?php

namespace IvyForms\Services\Slack;

/**
 * Normalizes Slack conversations.list / users.list payloads for the channel picker.
 */
class SlackChannelNormalizer
{
    /**
     * @param array<int, array<string, mixed>>      $conversations
     * @param array<int, array<string, mixed>>|null $users
     * @return array<int, array{id: string, name: string, type: string}>
     */
    public function buildChannelPickerEntries(array $conversations, ?array $users): array
    {
        $userNames = is_array($users) ? $this->mapUserDisplayNames($users) : [];

        $channels = [];
        $listedImUserIds = [];
        foreach ($conversations as $channel) {
            $normalized = $this->normalizeChannelEntry($channel, $userNames);
            if ($normalized === null) {
                continue;
            }

            if ($normalized['type'] === 'im' && !empty($channel['user'])) {
                $listedImUserIds[(string) $channel['user']] = true;
            }

            $channels[] = $normalized;
        }

        if (!is_array($users)) {
            return $channels;
        }

        return array_merge(
            $channels,
            $this->normalizeUsersAsDirectMessages($users, $listedImUserIds)
        );
    }

    /**
     * @param array<string, mixed>  $channel
     * @param array<string, string> $userNames
     * @return array{id: string, name: string, type: string}|null
     */
    public function normalizeChannelEntry(array $channel, array $userNames): ?array
    {
        $id = isset($channel['id']) ? (string) $channel['id'] : '';
        if ($id === '') {
            return null;
        }

        $isIm = !empty($channel['is_im']);
        $name = isset($channel['name']) ? (string) $channel['name'] : '';
        if ($name === '' && $isIm && !empty($channel['user'])) {
            $userId = (string) $channel['user'];
            $name = $userNames[$userId] ?? $userId;
        }

        if ($name === '') {
            return null;
        }

        return [
            'id'   => $id,
            'name' => $name,
            'type' => $isIm ? 'im' : 'channel',
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $users
     * @param array<string, true>              $excludeUserIds
     * @return array<int, array{id: string, name: string, type: string}>
     */
    public function normalizeUsersAsDirectMessages(array $users, array $excludeUserIds): array
    {
        $directMessages = [];
        foreach ($users as $user) {
            if (!$this->isSelectableDirectMessageUser($user)) {
                continue;
            }

            $userId = (string) $user['id'];
            if (isset($excludeUserIds[$userId])) {
                continue;
            }

            $directMessages[] = [
                'id'   => $userId,
                'name' => $this->displayNameFromSlackUser($user, $userId),
                'type' => 'im',
            ];
        }

        return $directMessages;
    }

    /**
     * @param array<int, array<string, mixed>> $users
     * @return array<string, string>
     */
    public function mapUserDisplayNames(array $users): array
    {
        $names = [];
        foreach ($users as $user) {
            if (empty($user['id']) || !empty($user['deleted'])) {
                continue;
            }

            $userId = (string) $user['id'];
            $names[$userId] = $this->displayNameFromSlackUser($user, $userId);
        }

        return $names;
    }

    /**
     * @param array<string, mixed> $user
     */
    private function isSelectableDirectMessageUser(array $user): bool
    {
        if (empty($user['id']) || !empty($user['deleted']) || !empty($user['is_bot'])) {
            return false;
        }

        // Slackbot and apps are not useful DM notification targets.
        if ((string) $user['id'] === 'USLACKBOT' || !empty($user['is_app_user'])) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $user
     */
    private function displayNameFromSlackUser(array $user, string $fallback): string
    {
        $profile = is_array($user['profile'] ?? null) ? $user['profile'] : [];
        foreach (['display_name', 'real_name'] as $profileKey) {
            $value = (string) ($profile[$profileKey] ?? '');
            if ($value !== '') {
                return $value;
            }
        }

        $username = (string) ($user['name'] ?? '');

        return $username !== '' ? $username : $fallback;
    }
}
