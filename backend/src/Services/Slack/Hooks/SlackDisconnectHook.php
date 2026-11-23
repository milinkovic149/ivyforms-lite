<?php

namespace IvyForms\Services\Slack\Hooks;

use IvyForms\Services\Slack\SlackApiClient;

class SlackDisconnectHook
{
    private SlackApiClient $apiClient;

    public function __construct(SlackApiClient $apiClient)
    {
        $this->apiClient = $apiClient;
    }

    public function register(): void
    {
        add_action('ivyforms/slack/disconnected', [$this, 'onDisconnected']);
    }

    public function onDisconnected(): void
    {
        $this->apiClient->clearCaches();
    }
}
