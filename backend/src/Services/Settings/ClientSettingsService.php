<?php

/**
 * @copyright © Melograno Venture Studio. All rights reserved.
 * @licence   See COPYING.md for license details.
 */

namespace IvyForms\Services\Settings;

use IvyForms\Services\Usage\UsageTrackingService;

/**
 * Applies a single setting change requested from the admin UI.
 *
 * @package IvyForms\Services\Settings
 */
class ClientSettingsService
{
    private SettingsService $settingsService;
    private UsageTrackingService $usageTrackingService;

    public function __construct(
        SettingsService $settingsService,
        UsageTrackingService $usageTrackingService
    ) {
        $this->settingsService = $settingsService;
        $this->usageTrackingService = $usageTrackingService;
    }

    /**
     * Save a client-editable setting and return its stored value.
     *
     * @param mixed $settingValue
     *
     * @return mixed
     */
    public function update(string $settingCategoryKey, string $settingKey, $settingValue)
    {
        // Usage tracking is persisted by the collector, not in the settings option.
        if ($settingCategoryKey === 'general' && $settingKey === 'usageTrackingEnabled') {
            $updatedSettings = $this->usageTrackingService->updateSettings([
                'usageTrackingEnabled' => (bool) $settingValue,
            ]);

            return $updatedSettings['usageTrackingEnabled'];
        }

        $this->settingsService->setClientSetting($settingCategoryKey, $settingKey, $settingValue);

        return $this->settingsService->getSetting($settingCategoryKey, $settingKey);
    }
}
