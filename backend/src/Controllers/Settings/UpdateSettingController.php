<?php

/**
 * @copyright © Melograno Venture Studio. All rights reserved.
 * @licence   See COPYING.md for license details.
 */

namespace IvyForms\Controllers\Settings;

use IvyForms\Common\Exceptions\ForbiddenException;
use IvyForms\Common\Exceptions\InvalidArgumentException;
use IvyForms\Common\Sanitizer\Sanitizer;
use IvyForms\Controllers\Controller;
use IvyForms\Services\Settings\ClientSettingsService;
use IvyForms\Services\Translations\BackendStrings;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Class UpdateSettingController
 *
 * @package IvyForms\Controllers\Settings
 */
class UpdateSettingController extends Controller
{
    private ClientSettingsService $clientSettingsService;

    public function __construct(ClientSettingsService $clientSettingsService)
    {
        $this->clientSettingsService = $clientSettingsService;
    }

    /**
     * @param WP_REST_Request $data
     *
     * @return WP_REST_Response
     *
     * @throws InvalidArgumentException
     * @throws ForbiddenException
     */
    public function handle(WP_REST_Request $data): WP_REST_Response
    {
        // Verify the nonce
        Sanitizer::verifyNonce($data->get_header('X-WP-Nonce'));

        // Check if settings parameters are provided
        if (empty($data->get_params())) {
            throw new InvalidArgumentException(
                BackendStrings::getCommonStrings()['invalid_request_data']
            );
        }

        $settingsCategory = Sanitizer::sanitizeText($data->get_params()['settingsCategory']);
        $settingsOption   = Sanitizer::sanitizeText($data->get_params()['settingsOption']);
        $settingsValue    = Sanitizer::sanitizeSettingsFields($data->get_params()['settingsValue']);

        if (empty($settingsCategory) || empty($settingsOption)) {
            throw new InvalidArgumentException(
                BackendStrings::getSettingsStrings()['settings_category_or_option_missing']
            );
        }

        return new WP_REST_Response([
            'message' => BackendStrings::getSettingsStrings()['settings_option_updated'],
            'data'    => [
                'value' => $this->clientSettingsService->update($settingsCategory, $settingsOption, $settingsValue),
            ]
        ], 200);
    }
}
