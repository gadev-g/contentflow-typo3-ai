<?php

declare(strict_types=1);

namespace ContentFlow\Typo3Translation\Controller;

use ContentFlow\Typo3Translation\Service\AssetMetadataWriter;
use ContentFlow\Typo3Translation\Service\AssetReader;
use ContentFlow\Typo3Translation\Service\ContentFlowClient;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

final class AssetMetadataController extends ActionController
{
    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly AssetReader $assetReader,
        private readonly ContentFlowClient $client,
        private readonly AssetMetadataWriter $writer,
        private readonly SiteFinder $siteFinder,
    ) {
    }

    public function indexAction(): ResponseInterface
    {
        $module = $this->moduleTemplateFactory->create($this->request);
        try {
            $providers = $this->client->providers();
        } catch (\Throwable $exception) {
            $providers = [];
            $this->addFlashMessage($exception->getMessage(), 'Provider configuration unavailable', ContextualFeedbackSeverity::ERROR);
        }
        $module->assignMultiple(['languages' => $this->availableLanguages(), 'providers' => $providers, 'defaultProvider' => $providers[0]['id'] ?? '']);

        return $module->renderResponse('AssetMetadata/Index');
    }

    public function previewAction(int $fileUid, string $language, string $provider = '', string $model = ''): ResponseInterface
    {
        try {
            $asset = $this->assetReader->read($fileUid);
            $result = $this->client->analyzeAsset('sys_file:'.$fileUid, $asset['mimeType'], $asset['contents'], $language, $asset['context'], $provider, '' === trim($model) ? null : $model);
            $token = bin2hex(random_bytes(24));
            $languageId = $this->resolveLanguageId($language);
            $this->backendUser()->setAndSaveSessionData('contentflow_asset_'.$token, ['fileUid' => $fileUid, 'languageId' => $languageId, 'metadata' => $result['metadata'], 'createdAt' => time()]);
            $module = $this->moduleTemplateFactory->create($this->request);
            $module->assignMultiple(['fileUid' => $fileUid, 'fileName' => $asset['name'], 'publicUrl' => $asset['publicUrl'], 'metadata' => $result['metadata'], 'previewToken' => $token, 'meta' => $result['meta'], 'debug' => $result['_debug'] ?? null]);

            return $module->renderResponse('AssetMetadata/Preview');
        } catch (\Throwable $exception) {
            $this->addFlashMessage($exception->getMessage(), 'Image analysis failed', ContextualFeedbackSeverity::ERROR);

            return $this->redirect('index');
        }
    }

    public function applyAction(string $previewToken): ResponseInterface
    {
        $sessionKey = 'contentflow_asset_'.$previewToken;
        $preview = $this->backendUser()->getSessionData($sessionKey);
        try {
            if (!is_array($preview) || !isset($preview['createdAt']) || time() - (int) $preview['createdAt'] > 3600) {
                throw new \RuntimeException('The asset preview expired. Please analyze the image again.');
            }
            /** @var array<string, string> $metadata */
            $metadata = $preview['metadata'];
            $metadataUid = $this->writer->write((int) $preview['fileUid'], (int) $preview['languageId'], $metadata);
            $this->backendUser()->setAndSaveSessionData($sessionKey, null);
            $this->addFlashMessage('The approved metadata was saved as record '.$metadataUid.'.', 'Asset metadata saved');
        } catch (\Throwable $exception) {
            $this->addFlashMessage($exception->getMessage(), 'Saving failed', ContextualFeedbackSeverity::ERROR);
        }

        return $this->redirect('index');
    }

    /** @return list<array{id: int, code: string, title: string}> */
    private function availableLanguages(): array
    {
        $languages = [];
        foreach ($this->siteFinder->getAllSites() as $site) {
            foreach ($site->getAllLanguages() as $language) {
                $languages[$language->getLanguageId()] = ['id' => $language->getLanguageId(), 'code' => $language->getLocale()->getLanguageCode(), 'title' => $language->getTitle()];
            }
        }
        ksort($languages);

        return array_values($languages);
    }

    private function resolveLanguageId(string $code): int
    {
        foreach ($this->availableLanguages() as $language) {
            if ($language['code'] === $code) {
                return $language['id'];
            }
        }
        throw new \RuntimeException('Please select an available TYPO3 language.');
    }

    private function backendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }
}
