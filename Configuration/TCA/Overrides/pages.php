<?php

declare(strict_types=1);

defined('TYPO3') or die();

use Kairos\AiEditorialHelper\Form\FieldWizard\GenerateMetaDescriptionWizard;

(static function (): void {
    if (!isset($GLOBALS['TCA']['pages']['columns'])) {
        return;
    }

    $columns = &$GLOBALS['TCA']['pages']['columns'];

    $wizardConfig = [
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
            $wizardConfig,
        );
    }

    $GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'][1714140100] = [
        'nodeName' => 'aiEditorialHelperGenerateMeta',
        'priority' => 40,
        'class' => GenerateMetaDescriptionWizard::class,
    ];
})();
