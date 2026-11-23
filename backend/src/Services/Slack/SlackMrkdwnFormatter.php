<?php

namespace IvyForms\Services\Slack;

/**
 * Converts HTML/plain text to Slack mrkdwn.
 */
class SlackMrkdwnFormatter
{
    /**
     * Format admin-authored text (feed title, field labels): markdown links and
     * emphasis are converted to mrkdwn.
     */
    public static function format(string $text): string
    {
        $text = self::escape($text);
        $text = preg_replace('/\*\*(.+?)\*\*/', '*$1*', $text) ?? $text;
        $text = preg_replace('/__(.+?)__/', '_$1_', $text) ?? $text;
        $text = preg_replace('/\[(.+?)\]\((.+?)\)/', '<$2|$1>', $text) ?? $text;
        $text = preg_replace('/(?<![<|])(https?:\/\/[^\s<>]+)/', '<$1>', $text) ?? $text;

        return trim($text);
    }

    /**
     * Escape visitor-submitted text as stored (HTML-encoded). No link
     * conversion, so a value can never produce a <!channel>-style mention or
     * a spoofed link.
     *
     * Slack has no escape for `*`, `_`, `~` or a backtick, so a value escaped
     * here can still come out bold or italic. That is why field rows go out
     * as rich_text; this stays for title placeholders.
     */
    public static function escape(string $text): string
    {
        return self::escapePlainText(self::toPlainText($text));
    }

    /**
     * Text with tags stripped and entities decoded, for rich_text elements,
     * which Slack shows exactly as given.
     */
    public static function toPlainText(string $text): string
    {
        return trim(html_entity_decode(wp_strip_all_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * Escape Slack's control characters in text that is already plain.
     * Running toPlainText() on it again would read a decoded "<3" as a tag
     * and cut the rest of the value.
     */
    public static function escapePlainText(string $plainText): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $plainText);
    }
}
