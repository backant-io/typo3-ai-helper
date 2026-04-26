<?php

declare(strict_types=1);

use Kairos\AiEditorialHelper\Controller\Ajax\CategorySuggesterAjaxController;
use Kairos\AiEditorialHelper\Controller\Ajax\MetaDescriptionAjaxController;
use Kairos\AiEditorialHelper\Controller\Ajax\SlugSuggesterAjaxController;

return [
    'ai_editorial_helper_meta' => [
        'path' => '/ai-editorial-helper/meta',
        'target' => MetaDescriptionAjaxController::class . '::generate',
    ],
<<<<<<< HEAD
    'ai_editorial_helper_categories' => [
        'path' => '/ai-editorial-helper/categories',
        'target' => CategorySuggesterAjaxController::class . '::suggest',
=======
    'ai_editorial_helper_slug' => [
        'path' => '/ai-editorial-helper/slug',
        'target' => SlugSuggesterAjaxController::class . '::suggest',
>>>>>>> bef4846 (Add URL slug suggester (#4))
    ],
];
