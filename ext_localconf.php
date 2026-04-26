<?php

declare(strict_types=1);

defined('TYPO3') or die();

use Kairos\AiEditorialHelper\Form\FieldWizard\GenerateMetaDescriptionWizard;
use Kairos\AiEditorialHelper\Form\FieldWizard\GenerateTeaserWizard;
use Kairos\AiEditorialHelper\Form\FieldWizard\SuggestCategoriesWizard;
use Kairos\AiEditorialHelper\Form\FieldWizard\SuggestSlugWizard;

/*
 * Register custom FormEngine field-wizard nodes.
 *
 * Why ext_localconf.php and NOT a TCA override file:
 *
 * TYPO3 caches the loaded TCA array between requests. The override files in
 * Configuration/TCA/Overrides/*.php run ONCE when the cache is built — any
 * side-effects beyond TCA mutation (e.g. writing to TYPO3_CONF_VARS) are lost
 * on subsequent requests. ext_localconf.php is included on every request, so
 * the nodeRegistry registration is always present when NodeFactory's
 * constructor reads it.
 */
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

$GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'][1714140102] = [
    'nodeName' => 'aiEditorialHelperGenerateTeaser',
    'priority' => 40,
    'class' => GenerateTeaserWizard::class,
];

$GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'][1714140103] = [
    'nodeName' => 'aiEditorialHelperSuggestCategories',
    'priority' => 40,
    'class' => SuggestCategoriesWizard::class,
];
