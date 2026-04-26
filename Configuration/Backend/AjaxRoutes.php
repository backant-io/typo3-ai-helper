<?php

declare(strict_types=1);

use Kairos\AiEditorialHelper\Controller\Ajax\MetaDescriptionAjaxController;
use Kairos\AiEditorialHelper\Controller\Ajax\SlugSuggesterAjaxController;

return [
    'ai_editorial_helper_meta' => [
        'path' => '/ai-editorial-helper/meta',
        'target' => MetaDescriptionAjaxController::class . '::generate',
    ],
    'ai_editorial_helper_slug' => [
        'path' => '/ai-editorial-helper/slug',
        'target' => SlugSuggesterAjaxController::class . '::suggest',
    ],
];
