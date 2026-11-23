<?php

/**
 * @copyright © Melograno Venture Studio. All rights reserved.
 * @licence   See COPYING.md for license details.
 */

namespace IvyForms\Common\Features;

/**
 * Release switches for user-facing features merged into develop before they ship.
 *
 * Every feature that is not certain to ship in the next release is registered here as
 * {@code false} and wraps all of its entry points (REST routes, admin menus and pages,
 * JS/CSS enqueue, hook registrations, builder fields) in {@see Features::enabled()}.
 * On release, approved flags flip to {@code true} in one commit.
 *
 * This is the only registry: add-ons read it, so one slug turns a feature off everywhere.
 * QA sites override values with the {@code ivyforms/feature/{slug}} filter (see qa-flags/).
 *
 * A flag is a release switch, not a security boundary: never put secrets, keys or
 * licence logic behind it, and keep migrations of flagged features additive.
 */
class Features
{
    /**
     * Shipped default per feature slug, with ticket and target release in a comment.
     *
     * @var array<string, bool>
     */
    private const DEFAULTS = [
        'cl-and-or-groups'        => false, // IVY-1432, target PRO 1.4
        'cl-set-field-value'      => false, // IVY-1419, target PRO 1.4
        'review-before-submit'    => false, // IVY-1418, target PRO 1.4
        'calculated-field-toggle' => false, // IVY-1640, target PRO 1.4
        'mailerlite'              => false, // IVY-1405, target PRO 1.5
        'slack'                   => true, // IVY-955, shipped in 1.5.3
        'hidden-field'            => false, // IVY-1453, target 1.6
        'sticky-builder-header'   => false, // IVY-1631, target 1.6
        'whats-new-page'          => true, // IVY-1455, shipped in 1.5.3
        'multilang-support'       => true, // IVY-1474, shipped in 1.5.3
        'repeater-field'          => false, // IVY-1353, target PRO 1.4
        'gutenberg-block-styles'  => false, // IVY-242, target 1.6
        'brevo'                   => false, // IVY-1406, target PRO 1.5
        'form-builder-history'    => false, // IVY-138, target 1.6
    ];

    /**
     * Whether the feature identified by the slug is turned on.
     *
     * Unknown slugs are off, so a typo hides a feature instead of exposing it.
     */
    public static function enabled(string $slug): bool
    {
        $enabled = self::defaults()[$slug] ?? false;

        /**
         * Filter whether a flagged feature is turned on.
         *
         * @param bool $enabled
         */
        return (bool) apply_filters("ivyforms/feature/{$slug}", $enabled);
    }

    /**
     * Every registered slug with its current value, for the admin app.
     *
     * @return array<string, bool>
     */
    public static function all(): array
    {
        $flags = [];
        foreach (array_keys(self::defaults()) as $slug) {
            $flags[$slug] = self::enabled($slug);
        }

        return $flags;
    }

    /**
     * @return array<string, bool>
     */
    private static function defaults(): array
    {
        return self::DEFAULTS;
    }
}
