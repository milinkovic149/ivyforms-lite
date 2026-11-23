<?php

/**
 * @copyright © Melograno Venture Studio. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

namespace IvyForms\Services\Shortcode\Skeleton;

/**
 * Resolves the style settings that change the geometry of the rendered form.
 *
 * Values follow the frontend generator (formStyleGenerator.ts): advanced mode wins,
 * then quick mode, then the generator default. Every value is validated as a CSS
 * length before it is printed.
 */
class SkeletonStyleResolver
{
    private const LENGTH_PATTERN = '/^\d+(\.\d+)?(px|rem|em|%|vh|vw)?$/';

    /**
     * @var array<string, mixed>
     */
    private array $styleSettings;

    /**
     * Form styles are on and the generator emits layout CSS (classic forms only).
     */
    private bool $stylesEnabled;

    /**
     * Styles are off: a reset stylesheet hands typography to the theme.
     */
    private bool $resetMode;

    /**
     * @param array<string, mixed> $styleSettings
     * @param string $formType
     */
    public function __construct(array $styleSettings, string $formType)
    {
        $this->styleSettings = $styleSettings;
        $this->resetMode = ($styleSettings['stylesEnabled'] ?? true) === false;
        $this->stylesEnabled = !$this->resetMode && ($formType === '' || $formType === 'classic');
    }

    public function isResetMode(): bool
    {
        return $this->resetMode;
    }

    /**
     * Gap between form rows (generated `form { gap }`); none without generated CSS.
     */
    public function getVerticalSpacing(): string
    {
        if (!$this->stylesEnabled) {
            return '0px';
        }

        return $this->resolve(['advancedMode', 'general', 'verticalSpacing'], ['quickMode', 'verticalSpacing'], '16px');
    }

    /**
     * Gap between fields in a row; FormRender applies it inline even without styles.
     */
    public function getHorizontalSpacing(): string
    {
        return $this->resolve(
            ['advancedMode', 'general', 'horizontalSpacing'],
            ['quickMode', 'horizontalSpacing'],
            '12px'
        );
    }

    /**
     * Height of a single-line input: min 40px, grows with field padding and border.
     *
     * @param int $innerHeight Height of the input without padding and border (the phone input is taller)
     */
    public function getInputHeight(int $innerHeight = 30): string
    {
        if ($this->resetMode) {
            // The reset strips padding and borders, leaving Element Plus' 32px line box.
            return '32px';
        }
        if (!$this->stylesEnabled) {
            return '40px';
        }

        return 'max(40px, calc(' . $this->getFieldBorderWidth() . ' * 2 + ' . $this->getFieldPaddingY() . ' * 2 + '
            . $innerHeight . 'px))';
    }

    /**
     * Vertical label padding; the generator applies it to every nested label element.
     */
    public function getLabelPaddingY(): string
    {
        if (!$this->stylesEnabled) {
            return '0px';
        }

        return $this->resolve(['advancedMode', 'fieldLabels', 'label', 'paddingTopBottom'], [], '0px');
    }

    /**
     * Textarea height: 24px rows plus padding and border (22px rows without chrome in reset mode).
     */
    public function getTextareaHeight(int $rows): string
    {
        if ($this->resetMode) {
            return ($rows * 22) . 'px';
        }
        if (!$this->stylesEnabled) {
            return ($rows * 24 + 2) . 'px';
        }

        return 'calc(' . ($rows * 24) . 'px + ' . $this->getFieldBorderWidth() . ' * 2 + '
            . $this->getFieldPaddingY() . ' * 2)';
    }

    public function getRatingIconSize(): string
    {
        $size = $this->resolve(['advancedMode', 'rating', 'iconSize'], [], '24px');

        return $this->stylesEnabled ? $size : '24px';
    }

    /**
     * Inline style for the submit / navigation button placeholder.
     */
    public function getButtonStyle(int $labelLength): string
    {
        $normal = ['advancedMode', 'buttonStates', 'normal'];
        $paddingY = '8px';
        $paddingX = '16px';
        $fontSize = '16px';
        $borderWidth = '1px';
        $width = '';
        $height = '';

        if ($this->stylesEnabled) {
            $paddingY = $this->resolve(array_merge($normal, ['paddingTopBottom']), [], $paddingY);
            $paddingX = $this->resolve(array_merge($normal, ['paddingLeftRight']), [], $paddingX);
            $fontSize = $this->resolve(array_merge($normal, ['fontSize']), [], $fontSize);
            $borderWidth = $this->resolve(
                array_merge($normal, ['borderWidth']),
                ['quickMode', 'buttonBorderWidth'],
                $borderWidth
            );
            $width = $this->resolve(array_merge($normal, ['width']), [], '');
            $height = $this->resolve(array_merge($normal, ['height']), [], '');
        }

        if ($this->resetMode) {
            // Native button look (reset stylesheet); padding and border come from the skeleton CSS.
            return 'width: calc(' . max($labelLength, 1) . 'ch + 16px);';
        }

        $styles = [
            'padding: ' . $paddingY . ' ' . $paddingX,
            'font-size: ' . $fontSize,
            'border-width: ' . $borderWidth,
        ];
        // The button text is not rendered, so its width is estimated from the label length.
        $styles[] = 'width: '
            . ($width !== '' ? $width : 'calc(' . max($labelLength, 1) . 'ch + ' . $paddingX . ' * 2)');
        if ($height !== '') {
            $styles[] = 'height: ' . $height;
        }

        return implode('; ', $styles) . ';';
    }

    private function getFieldPaddingY(): string
    {
        return $this->resolve(
            ['advancedMode', 'fieldStates', 'default', 'paddingTopBottom'],
            ['quickMode', 'fieldPaddingTopBottom'],
            '0px'
        );
    }

    private function getFieldBorderWidth(): string
    {
        return $this->resolve(
            ['advancedMode', 'fieldStates', 'default', 'borderWidth'],
            ['quickMode', 'fieldBorderWidth'],
            '1px'
        );
    }

    /**
     * @param string[] $advancedPath
     * @param string[] $quickPath
     * @param string $default
     * @return string
     */
    private function resolve(array $advancedPath, array $quickPath, string $default): string
    {
        foreach ([$advancedPath, $quickPath] as $path) {
            if (empty($path)) {
                continue;
            }
            $length = $this->toCssLength($this->getPath($path));
            if ($length !== null) {
                return $length;
            }
        }

        return $default;
    }

    /**
     * @param string[] $path
     * @return mixed
     */
    private function getPath(array $path)
    {
        $value = $this->styleSettings;
        foreach ($path as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * @param mixed $value
     */
    private function toCssLength($value): ?string
    {
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }
        if (!is_string($value)) {
            return null;
        }

        $value = strtolower(trim($value));
        if ($value === '' || !preg_match(self::LENGTH_PATTERN, $value)) {
            return null;
        }

        return is_numeric($value) ? $value . 'px' : $value;
    }
}
