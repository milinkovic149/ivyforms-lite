<?php

/**
 * @copyright © Melograno Venture Studio. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

namespace IvyForms\Services\Form\Style;

/**
 * Class FlatCssParser
 *
 * Splits a generated stylesheet into flat rules (selector list + declarations), for
 * {@see InstanceCssLayer}. Mirror: the parser part of `frontend/src/utils/form-styles/instanceCssLayer.ts`.
 *
 * @package IvyForms\Services\Form\Style
 */
final class FlatCssParser
{
    /**
     * Conditional group at-rules whose inner rules are parsed and keep the at-rule as context.
     * Any other at-rule (`@keyframes`, `@font-face`, …) is skipped.
     *
     * @var string
     */
    private const CONDITIONAL_AT_RULE_PATTERN = '/^@(?:media|supports|container)\b/i';

    /**
     * Split a stylesheet into flat rules, descending into conditional group at-rules.
     *
     * The built-in generators emit a flat rule list, but `ivyforms/style/filter/css_rules` can
     * add anything. `@media` / `@supports` / `@container` blocks are parsed recursively and their
     * rules remember the at-rule chain they belong to. Any other at-rule and any nested block is
     * skipped as a whole balanced block. Unbalanced input ends the scan.
     *
     * @param string $css
     * @param array<int, string> $context At-rule preludes enclosing $css, outermost first
     * @return array<int, array{context: array<int, string>, selectors: string, declarations: array<int, string>}>
     */
    public static function parse(string $css, array $context = []): array
    {
        $rules = [];
        $length = strlen($css);
        $prelude = '';
        $index = 0;

        while ($index < $length) {
            $character = $css[$index];

            if ('{' !== $character) {
                // A `;` ends a block-less at-rule (`@import …;`); a stray `}` ends a malformed block.
                $prelude = (';' === $character || '}' === $character) ? '' : $prelude . $character;
                $index++;
                continue;
            }

            $blockEnd = self::findBlockEnd($css, $index);
            if (null === $blockEnd) {
                break;
            }

            $header = trim($prelude);
            $body = substr($css, $index + 1, $blockEnd - $index - 1);
            $prelude = '';
            $index = $blockEnd + 1;

            if (preg_match(self::CONDITIONAL_AT_RULE_PATTERN, $header) === 1) {
                $rules = array_merge($rules, self::parse($body, array_merge($context, [$header])));
                continue;
            }

            if (!self::isFlatRule($header, $body)) {
                continue;
            }

            $rules[] = [
                'context'      => $context,
                'selectors'    => self::normalizeSelectorList($header),
                'declarations' => self::splitDeclarations($body),
            ];
        }

        return $rules;
    }

    /**
     * Index of the `}` closing the block opened at $openIndex, or null when the input is unbalanced.
     *
     * @param string $css
     * @param int $openIndex
     * @return int|null
     */
    private static function findBlockEnd(string $css, int $openIndex): ?int
    {
        $depth = 0;
        $length = strlen($css);

        for ($index = $openIndex; $index < $length; $index++) {
            if ('{' === $css[$index]) {
                $depth++;
                continue;
            }

            if ('}' === $css[$index] && --$depth === 0) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Whether the block is a plain rule this class can scope.
     *
     * Selector lists are split on `,` and declaration lists on `;`. Inside parentheses those
     * characters belong to a functional construct instead (`:is(a, b)`, `url(data:…;base64,…)`),
     * which a flat split cannot represent, so such rules stay with the per-form layer.
     *
     * @param string $selectors
     * @param string $declarations
     * @return bool
     */
    private static function isFlatRule(string $selectors, string $declarations): bool
    {
        if ('' === $selectors || 0 === strpos($selectors, '@') || false !== strpos($declarations, '{')) {
            return false;
        }

        return !self::hasSeparatorInsideParentheses($selectors, ',')
            && !self::hasSeparatorInsideParentheses($declarations, ';');
    }

    /**
     * @param string $value
     * @param string $separator Single character
     * @return bool
     */
    private static function hasSeparatorInsideParentheses(string $value, string $separator): bool
    {
        $depth = 0;
        $length = strlen($value);

        for ($index = 0; $index < $length; $index++) {
            $character = $value[$index];

            if ('(' === $character) {
                $depth++;
                continue;
            }

            if (')' === $character) {
                $depth = max(0, $depth - 1);
                continue;
            }

            if ($depth > 0 && $separator === $character) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $selectorList
     * @return string
     */
    private static function normalizeSelectorList(string $selectorList): string
    {
        $selectors = [];

        foreach (explode(',', $selectorList) as $selector) {
            $selector = trim((string) preg_replace('/\s+/', ' ', $selector));

            if ('' !== $selector) {
                $selectors[] = $selector;
            }
        }

        return implode(',', $selectors);
    }

    /**
     * @param string $declarationList
     * @return array<int, string> Normalized `property: value` declarations, `!important` kept
     */
    private static function splitDeclarations(string $declarationList): array
    {
        $declarations = [];

        foreach (explode(';', $declarationList) as $declaration) {
            $separator = strpos($declaration, ':');

            if (false === $separator) {
                continue;
            }

            $property = strtolower(trim(substr($declaration, 0, $separator)));
            $value = trim((string) preg_replace('/\s+/', ' ', substr($declaration, $separator + 1)));

            if ('' === $property || '' === $value) {
                continue;
            }

            $declarations[] = $property . ': ' . $value;
        }

        return $declarations;
    }
}
