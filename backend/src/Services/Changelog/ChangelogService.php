<?php

namespace IvyForms\Services\Changelog;

class ChangelogService
{
    /**
     * Get changelog data with translated strings.
     *
     * @return array{
     *     version: string,
     *     release_date: string,
     *     features: array<array{text: string}>,
     *     improvements: array<array{text: string}>,
     *     bugfixes: array<array{text: string}>
     * }
     */
    public static function getChangelogData(): array
    {
        $changelogData = [
            'version' => IVYFORMS_VERSION,
            'release_date' => '2026-11-10',
            'features' => [
                ['text' => __('Added a Slack integration that sends new form submissions to Slack.', 'ivyforms')],
                [
                    'text' => __(
                        'Added a What\'s New page with the latest IvyForms updates, tutorials, and blog posts.',
                        'ivyforms'
                    ),
                ],
                ['text' => __('Added WPML and Polylang support for multilingual forms.', 'ivyforms')],
            ],
            'improvements' => [],
            'bugfixes' => [
                ['text' => __('Fixed a security issue in the payment integration connection flow.', 'ivyforms')],
                [
                    'text' => __(
                        'Fixed the Gutenberg block preview staying on the loading skeleton after switching forms.',
                        'ivyforms'
                    ),
                ],
                ['text' => __('Fixed sitemaps failing with a memory error on sites with many forms.', 'ivyforms')],
                ['text' => __('Fixed CC and BCC recipients not being saved in notification settings.', 'ivyforms')],
            ],
        ];

        /**
         * Filter changelog data to allow Pro plugin to add its own changelog entries
         *
         * @param array $changelogData The changelog data array
         * @return array Modified changelog data
         */
        return apply_filters('ivyforms/changelog/get_data', $changelogData);
    }
}
