<?php

declare(strict_types=1);

namespace Kairos\AiEditorialHelper\Form\FieldWizard;

use TYPO3\CMS\Backend\Form\AbstractNode;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;

/**
 * Renders a "Suggest slug" button next to the slug field on the
 * pages-edit form. The button calls the AJAX endpoint registered by
 * {@see \Kairos\AiEditorialHelper\Configuration\Backend\AjaxRoutes}.
 *
 * Unlike the meta-description wizard, this one shows up for new (uid=0)
 * pages too — the editor can suggest a slug from a freshly typed title
 * before the page has been saved.
 */
final class SuggestSlugWizard extends AbstractNode
{
    public function render(): array
    {
        $row = $this->data['databaseRow'] ?? [];
        $pageUid = (int)($row['uid'] ?? 0);
        $fieldName = (string)($this->data['fieldName'] ?? 'slug');

        $buttonId = sprintf(
            'ai-editorial-helper-slug-%d-%s',
            $pageUid,
            htmlspecialchars($fieldName, ENT_QUOTES, 'UTF-8'),
        );

        $html = sprintf(
            '<div class="form-wizards-element">'
            . '<button type="button" class="btn btn-default ai-editorial-helper-suggest-slug" '
            . 'id="%s" data-page-uid="%d" data-field="%s">'
            . '<span class="t3js-icon icon icon-size-small">✨</span> %s'
            . '</button>'
            . '</div>',
            $buttonId,
            $pageUid,
            htmlspecialchars($fieldName, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($this->translate('wizard.suggest_slug'), ENT_QUOTES, 'UTF-8'),
        );

        return [
            'iconIdentifier' => null,
            'html' => $html,
            'javaScriptModules' => [
                JavaScriptModuleInstruction::create('@kairos/ai-editorial-helper/slug-suggester-wizard.js'),
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
        return 'Suggest slug';
    }
}
