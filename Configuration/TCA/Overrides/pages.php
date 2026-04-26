<?php

declare(strict_types=1);

defined('TYPO3') or die();

/*
 * Wire AI Editorial Helper field wizards into the pages-edit form.
 *
 * This file modifies TCA only — node-registry registration lives in
 * ext_localconf.php (see issue #11 for the explanation: TCA cache freezes
 * the TCA array between requests, so $TYPO3_CONF_VARS side-effects from a
 * TCA override file would be lost on every request after the first).
 */
(static function (): void {
    if (!isset($GLOBALS['TCA']['pages']['columns'])) {
        return;
    }

    $columns = &$GLOBALS['TCA']['pages']['columns'];

    $metaWizardConfig = [
        'aiEditorialHelperGenerate' => [
            'renderType' => 'aiEditorialHelperGenerateMeta',
        ],
    ];

    foreach (['description', 'seo_title'] as $field) {
        if (!isset($columns[$field]['config'])) {
            continue;
        }
        $columns[$field]['config']['fieldWizard'] = array_merge(
            $columns[$field]['config']['fieldWizard'] ?? [],
            $metaWizardConfig,
        );
    }

    if (isset($columns['categories']['config'])) {
        $columns['categories']['config']['fieldWizard'] = array_merge(
            $columns['categories']['config']['fieldWizard'] ?? [],
            [
                'aiEditorialHelperSuggestCategories' => [
                    'renderType' => 'aiEditorialHelperSuggestCategories',
                ],
            ],
        );
    }

    if (isset($columns['slug']['config'])) {
        $columns['slug']['config']['fieldWizard'] = array_merge(
            $columns['slug']['config']['fieldWizard'] ?? [],
            [
                'aiEditorialHelperSuggestSlug' => [
                    'renderType' => 'aiEditorialHelperSuggestSlug',
                ],
            ],
        );
    }

    if (isset($columns['abstract']['config'])) {
        $columns['abstract']['config']['fieldWizard'] = array_merge(
            $columns['abstract']['config']['fieldWizard'] ?? [],
            [
                'aiEditorialHelperGenerateTeaser' => [
                    'renderType' => 'aiEditorialHelperGenerateTeaser',
                ],
            ],
        );
    }
})();
