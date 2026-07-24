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
            $context = $this->client->integrationContext();
            $providers = is_array($context['items'] ?? null) ? array_values($context['items']) : [];
            $models = $this->availableModels($providers);
        } catch (\Throwable $exception) {
            $context = [];
            $providers = [];
            $models = [];
            $this->addFlashMessage(
                $exception->getMessage(),
                'Provider configuration unavailable',
                ContextualFeedbackSeverity::ERROR,
            );
        }

        if (true !== ($context['entitlements']['products']['asset_intelligence'] ?? false)) {
            $module->assign('plan', $context['entitlements']['plan'] ?? 'free');

            return $module->renderResponse('AssetMetadata/Upgrade');
        }

        $module->assignMultiple([
            'languages' => $this->availableLanguages(),
            'providers' => $providers,
            'models' => $models,
            'defaultProvider' => $providers[0]['id'] ?? '',
            'products' => $context['entitlements']['products'] ?? [],
        ]);

        return $module->renderResponse('AssetMetadata/Index');
    }

    /**
     * @param list<array{id: string}> $providers
     * @return list<array{id: string, label: string, provider: string}>
     */
    private function availableModels(array $providers): array
    {
        $models = [];
        foreach ($providers as $provider) {
            $providerId = (string) ($provider['id'] ?? '');
            if ('' === $providerId) {
                continue;
            }
            try {
                $providerModels = $this->client->models($providerId);
            } catch (\Throwable) {
                $providerModels = [];
            }
            foreach ($providerModels as $model) {
                $id = (string) ($model['id'] ?? '');
                if ('' === $id) {
                    continue;
                }
                $details = array_values(array_filter([
                    (string) ($model['parameter_size'] ?? ''),
                    ((int) ($model['size'] ?? 0)) > 0
                        ? number_format((int) $model['size'] / 1024 ** 3, 1) . ' GB'
                        : '',
                ]));
                $models[] = [
                    'id' => $id,
                    'label' => [] === $details ? $id : sprintf('%s (%s)', $id, implode(', ', $details)),
                    'provider' => $providerId,
                ];
            }
        }

        return $models;
    }

    public function previewAction(
        string $language,
        int $fileUid = 0,
        string $fileUids = '',
        string $folderIdentifier = '',
        string $provider = '',
        string $model = '',
    ): ResponseInterface {
        try {
            if (!$this->client->hasProduct('asset_intelligence')) {
                throw new \RuntimeException('Asset Intelligence requires the Starter plan or higher.');
            }

            $selectedFileUids = array_map(
                'intval',
                array_filter(
                    explode(',', $fileUids),
                    static fn (string $value): bool => ctype_digit(trim($value)),
                ),
            );

            if ($fileUid > 0) {
                $selectedFileUids[] = $fileUid;
            }

            if ('' !== trim($folderIdentifier)) {
                $selectedFileUids = array_merge(
                    $selectedFileUids,
                    $this->assetReader->fileUidsFromFolder(trim($folderIdentifier)),
                );
            }

            $selectedFileUids = array_values(array_unique(array_filter(
                $selectedFileUids,
                static fn (int $uid): bool => $uid > 0,
            )));

            if ([] === $selectedFileUids) {
                throw new \RuntimeException(
                    'Please select at least one image or a folder containing supported images.',
                );
            }

            if (count($selectedFileUids) > 25) {
                throw new \RuntimeException('A maximum of 25 images can be analyzed in one batch.');
            }

            $assets = [];
            $debugItems = [];
            $meta = [];

            foreach ($selectedFileUids as $selectedFileUid) {
                $asset = $this->assetReader->read($selectedFileUid);
                $result = $this->client->analyzeAsset(
                    'sys_file:' . $selectedFileUid,
                    $asset['mimeType'],
                    $asset['contents'],
                    $language,
                    $asset['context'],
                    $provider,
                    '' === trim($model) ? null : $model,
                );

                $assets[] = [
                    'fileUid' => $selectedFileUid,
                    'fileName' => $asset['name'],
                    'publicUrl' => $asset['publicUrl'],
                    'metadata' => $result['metadata'],
                ];
                $meta = $result['meta'];

                if (isset($result['_debug'])) {
                    $debugItems[] = ['fileName' => $asset['name'], 'exchange' => $result['_debug']];
                }
            }

            $token = bin2hex(random_bytes(24));
            $languageId = $this->resolveLanguageId($language);

            $this->backendUser()->setAndSaveSessionData('contentflow_asset_' . $token, [
                'assets' => $assets,
                'languageId' => $languageId,
                'createdAt' => time(),
            ]);

            $module = $this->moduleTemplateFactory->create($this->request);
            $module->assignMultiple([
                'assets' => $assets,
                'assetCount' => count($assets),
                'previewToken' => $token,
                'meta' => $meta,
                'debugItems' => $debugItems,
            ]);

            return $module->renderResponse('AssetMetadata/Preview');
        } catch (\Throwable $exception) {
            $this->addFlashMessage(
                $exception->getMessage(),
                'Image analysis failed',
                ContextualFeedbackSeverity::ERROR,
            );

            return $this->redirect('index');
        }
    }

    public function applyAction(string $previewToken): ResponseInterface
    {
        $sessionKey = 'contentflow_asset_' . $previewToken;
        $preview = $this->backendUser()->getSessionData($sessionKey);

        try {
            if (!$this->client->hasProduct('asset_intelligence')) {
                throw new \RuntimeException('Asset Intelligence requires the Starter plan or higher.');
            }

            if (!is_array($preview) || !isset($preview['createdAt']) || time() - (int) $preview['createdAt'] > 3600) {
                throw new \RuntimeException('The asset preview expired. Please analyze the image again.');
            }

            if (!isset($preview['assets']) || !is_array($preview['assets'])) {
                throw new \RuntimeException('The asset preview is invalid. Please analyze the images again.');
            }

            $savedRecords = 0;

            foreach ($preview['assets'] as $asset) {
                $isValidAsset = is_array($asset)
                    && isset($asset['fileUid'], $asset['metadata'])
                    && is_array($asset['metadata']);

                if (!$isValidAsset) {
                    continue;
                }

                $this->writer->write((int) $asset['fileUid'], (int) $preview['languageId'], $asset['metadata']);
                ++$savedRecords;
            }

            if (0 === $savedRecords) {
                throw new \RuntimeException('No valid asset metadata was available to save.');
            }

            $this->backendUser()->setAndSaveSessionData($sessionKey, null);
            $this->addFlashMessage($savedRecords . ' image metadata record(s) were saved.', 'Asset metadata saved');
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
                $languages[$language->getLanguageId()] = [
                    'id' => $language->getLanguageId(),
                    'code' => $language->getLocale()->getLanguageCode(),
                    'title' => $language->getTitle(),
                ];
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
