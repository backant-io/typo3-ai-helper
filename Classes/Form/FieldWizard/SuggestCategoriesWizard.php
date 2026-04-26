<?php

declare(strict_types=1);

namespace Kairos\AiEditorialHelper\Form\FieldWizard;

use TYPO3\CMS\Backend\Form\AbstractNode;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;

/**
 * Renders a "Suggest categories" button next to the categories field on the
 * pages-edit form. Hidden for new (uid<=0) pages because we need tt_content
 * to suggest from.
 */
final class SuggestCategoriesWizard extends AbstractNode
{
    public function render(): array
    {
        $row = $this->data['databaseRow'] ?? [];
        $pageUid = (int)($row['uid'] ?? 0);
        $fieldName = (string)($this->data['fieldName'] ?? 'categories');

        if ($pageUid <= 0) {
            return [
                'iconIdentifier' => null,
                'html' => '',
                'javaScriptModules' => [],
            ];
        }

        $buttonId = sprintf(
            'ai-editorial-helper-categories-%d-%s',
            $pageUid,
            htmlspecialchars($fieldName, ENT_QUOTES, 'UTF-8'),
        );

        $html = sprintf(
            '<div class="form-wizards-element ai-editorial-helper-categories-wizard">'
            . '<button type="button" class="btn btn-default ai-editorial-helper-suggest-categories" '
            . 'id="%s" data-page-uid="%d" data-field="%s">'
            . '<span class="t3js-icon icon icon-size-small">✨</span> %s'
            . '</button>'
            . '<div class="ai-editorial-helper-suggestions" data-for="%s"></div>'
            . '</div>',
            $buttonId,
            $pageUid,
            htmlspecialchars($fieldName, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($this->translate('wizard.suggest_categories'), ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($fieldName, ENT_QUOTES, 'UTF-8'),
        );

        return [
            'iconIdentifier' => null,
            'html' => $html,
            'javaScriptModules' => [
                JavaScriptModuleInstruction::create('@kairos/ai-editorial-helper/category-suggester-wizard.js'),
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
        return 'Suggest categories';
    }
}
