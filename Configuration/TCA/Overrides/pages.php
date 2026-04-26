<?php

declare(strict_types=1);

defined('TYPO3') or die();

use Kairos\AiEditorialHelper\Form\FieldWizard\GenerateMetaDescriptionWizard;
<<<<<<< HEAD
use Kairos\AiEditorialHelper\Form\FieldWizard\SuggestCategoriesWizard;
=======
use Kairos\AiEditorialHelper\Form\FieldWizard\SuggestSlugWizard;
>>>>>>> bef4846 (Add URL slug suggester (#4))

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

<<<<<<< HEAD
    if (isset($columns['categories']['config'])) {
        $columns['categories']['config']['fieldWizard'] = array_merge(
            $columns['categories']['config']['fieldWizard'] ?? [],
            [
                'aiEditorialHelperSuggestCategories' => [
                    'renderType' => 'aiEditorialHelperSuggestCategories',
=======
    if (isset($columns['slug']['config'])) {
        $columns['slug']['config']['fieldWizard'] = array_merge(
            $columns['slug']['config']['fieldWizard'] ?? [],
            [
                'aiEditorialHelperSuggestSlug' => [
                    'renderType' => 'aiEditorialHelperSuggestSlug',
>>>>>>> bef4846 (Add URL slug suggester (#4))
                ],
            ],
        );
    }

    $GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'][1714140100] = [
        'nodeName' => 'aiEditorialHelperGenerateMeta',
        'priority' => 40,
        'class' => GenerateMetaDescriptionWizard::class,
    ];

<<<<<<< HEAD
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'][1714140103] = [
        'nodeName' => 'aiEditorialHelperSuggestCategories',
        'priority' => 40,
        'class' => SuggestCategoriesWizard::class,
=======
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'][1714140101] = [
        'nodeName' => 'aiEditorialHelperSuggestSlug',
        'priority' => 40,
        'class' => SuggestSlugWizard::class,
>>>>>>> bef4846 (Add URL slug suggester (#4))
    ];
})();
