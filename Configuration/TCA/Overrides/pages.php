<?php

declare(strict_types=1);

defined('TYPO3') or die();

use Kairos\AiEditorialHelper\Form\FieldWizard\GenerateMetaDescriptionWizard;
use Kairos\AiEditorialHelper\Form\FieldWizard\SuggestSlugWizard;

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

    $GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'][1714140100] = [
        'nodeName' => 'aiEditorialHelperGenerateMeta',
        'priority' => 40,
        'class' => GenerateMetaDescriptionWizard::class,
    ];

    $GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'][1714140101] = [
        'nodeName' => 'aiEditorialHelperSuggestSlug',
        'priority' => 40,
        'class' => SuggestSlugWizard::class,
    ];
})();
