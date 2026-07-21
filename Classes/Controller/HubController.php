<?php

declare(strict_types=1);

namespace ContentFlow\Typo3Translation\Controller;

use ContentFlow\Typo3Translation\Service\ContentFlowClient;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

final class HubController extends ActionController
{
    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly ContentFlowClient $client,
    ) {
    }

    public function indexAction(): ResponseInterface
    {
        $module = $this->moduleTemplateFactory->create($this->request);
        try {
            $context = $this->client->integrationContext();
        } catch (\Throwable) {
            $context = [];
        }

        $module->assignMultiple([
            'plan' => $context['entitlements']['plan'] ?? 'unknown',
            'products' => $context['entitlements']['products'] ?? [],
        ]);

        return $module->renderResponse('Hub/Index');
    }
}
