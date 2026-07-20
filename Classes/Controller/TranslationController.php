<?php

declare(strict_types=1);

namespace ContentFlow\Typo3Translation\Controller;

use ContentFlow\Typo3Translation\Service\ContentFlowClient;
use ContentFlow\Typo3Translation\Service\LocalizedRecordWriter;
use ContentFlow\Typo3Translation\Service\TranslatableRecordReader;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

final class TranslationController extends ActionController
{
    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly TranslatableRecordReader $reader,
        private readonly ContentFlowClient $client,
        private readonly LocalizedRecordWriter $writer,
        private readonly SiteFinder $siteFinder,
        private readonly ConnectionPool $connectionPool,
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
        $module->assignMultiple([
            'tables' => [
                'pages' => 'Page',
                'tt_content' => 'Content element',
                'sys_file_metadata' => 'Asset metadata',
                'sys_file_reference' => 'File reference',
            ],
            'languages' => $this->availableLanguages(),
            'providers' => $providers,
            'defaultProvider' => $providers[0]['id'] ?? '',
        ]);

        return $module->renderResponse('Translation/Index');
    }

    public function previewAction(string $table, int $uid, string $sourceLanguage, string $targetLanguage, string $provider = '', string $model = ''): ResponseInterface
    {
        try {
            $targetLanguageId = $this->resolveLanguageId($targetLanguage);
            $uid = $this->resolveRecordUid($table, $uid);
            $sourceFields = $this->reader->read($table, $uid);
            if ([] === $sourceFields) {
                throw new \RuntimeException('No translatable fields were found in this record.');
            }
            $result = $this->client->translate($table.':'.$uid, $sourceFields, $sourceLanguage, $targetLanguage, $provider, $model);
            $translatedFields = $result['records'][0]['fields'] ?? [];
            $token = bin2hex(random_bytes(24));
            $this->backendUser()->setAndSaveSessionData('contentflow_translation_'.$token, [
                'table' => $table,
                'uid' => $uid,
                'targetLanguageId' => $targetLanguageId,
                'sourceFields' => $sourceFields,
                'translatedFields' => $translatedFields,
                'jobId' => $result['job_id'],
                'createdAt' => time(),
            ]);
            $module = $this->moduleTemplateFactory->create($this->request);
            $module->assignMultiple(['table' => $table, 'uid' => $uid, 'sourceFields' => $sourceFields, 'translatedFields' => $translatedFields, 'previewToken' => $token, 'jobId' => $result['job_id'], 'meta' => $result['meta'], 'debug' => $result['_debug'] ?? null]);

            return $module->renderResponse('Translation/Preview');
        } catch (\Throwable $exception) {
            $this->addFlashMessage($exception->getMessage(), 'Translation failed', ContextualFeedbackSeverity::ERROR);

            return $this->redirect('index');
        }
    }

    public function applyAction(string $previewToken): ResponseInterface
    {
        $sessionKey = 'contentflow_translation_'.$previewToken;
        $preview = $this->backendUser()->getSessionData($sessionKey);
        try {
            if (!\is_array($preview) || !isset($preview['createdAt']) || time() - (int) $preview['createdAt'] > 3600) {
                throw new \RuntimeException('The preview expired. Please create a new translation preview.');
            }
            /** @var array<string, string> $translatedFields */
            $translatedFields = $preview['translatedFields'];
            $localizedUid = $this->writer->write((string) $preview['table'], (int) $preview['uid'], (int) $preview['targetLanguageId'], $translatedFields);
            $this->backendUser()->setAndSaveSessionData($sessionKey, null);
            $this->addFlashMessage('The reviewed translation was saved as localized record '.$localizedUid.'.', 'Translation saved');
        } catch (\Throwable $exception) {
            $this->addFlashMessage($exception->getMessage(), 'Saving failed', ContextualFeedbackSeverity::ERROR);
        }

        return $this->redirect('index');
    }

    private function backendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }

    /** @return list<array{id: int, code: string, title: string, isDefault: bool}> */
    private function availableLanguages(): array
    {
        $languages = [];
        foreach ($this->siteFinder->getAllSites() as $site) {
            foreach ($site->getAllLanguages() as $language) {
                $id = $language->getLanguageId();
                if (isset($languages[$id])) {
                    continue;
                }
                $languages[$id] = [
                    'id' => $id,
                    'code' => $language->getLocale()->getLanguageCode(),
                    'title' => $language->getTitle(),
                    'isDefault' => 0 === $id,
                ];
            }
        }
        ksort($languages);

        return array_values($languages);
    }

    private function resolveLanguageId(string $languageCode): int
    {
        foreach ($this->availableLanguages() as $language) {
            if ($language['code'] === $languageCode && !$language['isDefault']) {
                return $language['id'];
            }
        }

        throw new \RuntimeException('Please select an available TYPO3 target language.');
    }

    private function resolveRecordUid(string $table, int $selectedUid): int
    {
        if ('sys_file_metadata' !== $table) {
            return $selectedUid;
        }

        $query = $this->connectionPool->getQueryBuilderForTable('sys_file_metadata');
        $uid = $query->select('uid')
            ->from('sys_file_metadata')
            ->where($query->expr()->eq('file', $query->createNamedParameter($selectedUid, \Doctrine\DBAL\ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchOne();
        if (false === $uid) {
            throw new \RuntimeException('No metadata record exists for the selected asset.');
        }

        return (int) $uid;
    }
}
