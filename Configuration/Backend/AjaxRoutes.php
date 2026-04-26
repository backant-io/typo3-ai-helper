<?php

declare(strict_types=1);

use Kairos\AiEditorialHelper\Controller\Ajax\MetaDescriptionAjaxController;

return [
    'ai_editorial_helper_meta' => [
        'path' => '/ai-editorial-helper/meta',
        'target' => MetaDescriptionAjaxController::class . '::generate',
    ],
];
