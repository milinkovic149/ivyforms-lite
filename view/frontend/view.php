<?php if (!defined('ABSPATH')) exit; // Exit if accessed directly

use IvyForms\Services\Shortcode\ShortcodeService;
use IvyForms\Services\Shortcode\Skeleton\FormSkeletonRenderer;

/** @var int $counter */
$form = ShortcodeService::$formList[$counter];
$formId = $form['id'] ?? 0;
$customCssClass = '';
if (!empty($form['form']['styleSettings']['customCssClass'])) {
    $customCssClass = ' ' . esc_attr($form['form']['styleSettings']['customCssClass']);
}
?>

<div id="ivyforms-app-<?php echo esc_attr($counter) ?>"
     class="ivyforms ivyforms-frontend ivyforms-form-<?php echo esc_attr($formId) ?> ivyforms-form-wrapper-<?php echo esc_attr($formId) ?><?php echo $customCssClass ?>"
     data-counter="<?php echo esc_attr($counter) ?>"
     data-post-id="<?php echo esc_attr(get_the_ID()); ?>"
     data-referer-url="<?php echo esc_attr(wp_get_referer() ?: ''); ?>"
>
    <form-render id="ivyforms-form-<?php echo esc_attr($counter) ?>"></form-render>
</div>
<?php
// Reserves the exact space of the form until FormRender mounts and removes it.
echo FormSkeletonRenderer::render($form, (string) $counter);
