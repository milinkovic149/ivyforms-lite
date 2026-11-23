<?php

/**
 * @copyright © Melograno Venture Studio. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

namespace IvyForms\Services\WhatsNew;

/**
 * Normalizes middleware What's New posts into the admin card payload.
 */
class WhatsNewPostNormalizer
{
    /**
     * @param array<int, mixed> $posts
     * @return array<int, array<string, mixed>>
     */
    public function normalizePosts(array $posts): array
    {
        $normalized = [];
        foreach ($posts as $post) {
            if (is_array($post)) {
                $normalized[] = $this->normalizePost($post);
            }
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private function normalizePost(array $post): array
    {
        return [
            'id' => $post['id'] ?? '',
            'title' => wp_strip_all_tags((string) ($post['title'] ?? '')),
            'description' => wp_strip_all_tags((string) ($post['description'] ?? '')),
            'date' => (string) ($post['date'] ?? ''),
            'url' => esc_url_raw((string) ($post['url'] ?? '')),
            'thumbnail' => $this->sanitizeThumbnail($post['thumbnail'] ?? ''),
            'type' => (string) ($post['type'] ?? 'post'),
            'source' => (string) ($post['source'] ?? 'blog'),
            'category' => $post['category'] ?? '',
        ];
    }

    /**
     * Media IDs stay integers so the card can resolve them. URL strings are escaped before use as an image src.
     *
     * @param mixed $thumbnail
     * @return int|string
     */
    private function sanitizeThumbnail($thumbnail)
    {
        if (is_int($thumbnail)) {
            return $thumbnail > 0 ? $thumbnail : '';
        }

        if (is_string($thumbnail) && ctype_digit($thumbnail)) {
            $mediaId = absint($thumbnail);

            return $mediaId > 0 ? $mediaId : '';
        }

        if (!is_string($thumbnail) || $thumbnail === '') {
            return '';
        }

        return esc_url_raw($thumbnail);
    }
}
