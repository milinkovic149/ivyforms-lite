<?php

namespace IvyForms\Services\Slack;

use IvyForms\Repository\Slack\SlackFeedRepositoryInterface;
use IvyForms\ValueObjects\Slack\SlackDeliveryResult;

/**
 * Sends Slack notifications in the background.
 *
 * A job is a non-autoloaded option holding the deliveries (feed, channel,
 * message) plus a single WP-Cron event pointing at it. Options never expire,
 * so a job survives until cron runs, and it is claimed by deleting the option:
 * only the runner whose delete removed the row sends it.
 */
class SlackNotificationDispatcher
{
    public const DISPATCH_HOOK = 'ivyforms/slack/dispatch_notification';
    public const JOB_OPTION_PREFIX = 'ivyforms_slack_job_';

    private const MAX_DISPATCH_ATTEMPTS = 3;

    /**
     * Deliveries sent per cron run; the rest go to a follow-up job so a large
     * fan-out (many feeds x channels) never runs into the PHP time limit.
     */
    private const DELIVERIES_PER_RUN = 20;

    private SlackFeedRepositoryInterface $repository;
    private SlackApiClient $apiClient;

    public function __construct(
        SlackFeedRepositoryInterface $repository,
        SlackApiClient $apiClient
    ) {
        $this->repository = $repository;
        $this->apiClient = $apiClient;
    }

    /**
     * @param array<int, array{feedId: int, channelId: string, message: string, blocks?: array<int, mixed>}> $deliveries
     */
    public function queue(array $deliveries): bool
    {
        $deliveries = $this->filterValidDeliveries($deliveries);
        if ($deliveries === []) {
            return false;
        }

        return $this->scheduleJob($deliveries, 0);
    }

    public static function spawnWorker(): void
    {
        if (function_exists('spawn_cron')) {
            spawn_cron();
        }
    }

    /**
     * @param mixed $payload
     */
    public function handleScheduledDispatch($payload): void
    {
        $deliveries = $this->claimJob($payload);
        if ($deliveries === null) {
            return;
        }

        $retries = [];
        $retryDelayInSeconds = 0;

        foreach (array_slice($deliveries, 0, self::DELIVERIES_PER_RUN) as $delivery) {
            $result = $this->deliver($delivery);
            if ($result === null || $result->ok) {
                continue;
            }

            $attempts = $delivery['attempts'] + 1;

            // Permanent Slack rejections (invalid channel, revoked auth, ...)
            // cannot succeed on retry.
            if (!$result->retryable || $attempts >= self::MAX_DISPATCH_ATTEMPTS) {
                $this->logDispatchFailure(sprintf(
                    'feed #%d channel %s failed after %d attempt(s): %s',
                    $delivery['feedId'],
                    $delivery['channelId'],
                    $attempts,
                    $result->error
                ));
                continue;
            }

            $delivery['attempts'] = $attempts;
            $retries[] = $delivery;

            // Linear backoff, honoring Slack's Retry-After hint when it is longer.
            $retryDelayInSeconds = max(
                $retryDelayInSeconds,
                $attempts * MINUTE_IN_SECONDS,
                (int) ($result->retryAfterSeconds ?? 0)
            );
        }

        $remaining = array_slice($deliveries, self::DELIVERIES_PER_RUN);
        if ($remaining !== []) {
            $this->scheduleJob($remaining, 0);
        }

        if ($retries !== []) {
            $this->scheduleJob($retries, $retryDelayInSeconds);
        }
    }

    /**
     * Remove queued jobs and their cron events (plugin data deletion).
     */
    public static function deleteAllJobs(): void
    {
        global $wpdb;

        wp_unschedule_hook(self::DISPATCH_HOOK);

        $jobKeys = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like(self::JOB_OPTION_PREFIX) . '%'
            )
        );

        foreach ((array) $jobKeys as $jobKey) {
            delete_option((string) $jobKey);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $deliveries
     */
    private function scheduleJob(array $deliveries, int $delayInSeconds): bool
    {
        $jobKey = self::JOB_OPTION_PREFIX . wp_generate_password(20, false);

        if (!add_option($jobKey, ['deliveries' => $deliveries], '', 'no')) {
            return false;
        }

        $scheduled = wp_schedule_single_event(
            time() + $delayInSeconds,
            self::DISPATCH_HOOK,
            [
                [
                    'jobKey' => $jobKey,
                ],
            ]
        );

        if ($scheduled !== true) {
            delete_option($jobKey);
            $this->logDispatchFailure('could not schedule the dispatch event');

            return false;
        }

        return true;
    }

    /**
     * @param mixed $payload
     * @return array<int, array{
     *     feedId: int,
     *     channelId: string,
     *     message: string,
     *     blocks: array<int, mixed>,
     *     attempts: int
     * }>|null
     */
    private function claimJob($payload): ?array
    {
        $jobKey = is_array($payload) ? (string) ($payload['jobKey'] ?? '') : '';
        if (strpos($jobKey, self::JOB_OPTION_PREFIX) !== 0) {
            return null;
        }

        $job = get_option($jobKey, null);

        // delete_option() returns false for every runner but the one whose
        // DELETE removed the row, so concurrent cron runs cannot send twice.
        if (!is_array($job) || !delete_option($jobKey)) {
            return null;
        }

        return $this->filterValidDeliveries((array) ($job['deliveries'] ?? []));
    }

    /**
     * @param array<int, mixed> $deliveries
     * @return array<int, array{
     *     feedId: int,
     *     channelId: string,
     *     message: string,
     *     blocks: array<int, mixed>,
     *     attempts: int
     * }>
     */
    private function filterValidDeliveries(array $deliveries): array
    {
        $valid = [];

        foreach ($deliveries as $delivery) {
            if (!is_array($delivery)) {
                continue;
            }

            $feedId = (int) ($delivery['feedId'] ?? 0);
            $channelId = (string) ($delivery['channelId'] ?? '');
            $message = (string) ($delivery['message'] ?? '');

            if ($feedId <= 0 || $channelId === '' || $message === '') {
                continue;
            }

            // Jobs queued before blocks existed have none and go out as text.
            $blocks = $delivery['blocks'] ?? [];

            $valid[] = [
                'feedId'    => $feedId,
                'channelId' => $channelId,
                'message'   => $message,
                'blocks'    => is_array($blocks) ? array_values($blocks) : [],
                'attempts'  => (int) ($delivery['attempts'] ?? 0),
            ];
        }

        return $valid;
    }

    /**
     * @param array{feedId: int, channelId: string, message: string, blocks: array<int, mixed>, attempts: int} $delivery
     * @return SlackDeliveryResult|null Null when the feed was deleted or disabled since queueing.
     */
    private function deliver(array $delivery): ?SlackDeliveryResult
    {
        try {
            $feed = $this->repository->getById($delivery['feedId']);
            if ($feed === null || !$feed->isEnabled()) {
                return null;
            }

            return $this->apiClient->postChannelMessage(
                $delivery['channelId'],
                $delivery['message'],
                $delivery['blocks']
            );
        } catch (\Throwable $exception) {
            return SlackDeliveryResult::transientFailure($exception->getMessage());
        }
    }

    private function logDispatchFailure(string $message): void
    {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        error_log('IvyForms: Slack notification dispatch failed: ' . $message);
    }
}
