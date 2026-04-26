<?php

declare(strict_types=1);

namespace Kairos\AiEditorialHelper\Form\FieldWizard;

use TYPO3\CMS\Backend\Form\AbstractNode;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;

/**
 * Renders a "Check page quality" button next to the page title field.
 *
 * Page-level (not field-level): the button checks the whole page, not the
 * single field it sits next to. The title field is just a convenient anchor
 * point on the page-edit form.
 */
final class CheckQualityWizard extends AbstractNode
{
    public function render(): array
    {
        $row = $this->data['databaseRow'] ?? [];
        $pageUid = (int)($row['uid'] ?? 0);

        if ($pageUid <= 0) {
            return [
                'iconIdentifier' => null,
                'html' => '',
                'javaScriptModules' => [],
            ];
        }

        $buttonId = sprintf('ai-editorial-helper-quality-%d', $pageUid);

        $html = sprintf(
            '<div class="form-wizards-element ai-editorial-helper-quality-wizard">'
            . '<button type="button" class="btn btn-default ai-editorial-helper-check-quality" '
            . 'id="%s" data-page-uid="%d">'
            . '<span class="t3js-icon icon icon-size-small">🔎</span> %s'
            . '</button>'
            . '<div class="ai-editorial-helper-quality-results"></div>'
            . '</div>',
            $buttonId,
            $pageUid,
            htmlspecialchars($this->translate('wizard.check_quality'), ENT_QUOTES, 'UTF-8'),
        );

        return [
            'iconIdentifier' => null,
            'html' => $html,
            'javaScriptModules' => [
                JavaScriptModuleInstruction::create('@kairos/ai-editorial-helper/quality-checker-wizard.js'),
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
        return 'Check page quality';
    }
}
