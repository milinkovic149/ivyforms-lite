<?php

/**
 * Hooks of features that ship behind a release flag.
 *
 * @package IvyForms
 */

namespace IvyForms\Plugin;

use IvyForms\Common\Features\Features;
use IvyForms\Services\Multilingual\MultilingualHooks;
use IvyForms\Services\Shortcode\Helpers\ShortcodeTranslationHelper;
use IvyForms\Services\Shortcode\ShortcodeService;
use IvyForms\Services\Slack\Hooks\SlackDisconnectHook;
use IvyForms\Services\Slack\Hooks\SlackFormDeleteHook;
use IvyForms\Services\Slack\Hooks\SlackSubmissionHook;
use IvyForms\Vendor\DI\Container;
use IvyForms\Vendor\DI\DependencyException;
use IvyForms\Vendor\DI\NotFoundException;

/**
 * Registers flagged features' hooks outside Plugin, which keeps Plugin within the PHPMD coupling limit.
 */
class FeatureHooks
{
    private Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * Hooks added while the plugin boots (before plugins_loaded).
     */
    public static function registerBootHooks(): void
    {
        if (Features::enabled('multilang-support')) {
            MultilingualHooks::registerBootHooks();
        }
    }

    /**
     * Register Slack submission, disconnect and form-delete hooks.
     */
    public function registerSlack(): void
    {
        if (!Features::enabled('slack')) {
            return;
        }

        try {
            /** @var SlackSubmissionHook $slackSubmissionHook */
            $slackSubmissionHook = $this->container->get(SlackSubmissionHook::class);
            $slackSubmissionHook->register();

            /** @var SlackDisconnectHook $slackDisconnectHook */
            $slackDisconnectHook = $this->container->get(SlackDisconnectHook::class);
            $slackDisconnectHook->register();

            /** @var SlackFormDeleteHook $slackFormDeleteHook */
            $slackFormDeleteHook = $this->container->get(SlackFormDeleteHook::class);
            $slackFormDeleteHook->register();
        } catch (DependencyException | NotFoundException $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Slack hooks error: ' . $e->getMessage());
            }
        }
    }

    /**
     * Register multilingual snapshot and string-registration hooks.
     */
    public function registerMultilingual(): void
    {
        if (!Features::enabled('multilang-support')) {
            return;
        }

        try {
            /** @var MultilingualHooks $hooks */
            $hooks = $this->container->get(MultilingualHooks::class);
            $hooks->register();
        } catch (DependencyException | NotFoundException $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Multilingual service error: ' . $e->getMessage());
            }
        }

        try {
            /** @var ShortcodeTranslationHelper $translationHelper */
            $translationHelper = $this->container->get(ShortcodeTranslationHelper::class);
            ShortcodeService::setTranslationHelper($translationHelper);
        } catch (DependencyException | NotFoundException $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('IvyForms multilingual render: ' . $e->getMessage());
            }
        }
    }
}
