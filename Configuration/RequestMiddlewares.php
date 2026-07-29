<?php

declare(strict_types=1);

return [
    'frontend' => [
        'contentflow/source-migration' => [
            'target' => ContentFlow\Typo3Translation\Middleware\MigrationSourceMiddleware::class,
            'after' => ['typo3/cms-frontend/site'],
            'before' => ['typo3/cms-frontend/page-resolver'],
        ],
    ],
];
