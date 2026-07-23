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
        $preselectedUid = $this->request->hasArgument('uid') ? (int) $this->request->getArgument('uid') : 0;
        $preselectedTargetLanguage = $this->request->hasArgument('targetLanguage')
            ? (string) $this->request->getArgument('targetLanguage')
            : '';
        try {
            $context = $this->client->integrationContext();
            $providers = \is_array($context['items'] ?? null) ? array_values($context['items']) : [];
        } catch (\Throwable $exception) {
            $context = [];
            $providers = [];
            $this->addFlashMessage(
                $exception->getMessage(),
                'Provider configuration unavailable',
                ContextualFeedbackSeverity::ERROR,
            );
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
            'products' => $context['entitlements']['products'] ?? [],
            'preselectedUid' => $preselectedUid,
            'preselectedTable' => $preselectedUid > 0 ? 'pages' : '',
            'preselectedScope' => $preselectedUid > 0 ? 'page' : '',
            'preselectedTargetLanguage' => $preselectedTargetLanguage,
        ]);

        return $module->renderResponse('Translation/Index');
    }

    public function previewAction(
        string $table,
        int $uid,
        string $sourceLanguage,
        string $targetLanguage,
        string $provider = '',
        string $model = '',
        string $scope = 'single',
        string $uids = '',
    ): ResponseInterface {
        try {
            $targetLanguageId = $this->resolveLanguageId($targetLanguage);
            $selection = $this->expandNestedContent($this->resolveSelection($scope, $table, $uid, $uids));
            $requestRecords = [];
            $previewRecords = [];

            foreach ($selection as $record) {
                $sourceFields = $this->reader->read($record['table'], $record['uid']);
                $reference = $record['table'].':'.$record['uid'];
                $previewRecords[$reference] = $record + [
                    'reference' => $reference,
                    'sourceFields' => $sourceFields,
                    'translatedFields' => [],
                    'structuralOnly' => [] === $sourceFields,
                ];

                if ([] !== $sourceFields) {
                    $requestRecords[] = ['reference' => $reference, 'fields' => $sourceFields];
                }
            }

            $result = [] !== $requestRecords
                ? $this->client->translateBatch(
                    $requestRecords,
                    $sourceLanguage,
                    $targetLanguage,
                    $provider,
                    $model,
                )
                : [
                    'job_id' => 'typo3-structure-'.bin2hex(random_bytes(6)),
                    'records' => [],
                    'meta' => [
                        'provider' => 'TYPO3',
                        'model' => 'structural localization',
                        'usage' => [
                            'input_tokens' => 0,
                            'output_tokens' => 0,
                        ],
                        'translation_settings' => [
                            'instructions_applied' => false,
                            'glossary_entries_applied' => 0,
                        ],
                    ],
                    '_debug' => null,
                ];

            foreach ($result['records'] ?? [] as $translatedRecord) {
                $reference = (string) ($translatedRecord['reference'] ?? '');
                if (isset($previewRecords[$reference])) {
                    $previewRecords[$reference]['translatedFields'] = $translatedRecord['fields'] ?? [];
                }
            }

            foreach ($previewRecords as $record) {
                if ([] !== $record['sourceFields'] && [] === $record['translatedFields']) {
                    throw new \RuntimeException('The provider did not return a translation for '.$record['reference'].'.');
                }
            }

            $previewRecords = array_values($previewRecords);
            $token = bin2hex(random_bytes(24));

            $this->backendUser()->setAndSaveSessionData('contentflow_translation_'.$token, [
                'records' => $previewRecords,
                'targetLanguageId' => $targetLanguageId,
                'jobId' => $result['job_id'],
                'createdAt' => time(),
            ]);

            $module = $this->moduleTemplateFactory->create($this->request);
            $module->assignMultiple([
                'records' => $previewRecords,
                'recordCount' => \count($previewRecords),
                'previewToken' => $token,
                'jobId' => $result['job_id'],
                'meta' => $result['meta'],
                'debug' => $result['_debug'] ?? null,
            ]);

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

            $localizedUids = [];

            foreach ($preview['records'] ?? [] as $record) {
                $localizedUids[] = $this->writer->write(
                    (string) $record['table'],
                    (int) $record['uid'],
                    (int) $preview['targetLanguageId'],
                    (array) $record['translatedFields'],
                );
            }

            if ([] === $localizedUids) {
                throw new \RuntimeException('The preview does not contain any records.');
            }

            $this->backendUser()->setAndSaveSessionData($sessionKey, null);
            $this->addFlashMessage(
                \count($localizedUids).' reviewed translation(s) were saved.',
                'Translations saved',
            );
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
            ->where(
                $query->expr()->eq(
                    'file',
                    $query->createNamedParameter($selectedUid, \Doctrine\DBAL\ParameterType::INTEGER),
                ),
            )
            ->executeQuery()
            ->fetchOne();

        if (false === $uid) {
            throw new \RuntimeException('No metadata record exists for the selected asset.');
        }

        return (int) $uid;
    }

    /** @return list<array{table: string, uid: int}> */
    private function resolveSelection(string $scope, string $table, int $uid, string $uids): array
    {
        if ('multiple' === $scope) {
            $selected = array_values(array_unique(array_filter(array_map('intval', explode(',', $uids)))));

            if ([] === $selected) {
                throw new \RuntimeException('Please select at least one content element.');
            }

            return array_map(
                static fn (int $selectedUid): array => [
                    'table' => 'tt_content',
                    'uid' => $selectedUid,
                ],
                $selected,
            );
        }

        if ('page' === $scope) {
            $selection = [['table' => 'pages', 'uid' => $uid]];
            $query = $this->connectionPool->getQueryBuilderForTable('tt_content');
            $contentUids = $query
                ->select('uid')
                ->from('tt_content')
                ->where(
                    $query->expr()->eq(
                        'pid',
                        $query->createNamedParameter($uid, \Doctrine\DBAL\ParameterType::INTEGER),
                    ),
                    $query->expr()->eq(
                        'sys_language_uid',
                        $query->createNamedParameter(0, \Doctrine\DBAL\ParameterType::INTEGER),
                    ),
                    $query->expr()->eq(
                        'hidden',
                        $query->createNamedParameter(0, \Doctrine\DBAL\ParameterType::INTEGER),
                    ),
                )
                ->orderBy('sorting')
                ->executeQuery()
                ->fetchFirstColumn();

            foreach ($contentUids as $contentUid) {
                $selection[] = ['table' => 'tt_content', 'uid' => (int) $contentUid];
            }

            return $selection;
        }

        return [['table' => $table, 'uid' => $this->resolveRecordUid($table, $uid)]];
    }

    /**
     * Expand selected containers recursively. This supports EXT:container,
     * Gridelements, Flux and common project-specific parent relations while
     * keeping the result stable and free of duplicates.
     *
     * @param list<array{table: string, uid: int}> $selection
     *
     * @return list<array{table: string, uid: int}>
     */
    private function expandNestedContent(array $selection): array
    {
        $expanded = [];
        $visited = [];
        $parentRelations = $this->availableContentParentRelations();
        $queue = $this->includeContainerAncestors($selection, $parentRelations);

        while ([] !== $queue) {
            $record = array_shift($queue);

            if (!\is_array($record)) {
                continue;
            }

            $key = $record['table'].':'.$record['uid'];

            if (isset($visited[$key])) {
                continue;
            }

            $visited[$key] = true;
            $expanded[] = $record;

            foreach ($this->reader->relatedCollectionRecords($record['table'], $record['uid']) as $relatedRecord) {
                $queue[] = $relatedRecord;
            }

            if ('tt_content' !== $record['table']) {
                continue;
            }

            foreach ($parentRelations as $relation) {
                $query = $this->connectionPool->getQueryBuilderForTable('tt_content');
                $conditions = [
                    $query->expr()->eq(
                        $relation['field'],
                        $query->createNamedParameter($record['uid'], \Doctrine\DBAL\ParameterType::INTEGER),
                    ),
                    $query->expr()->eq(
                        'sys_language_uid',
                        $query->createNamedParameter(0, \Doctrine\DBAL\ParameterType::INTEGER),
                    ),
                ];

                if (isset($relation['tableField'])) {
                    $conditions[] = $query->expr()->eq(
                        $relation['tableField'],
                        $query->createNamedParameter('tt_content'),
                    );
                }

                $childUids = $query
                    ->select('uid')
                    ->from('tt_content')
                    ->where(...$conditions)
                    ->orderBy('sorting')
                    ->executeQuery()
                    ->fetchFirstColumn();

                foreach ($childUids as $childUid) {
                    $queue[] = ['table' => 'tt_content', 'uid' => (int) $childUid];
                }
            }
        }

        return $expanded;
    }

    /**
     * A child can only be localized after its connected container translation
     * exists. Add missing ancestors and keep them before the selected child.
     *
     * @param list<array{table: string, uid: int}>            $selection
     * @param list<array{field: string, tableField?: string}> $relations
     *
     * @return list<array{table: string, uid: int}>
     */
    private function includeContainerAncestors(array $selection, array $relations): array
    {
        $ordered = [];
        $added = [];

        $add = function (array $record) use (&$add, &$ordered, &$added, $relations): void {
            $key = $record['table'].':'.$record['uid'];

            if (isset($added[$key])) {
                return;
            }

            $added[$key] = true;

            if ('tt_content' === $record['table']) {
                $parentUid = $this->contentParentUid($record['uid'], $relations);

                if ($parentUid > 0) {
                    $add(['table' => 'tt_content', 'uid' => $parentUid]);
                }
            }

            $ordered[] = $record;
        };

        foreach ($selection as $record) {
            $add($record);
        }

        return $ordered;
    }

    /** @param list<array{field: string, tableField?: string}> $relations */
    private function contentParentUid(int $uid, array $relations): int
    {
        foreach ($relations as $relation) {
            $query = $this->connectionPool->getQueryBuilderForTable('tt_content');
            $fields = [$relation['field']];
            if (isset($relation['tableField'])) {
                $fields[] = $relation['tableField'];
            }

            $record = $query
                ->select(...$fields)
                ->from('tt_content')
                ->where(
                    $query->expr()->eq(
                        'uid',
                        $query->createNamedParameter($uid, \Doctrine\DBAL\ParameterType::INTEGER),
                    ),
                )
                ->executeQuery()
                ->fetchAssociative();

            if (!$record || (int) ($record[$relation['field']] ?? 0) <= 0) {
                continue;
            }

            if (isset($relation['tableField']) && 'tt_content' !== ($record[$relation['tableField']] ?? null)) {
                continue;
            }

            return (int) $record[$relation['field']];
        }

        return 0;
    }

    /** @return list<array{field: string, tableField?: string}> */
    private function availableContentParentRelations(): array
    {
        $schemaManager = $this->connectionPool->getConnectionForTable('tt_content')->createSchemaManager();
        $columns = array_change_key_case($schemaManager->listTableColumns('tt_content'), \CASE_LOWER);
        $relations = [];

        foreach (['tx_container_parent', 'tx_gridelements_container', 'tx_flux_parent'] as $field) {
            if (isset($columns[$field])) {
                $relations[] = ['field' => $field];
            }
        }

        if (isset($columns['parentid'], $columns['parenttable'])) {
            $relations[] = ['field' => 'parentid', 'tableField' => 'parenttable'];
        }

        return $relations;
    }
}
