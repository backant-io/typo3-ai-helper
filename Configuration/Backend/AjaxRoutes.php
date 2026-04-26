<?php

declare(strict_types=1);

use Kairos\AiEditorialHelper\Controller\Ajax\CategorySuggesterAjaxController;
use Kairos\AiEditorialHelper\Controller\Ajax\MetaDescriptionAjaxController;
use Kairos\AiEditorialHelper\Controller\Ajax\SlugSuggesterAjaxController;
use Kairos\AiEditorialHelper\Controller\Ajax\TeaserAjaxController;
use Kairos\AiEditorialHelper\Controller\Ajax\TranslationStubAjaxController;

return [
    'ai_editorial_helper_meta' => [
        'path' => '/ai-editorial-helper/meta',
        'target' => MetaDescriptionAjaxController::class . '::generate',
    ],
    'ai_editorial_helper_categories' => [
        'path' => '/ai-editorial-helper/categories',
        'target' => CategorySuggesterAjaxController::class . '::suggest',
    ],
    'ai_editorial_helper_slug' => [
        'path' => '/ai-editorial-helper/slug',
        'target' => SlugSuggesterAjaxController::class . '::suggest',
    ],
    'ai_editorial_helper_teaser' => [
        'path' => '/ai-editorial-helper/teaser',
        'target' => TeaserAjaxController::class . '::generate',
    ],
    'ai_editorial_helper_translate' => [
        'path' => '/ai-editorial-helper/translate',
        'target' => TranslationStubAjaxController::class . '::generate',
    ],
];
