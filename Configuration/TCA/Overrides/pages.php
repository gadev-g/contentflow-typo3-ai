<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

$fields = [
    'tx_contentflow_focus_keywords' => [
        'exclude' => true,
        'label' => 'ContentFlow focus keywords',
        'config' => ['type' => 'text', 'rows' => 2],
    ],
    'tx_contentflow_schema_org' => [
        'exclude' => true,
        'label' => 'ContentFlow Schema.org JSON-LD',
        'config' => ['type' => 'text', 'rows' => 12, 'enableTabulator' => true],
    ],
];
ExtensionManagementUtility::addTCAcolumns('pages', $fields);
ExtensionManagementUtility::addToAllTCAtypes(
    'pages',
    '--div--;ContentFlow SEO,tx_contentflow_focus_keywords,tx_contentflow_schema_org',
);
