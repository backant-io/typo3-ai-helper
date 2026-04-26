<?php

declare(strict_types=1);

use Kairos\AiEditorialHelper\Controller\Ajax\CategorySuggesterAjaxController;
use Kairos\AiEditorialHelper\Controller\Ajax\MetaDescriptionAjaxController;

return [
    'ai_editorial_helper_meta' => [
        'path' => '/ai-editorial-helper/meta',
        'target' => MetaDescriptionAjaxController::class . '::generate',
    ],
    'ai_editorial_helper_categories' => [
        'path' => '/ai-editorial-helper/categories',
        'target' => CategorySuggesterAjaxController::class . '::suggest',
    ],
];
