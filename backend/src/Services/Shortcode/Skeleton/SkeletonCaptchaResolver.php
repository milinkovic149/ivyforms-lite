<?php

/**
 * @copyright © Melograno Venture Studio. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

namespace IvyForms\Services\Shortcode\Skeleton;

use IvyForms\Services\Shortcode\Helpers\ShortcodeHelper;
use Throwable;

/**
 * Final height of a captcha widget, from the same security config the frontend receives.
 */
class SkeletonCaptchaResolver
{
    /**
     * Heights of the checkbox widgets including the field's own margins.
     */
    private const WIDGET_HEIGHTS = ['recaptcha' => '136px', 'turnstile' => '83px', 'hcaptcha' => '96px'];

    /**
     * Invisible variants keep only their margins.
     */
    private const INVISIBLE_HEIGHTS = ['recaptcha' => '40px', 'hcaptcha' => '0px'];

    /**
     * Unconfigured captchas show a short warning alert instead.
     */
    private const ALERT_HEIGHT = '64px';

    /**
     * @var array<string, mixed>
     */
    private array $formData;

    /**
     * @var array<string, mixed>|null
     */
    private ?array $config = null;

    /**
     * @param array<string, mixed> $formData Entry of ShortcodeService::$formList
     */
    public function __construct(array $formData)
    {
        $this->formData = $formData;
    }

    public function getHeight(string $type): string
    {
        $config = $this->getConfig();
        $providerConfig = is_array($config[$type] ?? null) ? $config[$type] : null;
        if (empty($config['enabled']) || $providerConfig === null) {
            return self::ALERT_HEIGHT;
        }

        $captchaType = (string) ($providerConfig['type'] ?? '');
        $isInvisible = $captchaType === 'invisible' || ($type === 'recaptcha' && $captchaType === 'v3');
        if ($isInvisible && isset(self::INVISIBLE_HEIGHTS[$type])) {
            return self::INVISIBLE_HEIGHTS[$type];
        }

        return self::WIDGET_HEIGHTS[$type] ?? self::ALERT_HEIGHT;
    }

    /**
     * @return array<string, mixed>
     */
    private function getConfig(): array
    {
        if ($this->config !== null) {
            return $this->config;
        }

        try {
            $securityConfig = ShortcodeHelper::getSecurityService()->getFrontendSecurityConfig(
                ShortcodeHelper::getAllFormFields(['skeleton' => $this->formData])
            );
            $this->config = is_array($securityConfig['captcha'] ?? null) ? $securityConfig['captcha'] : [];
        } catch (Throwable $exception) {
            $this->config = [];
        }

        return $this->config;
    }
}
