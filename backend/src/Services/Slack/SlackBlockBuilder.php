<?php

namespace IvyForms\Services\Slack;

/**
 * Renders a built Slack message as Block Kit blocks: the title as a mrkdwn
 * section, the field rows as one rich_text block that Slack shows as given.
 */
class SlackBlockBuilder
{
    private const URL_PATTERN = '/(https?:\/\/[^\s<>]+)/u';

    /** Sentence punctuation that ends a URL in running text, as in "see https://a.com/x." */
    private const URL_TRAILING_PUNCTUATION = '.,;:!?\'"';

    /**
     * @param array<int, array{label: string, value: string, type: string}> $rows
     * @return array<int, array<string, mixed>>
     */
    public static function build(string $title, array $rows): array
    {
        $blocks = [];
        if ($title !== '') {
            $blocks[] = [
                'type' => 'section',
                // Without verbatim Slack link-ifies channel names and parses some
                // mentions, which plain message text never did; links the
                // formatter built as <url|text> still work.
                'text' => ['type' => 'mrkdwn', 'text' => $title, 'verbatim' => true],
            ];
        }

        if ($rows === []) {
            return $blocks;
        }

        $elements = [];
        foreach ($rows as $index => $row) {
            if ($index > 0) {
                $elements[] = ['type' => 'text', 'text' => "\n"];
            }

            $elements[] = ['type' => 'text', 'text' => $row['label'] . ': ', 'style' => ['bold' => true]];
            array_push($elements, ...self::buildValueElements($row['value'], $row['type']));
        }

        $blocks[] = [
            'type'     => 'rich_text',
            'elements' => [
                ['type' => 'rich_text_section', 'elements' => $elements],
            ],
        ];

        return $blocks;
    }

    /**
     * rich_text does not auto-link, so email field values and http(s) URLs
     * become link elements. Other text, including an email typed into a text
     * field, stays plain. A link shows its own URL, never custom text, so a
     * value cannot hide where it points.
     *
     * @return array<int, array<string, string>>
     */
    private static function buildValueElements(string $value, string $fieldType): array
    {
        if ($fieldType === 'email' && is_email($value)) {
            return [['type' => 'link', 'url' => 'mailto:' . $value, 'text' => $value]];
        }

        $parts = preg_split(self::URL_PATTERN, $value, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        if (!is_array($parts)) {
            return [['type' => 'text', 'text' => $value]];
        }

        $elements = [];
        foreach ($parts as $part) {
            if (preg_match(self::URL_PATTERN, $part) !== 1) {
                $elements[] = ['type' => 'text', 'text' => $part];
                continue;
            }

            $url = self::trimUrlTrailingPunctuation($part);
            $isUrl = preg_match(self::URL_PATTERN, $url) === 1 && esc_url_raw($url) === $url;
            $elements[] = $isUrl
                ? ['type' => 'link', 'url' => $url]
                : ['type' => 'text', 'text' => $url];

            if ($url !== $part) {
                $elements[] = ['type' => 'text', 'text' => substr($part, strlen($url))];
            }
        }

        return $elements;
    }

    /**
     * A closing bracket stays only when the URL opens one too, as in
     * https://en.wikipedia.org/wiki/Slack_(software).
     */
    private static function trimUrlTrailingPunctuation(string $url): string
    {
        $url = rtrim($url, self::URL_TRAILING_PUNCTUATION);

        foreach ([')' => '(', ']' => '['] as $closing => $opening) {
            while (substr($url, -1) === $closing && substr_count($url, $closing) > substr_count($url, $opening)) {
                $url = rtrim(substr($url, 0, -1), self::URL_TRAILING_PUNCTUATION);
            }
        }

        return $url;
    }
}
