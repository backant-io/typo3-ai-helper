<?php

declare(strict_types=1);

defined('TYPO3') or die();

/*
 * Wire the AI translation-stub wizard into the tt_content bodytext field.
 * The wizard itself decides at render time whether to surface the button
 * (only on localised rows with l10n_source > 0).
 *
 * TCA mutations only — node-registry registration lives in ext_localconf.php.
 */
(static function (): void {
    if (!isset($GLOBALS['TCA']['tt_content']['columns']['bodytext']['config'])) {
        return;
    }

    $config = &$GLOBALS['TCA']['tt_content']['columns']['bodytext']['config'];
    $config['fieldWizard'] = array_merge(
        $config['fieldWizard'] ?? [],
        [
            'aiEditorialHelperTranslationStub' => [
                'renderType' => 'aiEditorialHelperTranslationStub',
            ],
        ],
    );
})();
