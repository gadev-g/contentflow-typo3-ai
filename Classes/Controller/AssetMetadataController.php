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

    public function previewAction(string $language, int $fileUid = 0, string $fileUids = '', string $folderIdentifier = '', string $provider = '', string $model = ''): ResponseInterface
    {
        try {
            $selectedFileUids = array_map('intval', array_filter(explode(',', $fileUids), static fn (string $value): bool => ctype_digit(trim($value))));
            if ($fileUid > 0) {
                $selectedFileUids[] = $fileUid;
            }
            if ('' !== trim($folderIdentifier)) {
                $selectedFileUids = array_merge($selectedFileUids, $this->assetReader->fileUidsFromFolder(trim($folderIdentifier)));
            }
            $selectedFileUids = array_values(array_unique(array_filter($selectedFileUids, static fn (int $uid): bool => $uid > 0)));
            if ([] === $selectedFileUids) {
                throw new \RuntimeException('Please select at least one image or a folder containing supported images.');
            }
            if (count($selectedFileUids) > 25) {
                throw new \RuntimeException('A maximum of 25 images can be analyzed in one batch.');
            }

            $assets = [];
            $debugItems = [];
            $meta = [];
            foreach ($selectedFileUids as $selectedFileUid) {
                $asset = $this->assetReader->read($selectedFileUid);
                $result = $this->client->analyzeAsset('sys_file:'.$selectedFileUid, $asset['mimeType'], $asset['contents'], $language, $asset['context'], $provider, '' === trim($model) ? null : $model);
                $assets[] = ['fileUid' => $selectedFileUid, 'fileName' => $asset['name'], 'publicUrl' => $asset['publicUrl'], 'metadata' => $result['metadata']];
                $meta = $result['meta'];
                if (isset($result['_debug'])) {
                    $debugItems[] = ['fileName' => $asset['name'], 'exchange' => $result['_debug']];
                }
            }
            $token = bin2hex(random_bytes(24));
            $languageId = $this->resolveLanguageId($language);
            $this->backendUser()->setAndSaveSessionData('contentflow_asset_'.$token, ['assets' => $assets, 'languageId' => $languageId, 'createdAt' => time()]);
            $module = $this->moduleTemplateFactory->create($this->request);
            $module->assignMultiple(['assets' => $assets, 'assetCount' => count($assets), 'previewToken' => $token, 'meta' => $meta, 'debugItems' => $debugItems]);

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
            if (!isset($preview['assets']) || !is_array($preview['assets'])) {
                throw new \RuntimeException('The asset preview is invalid. Please analyze the images again.');
            }
            $savedRecords = 0;
            foreach ($preview['assets'] as $asset) {
                if (!is_array($asset) || !isset($asset['fileUid'], $asset['metadata']) || !is_array($asset['metadata'])) {
                    continue;
                }
                $this->writer->write((int) $asset['fileUid'], (int) $preview['languageId'], $asset['metadata']);
                ++$savedRecords;
            }
            if (0 === $savedRecords) {
                throw new \RuntimeException('No valid asset metadata was available to save.');
            }
            $this->backendUser()->setAndSaveSessionData($sessionKey, null);
            $this->addFlashMessage($savedRecords.' image metadata record(s) were saved.', 'Asset metadata saved');
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
