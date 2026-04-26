<?php

declare(strict_types=1);

namespace Kairos\AiEditorialHelper\Form\FieldWizard;

use TYPO3\CMS\Backend\Form\AbstractNode;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;

/**
 * Renders a "Generate with AI" button next to the meta description / SEO title
 * fields on the pages-edit form.
 *
 * The actual wiring lives in {@see \Kairos\AiEditorialHelper\Configuration\TCA\Overrides\pages}
 * — this wizard just emits the markup + ES module instruction. The button
 * itself is wired to the AJAX route in the loaded JS module.
 */
final class GenerateMetaDescriptionWizard extends AbstractNode
{
    public function render(): array
    {
        $row = $this->data['databaseRow'] ?? [];
        $pageUid = (int)($row['uid'] ?? 0);
        $fieldName = (string)($this->data['fieldName'] ?? '');

        // Only show the button when editing an existing page (we need a UID to
        // load the body content from tt_content). For new (uid<=0) records the
        // wizard hides itself.
        if ($pageUid <= 0) {
            return [
                'iconIdentifier' => null,
                'html' => '',
                'javaScriptModules' => [],
            ];
        }

        $buttonId = 'ai-editorial-helper-meta-' . $pageUid . '-' . htmlspecialchars($fieldName, ENT_QUOTES, 'UTF-8');

        $html = sprintf(
            '<div class="form-wizards-element">'
            . '<button type="button" class="btn btn-default ai-editorial-helper-generate" '
            . 'id="%s" data-page-uid="%d" data-field="%s">'
            . '<span class="t3js-icon icon icon-size-small">✨</span> %s'
            . '</button>'
            . '</div>',
            $buttonId,
            $pageUid,
            htmlspecialchars($fieldName, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($this->translate('wizard.generate'), ENT_QUOTES, 'UTF-8'),
        );

        return [
            'iconIdentifier' => null,
            'html' => $html,
            'javaScriptModules' => [
                JavaScriptModuleInstruction::create('@kairos/ai-editorial-helper/meta-description-wizard.js'),
            ],
            'stylesheetFiles' => [],
            'requireJsModules' => [],
            'additionalHiddenFields' => [],
            'additionalInlineLanguageLabelFiles' => [],
            'inlineData' => [],
        ];
    }

    private function translate(string $key): string
    {
        $lang = $GLOBALS['LANG'] ?? null;
        if ($lang !== null && method_exists($lang, 'sL')) {
            $label = $lang->sL('LLL:EXT:ai_editorial_helper/Resources/Private/Language/locallang_be.xlf:' . $key);
            if (is_string($label) && $label !== '') {
                return $label;
            }
        }
        return 'Generate with AI';
    }
}
