<?php

declare(strict_types=1);

use ContentFlow\Typo3Translation\Controller\TranslationController;
use ContentFlow\Typo3Translation\Controller\AssetMetadataController;
use ContentFlow\Typo3Translation\Controller\HubController;
use ContentFlow\Typo3Translation\Controller\ImageGenerationController;
use ContentFlow\Typo3Translation\Controller\ContentCreatorController;

return [
    'contentflow_translation' => [
        'parent' => 'web',
        'position' => ['after' => 'web_layout'],
        'access' => 'user',
        'workspaces' => 'live,offline',
        'path' => '/module/content/contentflow-translation',
        'labels' => 'LLL:EXT:contentflow_translation/Resources/Private/Language/locallang_mod.xlf',
        'extensionName' => 'ContentflowTranslation',
        'iconIdentifier' => 'contentflow-ai-module',
        'controllerActions' => [
            HubController::class => ['index'],
            TranslationController::class => ['index', 'preview', 'apply'],
            AssetMetadataController::class => ['index', 'preview', 'apply'],
            ImageGenerationController::class => ['index'],
            ContentCreatorController::class => ['index'],
        ],
    ],
];
