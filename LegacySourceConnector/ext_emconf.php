<?php

$EM_CONF[$_EXTKEY] = array(
    'title' => 'ContentFlow Legacy Source Connector',
    'description' => 'Read-only source connector for migrations from TYPO3 8.7.',
    'category' => 'services',
    'author' => 'ContentFlow AI',
    'author_email' => 'development@contentflow-ai.com',
    'state' => 'beta',
    'clearCacheOnLoad' => 1,
    'version' => '0.1.0',
    'constraints' => array(
        'depends' => array(
            'typo3' => '8.7.0-8.7.99',
            'php' => '7.0.0-7.4.99',
        ),
        'conflicts' => array(),
        'suggests' => array(),
    ),
);
