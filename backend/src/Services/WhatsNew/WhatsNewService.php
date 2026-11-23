<?php

/**
 * @copyright © Melograno Venture Studio. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

namespace IvyForms\Services\WhatsNew;

use IvyForms\Common\Exceptions\ServiceUnavailableException;
use IvyForms\Services\Integrations\MiddlewareUrl;
use IvyForms\Services\Translations\BackendStrings;

/**
 * Loads What's New posts from ivyforms-middleware.
 *
 * YouTube, CMS fetching, caching, and API keys stay in middleware.
 */
class WhatsNewService
{
    public const DEFAULT_LIMIT = 15;

    public const MAX_LIMIT = 50;

    private WhatsNewHttpClient $httpClient;

    private WhatsNewPostNormalizer $normalizer;

    public function __construct(WhatsNewHttpClient $httpClient, WhatsNewPostNormalizer $normalizer)
    {
        $this->httpClient = $httpClient;
        $this->normalizer = $normalizer;
    }

    /**
     * @param array{page: int, limit: int, category: string, search?: string} $params
     * @return array{posts: array<int, array<string, mixed>>, total: int}
     * @throws ServiceUnavailableException
     */
    public function getPosts(array $params): array
    {
        $fromMiddleware = $this->fetchFromMiddleware($params);
        if ($fromMiddleware === null) {
            throw new ServiceUnavailableException(
                BackendStrings::getWhatsNewStrings()['failed_to_load_whats_new']
            );
        }

        return $fromMiddleware;
    }

    /**
     * @param array{page: int, limit: int, category: string, search?: string} $params
     * @return array{posts: array<int, array<string, mixed>>, total: int}|null
     */
    private function fetchFromMiddleware(array $params): ?array
    {
        $baseUrl = MiddlewareUrl::getBaseUrl();
        if ($baseUrl === '') {
            return null;
        }

        $decoded = $this->httpClient->getJson($this->buildMiddlewareUrl($params));
        if ($decoded === null || empty($decoded['success'])) {
            return null;
        }

        $posts = isset($decoded['data']) && is_array($decoded['data']) ? $decoded['data'] : [];
        $pagination = isset($decoded['pagination']) && is_array($decoded['pagination'])
            ? $decoded['pagination']
            : [];

        return [
            'posts' => $this->normalizer->normalizePosts($posts),
            'total' => isset($pagination['total']) ? (int) $pagination['total'] : count($posts),
        ];
    }

    /**
     * @param array{page: int, limit: int, category: string, search?: string} $params
     */
    private function buildMiddlewareUrl(array $params): string
    {
        $query = [
            'page' => $params['page'],
            'limit' => $params['limit'],
            // The middleware's tutorial filter covers the form-tutorials posts and the YouTube videos.
            'category' => $params['category'],
        ];
        $search = isset($params['search']) ? trim($params['search']) : '';
        if ($search !== '') {
            $query['search'] = $search;
        }

        return MiddlewareUrl::getBaseUrl() . '/blog-content?' . http_build_query(
            $query,
            '',
            '&',
            PHP_QUERY_RFC3986
        );
    }
}
