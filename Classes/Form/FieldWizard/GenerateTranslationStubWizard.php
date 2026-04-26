<?php

declare(strict_types=1);

namespace Kairos\AiEditorialHelper\Form\FieldWizard;

use TYPO3\CMS\Backend\Form\AbstractNode;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;

/**
 * Renders a "Generate translation stub" button next to the bodytext field on
 * tt_content rows that are localizations of an original row (i.e. l10n_source > 0
 * and sys_language_uid > 0). For original (default-language) rows, the button
 * is hidden — there's nothing to translate from.
 */
final class GenerateTranslationStubWizard extends AbstractNode
{
    public function render(): array
    {
        $row = $this->data['databaseRow'] ?? [];
        $contentUid = (int)($row['uid'] ?? 0);
        $sysLanguageUid = (int)($row['sys_language_uid'] ?? 0);
        $l10nSource = (int)($row['l10n_source'] ?? 0);

        // Only show on localised rows that have a source to translate from.
        if ($contentUid <= 0 || $sysLanguageUid === 0 || $l10nSource <= 0) {
            return [
                'iconIdentifier' => null,
                'html' => '',
                'javaScriptModules' => [],
            ];
        }

        $buttonId = sprintf('ai-editorial-helper-translate-%d', $contentUid);

        $html = sprintf(
            '<div class="form-wizards-element">'
            . '<button type="button" class="btn btn-default ai-editorial-helper-translate-stub" '
            . 'id="%s" data-content-uid="%d">'
            . '<span class="t3js-icon icon icon-size-small">🌐</span> %s'
            . '</button>'
            . '</div>',
            $buttonId,
            $contentUid,
            htmlspecialchars($this->translate('wizard.translate_stub'), ENT_QUOTES, 'UTF-8'),
        );

        return [
            'iconIdentifier' => null,
            'html' => $html,
            'javaScriptModules' => [
                JavaScriptModuleInstruction::create('@kairos/ai-editorial-helper/translation-stub-wizard.js'),
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
        return 'Generate translation stub';
    }
}
