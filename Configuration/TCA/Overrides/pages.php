<?php

declare(strict_types=1);

defined('TYPO3') or die();

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

    if (isset($columns['title']['config'])) {
        $columns['title']['config']['fieldWizard'] = array_merge(
            $columns['title']['config']['fieldWizard'] ?? [],
            [
                'aiEditorialHelperCheckQuality' => [
                    'renderType' => 'aiEditorialHelperCheckQuality',
                ],
            ],
        );
    }
})();
