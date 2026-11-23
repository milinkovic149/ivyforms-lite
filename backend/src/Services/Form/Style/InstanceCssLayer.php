<?php

/**
 * @copyright © Melograno Venture Studio. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

namespace IvyForms\Services\Form\Style;

/**
 * Class InstanceCssLayer
 *
 * Builds the stylesheet layer of a single embed instance (one Gutenberg block) on top of the
 * per-form layer that is already on the page.
 *
 * The layer holds only the declarations the instance settings change: both stylesheets come from
 * the same generator, and every declaration the per-form stylesheet already emits with the same
 * value is dropped. Overriding the button color therefore emits the button color rules and
 * nothing else, so theme and custom CSS keep working for every property the block did not touch.
 *
 * Every kept selector gets the instance prefix (higher specificity) and every kept declaration is
 * forced to `!important`, so it wins over the per-form layer, which already uses `!important`,
 * regardless of injection order. Popper selectors, which render in a body-level portal outside
 * the instance wrapper, are narrowed with the instance teleport class instead of the prefix.
 *
 * Mirror: `frontend/src/utils/form-styles/instanceCssLayer.ts`.
 *
 * @package IvyForms\Services\Form\Style
 */
final class InstanceCssLayer
{
    /**
     * The per-form popper class (`ivyforms-form-popper-{token}`) that portal selectors start from.
     *
     * @var string
     */
    private const FORM_POPPER_CLASS_PATTERN = '/\.ivyforms-form-popper-[A-Za-z0-9_-]+/';

    /**
     * Build the instance layer.
     *
     * @param string $baseCss Per-form stylesheet the page already carries
     * @param string $instanceCss Stylesheet generated from the instance settings, unscoped
     * @param string $scopePrefix Selector of the instance wrapper, e.g. `#ivyforms-block-3`
     * @param string $teleportScopeClass Class the instance adds to its portal roots; empty when
     *                                   the instance has none, which leaves popper rules out
     * @return string
     */
    public static function build(
        string $baseCss,
        string $instanceCss,
        string $scopePrefix,
        string $teleportScopeClass
    ): string {
        $scopePrefix = trim($scopePrefix);

        if ('' === $scopePrefix || '' === trim($instanceCss)) {
            return '';
        }

        $baseDeclarations = self::indexDeclarations(FlatCssParser::parse($baseCss));
        $output = [];

        foreach (FlatCssParser::parse($instanceCss) as $rule) {
            $declarations = [];

            foreach ($rule['declarations'] as $declaration) {
                if (isset($baseDeclarations[self::declarationKey($rule, $declaration)])) {
                    continue;
                }

                $declarations[] = '  ' . self::forceImportant($declaration) . ';';
            }

            if ([] === $declarations) {
                continue;
            }

            $selectors = self::scopeSelectorList($rule['selectors'], $scopePrefix, $teleportScopeClass);

            if ('' === $selectors) {
                continue;
            }

            $output[] = self::wrapInContext(
                $selectors . " {\n" . implode("\n", $declarations) . "\n}",
                $rule['context']
            );
        }

        return implode("\n", $output);
    }

    /**
     * @param array<int, array{context: array<int, string>, selectors: string, declarations: array<int, string>}> $rules
     * @return array<string, true>
     */
    private static function indexDeclarations(array $rules): array
    {
        $index = [];

        foreach ($rules as $rule) {
            foreach ($rule['declarations'] as $declaration) {
                $index[self::declarationKey($rule, $declaration)] = true;
            }
        }

        return $index;
    }

    /**
     * Identity of one declaration: where it applies (at-rule chain + selectors) and what it sets.
     * `!important` is ignored, so a declaration only differs from the per-form one by its value.
     *
     * @param array{context: array<int, string>, selectors: string, declarations: array<int, string>} $rule
     * @param string $declaration
     * @return string
     */
    private static function declarationKey(array $rule, string $declaration): string
    {
        $declaration = trim((string) preg_replace('/\s*!\s*important$/i', '', $declaration));

        return implode("\x1F", $rule['context']) . "\x1E" . $rule['selectors'] . "\x1E" . $declaration;
    }

    /**
     * Prefix every selector of a comma separated selector list.
     *
     * Popper selectors cannot be prefixed — the popper is not inside the instance wrapper — so the
     * per-form popper class is narrowed with the instance teleport class instead. Without a
     * teleport class the popper selector cannot be scoped and is dropped.
     *
     * @param string $selectorList
     * @param string $scopePrefix
     * @param string $teleportScopeClass
     * @return string
     */
    private static function scopeSelectorList(
        string $selectorList,
        string $scopePrefix,
        string $teleportScopeClass
    ): string {
        $scoped = [];

        foreach (explode(',', $selectorList) as $selector) {
            if (preg_match(self::FORM_POPPER_CLASS_PATTERN, $selector) !== 1) {
                $scoped[] = $scopePrefix . ' ' . $selector;
                continue;
            }

            if ('' !== $teleportScopeClass) {
                $scoped[] = (string) preg_replace(
                    self::FORM_POPPER_CLASS_PATTERN,
                    '$0.' . $teleportScopeClass,
                    $selector,
                    1
                );
            }
        }

        return implode(',', $scoped);
    }

    /**
     * @param string $declaration
     * @return string
     */
    private static function forceImportant(string $declaration): string
    {
        return preg_match('/!\s*important$/i', $declaration) === 1 ? $declaration : $declaration . ' !important';
    }

    /**
     * @param string $rule
     * @param array<int, string> $context At-rule preludes, outermost first
     * @return string
     */
    private static function wrapInContext(string $rule, array $context): string
    {
        foreach (array_reverse($context) as $atRule) {
            $rule = $atRule . " {\n" . $rule . "\n}";
        }

        return $rule;
    }
}
