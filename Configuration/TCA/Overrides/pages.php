<?php

declare(strict_types=1);

defined('TYPO3') or die();

use Kairos\AiEditorialHelper\Form\FieldWizard\GenerateMetaDescriptionWizard;
use Kairos\AiEditorialHelper\Form\FieldWizard\SuggestCategoriesWizard;

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

    $GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'][1714140100] = [
        'nodeName' => 'aiEditorialHelperGenerateMeta',
        'priority' => 40,
        'class' => GenerateMetaDescriptionWizard::class,
    ];

    $GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'][1714140103] = [
        'nodeName' => 'aiEditorialHelperSuggestCategories',
        'priority' => 40,
        'class' => SuggestCategoriesWizard::class,
    ];
})();
