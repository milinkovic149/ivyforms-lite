<?php

/**
 * @copyright © Melograno Venture Studio. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

namespace IvyForms\Services\Shortcode\Skeleton;

use IvyForms\Common\Sanitizer\HtmlSanitizer;
use IvyForms\Services\API\IvyFormsAPI;

/**
 * Server-rendered loading placeholder that reserves the space of the form.
 *
 * The markup mirrors FormRender.vue: the same wrapper classes (so the inline form CSS
 * printed by the shortcode applies to both), the same rows, and per-type heights of
 * the public field components. FormRender removes it once the form is ready.
 */
class FormSkeletonRenderer
{
    private const PROGRESS_HEIGHTS = ['rootline' => 84, 'tabs' => 68];

    /**
     * @var array<string, mixed>
     */
    private array $formData;

    /**
     * @var array<string, mixed>
     */
    private array $form;

    private SkeletonStyleResolver $style;

    private SkeletonMarkup $markup;

    private SkeletonFieldTree $tree;

    private SkeletonFieldRenderer $fields;

    /**
     * @param array<string, mixed> $formData Entry of ShortcodeService::$formList
     */
    public function __construct(array $formData)
    {
        $this->formData = $formData;
        $this->form = is_array($formData['form'] ?? null) ? $formData['form'] : [];

        $this->style = new SkeletonStyleResolver($this->getStyleSettings(), $this->getFormType());
        $this->markup = new SkeletonMarkup($this->style);
        $this->tree = new SkeletonFieldTree($formData);
        $this->fields = new SkeletonFieldRenderer(
            $this->markup,
            $this->style,
            $this->tree,
            new SkeletonCaptchaResolver($formData)
        );
    }

    /**
     * @param array<string, mixed> $formData Entry of ShortcodeService::$formList
     * @param string $counter Render counter; FormRender removes `.ivyforms-skeleton-{counter}`
     * @return string
     */
    public static function render(array $formData, string $counter): string
    {
        return (new self($formData))->renderSkeleton($counter);
    }

    public function renderSkeleton(string $counter): string
    {
        $formId = (int) ($this->formData['id'] ?? 0);
        $styleSettings = $this->getStyleSettings();
        $customClass = HtmlSanitizer::sanitizeCssClasses($styleSettings['customCssClass'] ?? '');
        $customClass = $customClass !== '' ? ' ' . $customClass : '';
        $modeClass = $this->style->isResetMode() ? ' ivyforms-skeleton--reset' : '';

        $cssVars = '--ivyforms-skeleton-vertical: ' . $this->style->getVerticalSpacing() . '; '
            . '--ivyforms-skeleton-horizontal: ' . $this->style->getHorizontalSpacing() . '; '
            . '--ivyforms-skeleton-input: ' . $this->style->getInputHeight() . '; '
            . '--ivyforms-skeleton-phone: ' . $this->style->getInputHeight(38) . '; '
            . '--ivyforms-skeleton-label-pad: ' . $this->style->getLabelPaddingY() . '; '
            . '--ivyforms-skeleton-star: ' . $this->style->getRatingIconSize() . ';';

        // Outer div mirrors #ivyforms-app-*, inner div mirrors the FormRender wrapper.
        return '<div class="ivyforms-skeleton' . $modeClass . ' ivyforms-form-' . $formId
            . ' ivyforms-form-wrapper-' . $formId . esc_attr($customClass)
            . ' ivyforms-skeleton-' . esc_attr($counter) . '" aria-hidden="true"'
            . ' style="' . esc_attr($cssVars) . '">'
            . '<div class="ivyforms-skeleton-inner ivyforms-form-wrapper ivyforms-form-wrapper-' . $formId
            . ' ivyforms-form-' . $formId . '">'
            . $this->renderHeader($formId)
            . ($this->isConversational() ? $this->renderConversational() : $this->renderClassic())
            . '</div></div>';
    }

    /**
     * Title and description keep their (invisible) text so they wrap like the real ones.
     */
    private function renderHeader(int $formId): string
    {
        $html = '';
        if (!array_key_exists('showTitle', $this->form) || !empty($this->form['showTitle'])) {
            $html .= '<h3 class="ivyforms-skeleton-title ivyforms-form-title ivyforms-form-title-' . $formId . '">'
                . esc_html((string) ($this->form['name'] ?? '')) . '</h3>';
        }
        if (!empty($this->form['showDescription'])) {
            $html .= '<p class="ivyforms-skeleton-description ivyforms-form-description ivyforms-form-description-'
                . $formId . '">' . esc_html((string) ($this->form['description'] ?? '')) . '</p>';
        }

        return $html;
    }

    private function renderClassic(): string
    {
        return $this->renderProgressIndicator()
            . '<div class="ivyforms-skeleton-form">'
            . $this->fields->renderRows($this->tree->getRootFields(), SkeletonFieldRenderer::CONTEXT_ROOT)
            . $this->renderActions()
            . '</div>';
    }

    private function renderProgressIndicator(): string
    {
        $progress = is_array($this->form['progressIndicator'] ?? null) ? $this->form['progressIndicator'] : [];
        $type = (string) ($progress['type'] ?? 'progress-bar');
        if (!$this->tree->isMultiPage() || $type === 'none') {
            return '';
        }

        $height = self::PROGRESS_HEIGHTS[$type] ?? (!empty($progress['showPageTitles']) ? 68 : 40);

        return '<div class="ivyforms-skeleton-progress" style="height: ' . $height . 'px;"></div>';
    }

    /**
     * Submit button, or the page navigation (Next) on the first page of a multi-page form.
     */
    private function renderActions(): string
    {
        $buttons = is_array($this->form['formActionButtons'] ?? null) ? $this->form['formActionButtons'] : [];

        if ($this->tree->isMultiPage()) {
            $pages = $this->tree->getPages();
            $settings = $buttons['pageNavigationSettings'][(string) ($pages[0]['id'] ?? '')] ?? [];
            $label = (string) ($settings['nextButton']['label'] ?? 'Next');

            return '<div class="ivyforms-skeleton-actions ivyforms-skeleton-actions--pages">'
                . $this->markup->button($label) . '</div>';
        }

        $submit = is_array($buttons['submitButtonSettings'] ?? null) ? $buttons['submitButtonSettings'] : [];
        $position = in_array($submit['position'] ?? '', ['center', 'right'], true) ? $submit['position'] : 'left';
        $label = trim((string) ($submit['label'] ?? '')) !== '' ? (string) $submit['label'] : 'Submit';

        return '<div class="ivyforms-skeleton-actions ivyforms-skeleton-actions--' . $position . '">'
            . $this->markup->button($label) . '</div>';
    }

    /**
     * Conversational forms show a welcome canvas, or the first question in a dialog.
     */
    private function renderConversational(): string
    {
        $welcome = is_array($this->form['welcomePage'] ?? null) ? $this->form['welcomePage'] : [];
        if (($welcome['enabled'] ?? true) !== false) {
            return '<div class="ivyforms-skeleton-block ivyforms-skeleton-welcome"></div>';
        }

        $fields = $this->tree->getAllRootFields();
        usort($fields, static function (array $first, array $second): int {
            return [(int) ($first['rowIndex'] ?? 0), (int) ($first['columnIndex'] ?? 0)]
                <=> [(int) ($second['rowIndex'] ?? 0), (int) ($second['columnIndex'] ?? 0)];
        });
        $question = isset($fields[0])
            ? $this->fields->renderField($fields[0], SkeletonFieldRenderer::CONTEXT_NESTED)
            : '';

        return '<div class="ivyforms-skeleton-dialog">' . $question
            . '<span class="ivyforms-skeleton-block ivyforms-skeleton-dialog-footer"></span></div>';
    }

    /**
     * @return array<string, mixed>
     */
    private function getStyleSettings(): array
    {
        return is_array($this->form['styleSettings'] ?? null) ? $this->form['styleSettings'] : [];
    }

    private function getFormType(): string
    {
        return (string) ($this->form['formType'] ?? 'classic');
    }

    private function isConversational(): bool
    {
        return $this->getFormType() === 'conversational' && IvyFormsAPI::isProPluginActive();
    }
}
