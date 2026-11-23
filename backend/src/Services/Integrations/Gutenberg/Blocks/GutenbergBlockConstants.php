<?php

/**
 * GutenbergBlockConstants class - identifiers shared by the Gutenberg block classes and routes
 *
 * @copyright © Melograno Venture Studio. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

namespace IvyForms\Services\Integrations\Gutenberg\Blocks;

/**
 * Class GutenbergBlockConstants
 *
 * @package IvyForms\Services\Integrations\Gutenberg\Blocks
 */
final class GutenbergBlockConstants
{
    /**
     * Release flag for the per-block style panel and its style overrides.
     *
     * @var string
     */
    public const STYLES_FEATURE = 'gutenberg-block-styles';

    /**
     * REST route (relative to the plugin namespace) serving the editor payload the block script
     * fetches the first time an IvyForms block is rendered in the editor. Registered only while
     * {@see self::STYLES_FEATURE} is on.
     *
     * @var string
     */
    public const EDITOR_DATA_ROUTE = '/gutenberg/block-editor-data';
}
