<?php

/**
 * @copyright © Melograno Venture Studio. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

namespace IvyForms\Services\WhatsNew;

/**
 * JSON GET helper for ivyforms-middleware What's New.
 */
class WhatsNewHttpClient
{
    /**
     * @return array<int|string, mixed>|null
     */
    public function getJson(string $url, int $timeout = 30): ?array
    {
        $response = wp_remote_get($url, [
            'timeout' => $timeout,
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'IvyForms/' . IVYFORMS_VERSION,
            ],
        ]);

        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);

        return is_array($decoded) ? $decoded : null;
    }
}
