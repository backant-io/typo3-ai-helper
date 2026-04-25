<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'AI Editorial Helper',
    'description' => 'Local-LLM-powered editorial assistant for TYPO3 backend',
    'category' => 'be',
    'state' => 'beta',
    'version' => '0.1.0',
    'constraints' => [
        'depends' => [
            'typo3' => '13.0.0-13.99.99',
        ],
    ],
];
