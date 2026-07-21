<?php

declare(strict_types=1);

namespace ContentFlow\Typo3Translation\Controller;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

final class ContentCreatorController extends ActionController
{
    public function __construct(private readonly ModuleTemplateFactory $moduleTemplateFactory)
    {
    }

    public function indexAction(): ResponseInterface
    {
        return $this->moduleTemplateFactory->create($this->request)->renderResponse('ContentCreator/Index');
    }
}
