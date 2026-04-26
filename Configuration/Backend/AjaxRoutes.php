<?php

declare(strict_types=1);

use Kairos\AiEditorialHelper\Controller\Ajax\MetaDescriptionAjaxController;
use Kairos\AiEditorialHelper\Controller\Ajax\TeaserAjaxController;

return [
    'ai_editorial_helper_meta' => [
        'path' => '/ai-editorial-helper/meta',
        'target' => MetaDescriptionAjaxController::class . '::generate',
    ],
    'ai_editorial_helper_teaser' => [
        'path' => '/ai-editorial-helper/teaser',
        'target' => TeaserAjaxController::class . '::generate',
    ],
];
