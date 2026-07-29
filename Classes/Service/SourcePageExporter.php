<?php

declare(strict_types=1);

namespace ContentFlow\Typo3Translation\Service;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\FileRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final readonly class SourcePageExporter
{
    private const TECHNICAL_FIELDS = [
        'uid', 'pid', 'tstamp', 'crdate', 'cruser_id', 'deleted', 'hidden',
        'starttime', 'endtime', 'fe_group', 'sorting', 'l18n_parent', 'l10n_source',
    ];

    private string $signingSecret;

    public function __construct(
        ExtensionConfiguration $extensionConfiguration,
        private FileRepository $fileRepository,
    ) {
        /** @var array{migrationSigningSecret?: string} $configuration */
        $configuration = $extensionConfiguration->get('contentflow_translation');
        $this->signingSecret = trim((string) ($configuration['migrationSigningSecret'] ?? ''))
            ?: (string) ($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? '');
    }

    /** @return array<string, mixed> */
    public function export(string $sourceUrl, string $baseUrl): array
    {
        $page = $this->resolvePage($sourceUrl);
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tt_content');
        $rows = $queryBuilder
            ->select('*')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq(
                    'pid',
                    $queryBuilder->createNamedParameter((int) $page['uid'], Connection::PARAM_INT),
                ),
                $queryBuilder->expr()->eq(
                    'sys_language_uid',
                    $queryBuilder->createNamedParameter(0, Connection::PARAM_INT),
                ),
                $queryBuilder->expr()->eq(
                    'hidden',
                    $queryBuilder->createNamedParameter(0, Connection::PARAM_INT),
                ),
            )
            ->orderBy('colPos')
            ->addOrderBy('sorting')
            ->executeQuery()
            ->fetchAllAssociative();

        return [
            'schema_version' => '1.0',
            'source' => [
                'url' => $sourceUrl,
                'title' => (string) ($page['title'] ?? ''),
                'page_uid' => (int) $page['uid'],
            ],
            'page' => $this->editableFields('pages', $page),
            'elements' => array_map(
                fn (array $row): array => $this->exportRecord('tt_content', $row, $baseUrl, 0),
                $rows,
            ),
            'exported_at' => (new \DateTimeImmutable())->format(\DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function resolvePage(string $sourceUrl): array
    {
        $path = rawurldecode((string) parse_url($sourceUrl, \PHP_URL_PATH));
        $path = '/'.trim($path, '/');
        $path = '/' === $path ? '/' : rtrim($path, '/');
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('pages');
        $row = $queryBuilder
            ->select('*')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('slug', $queryBuilder->createNamedParameter($path)),
                $queryBuilder->expr()->eq(
                    'hidden',
                    $queryBuilder->createNamedParameter(0, Connection::PARAM_INT),
                ),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if (false === $row) {
            throw new \RuntimeException('No TYPO3 page matches the supplied source URL.');
        }

        return $row;
    }

    /** @return array<string, mixed> */
    private function exportRecord(string $table, array $row, string $baseUrl, int $depth): array
    {
        $record = [
            'source_table' => $table,
            'source_uid' => (int) ($row['uid'] ?? 0),
            'type' => 'tt_content' === $table ? (string) ($row['CType'] ?? '') : $table,
            'column' => (int) ($row['colPos'] ?? 0),
            'sorting' => (int) ($row['sorting'] ?? 0),
            'fields' => $this->editableFields($table, $row),
            'relations' => [],
            'media' => [],
        ];

        if ($depth >= 5) {
            return $record;
        }

        foreach (($GLOBALS['TCA'][$table]['columns'] ?? []) as $field => $column) {
            $configuration = $column['config'] ?? [];

            if (
                'inline' === ($configuration['type'] ?? null)
                && isset($configuration['foreign_table'])
                && 'sys_file_reference' !== $configuration['foreign_table']
            ) {
                $record['relations'][$field] = $this->inlineChildren(
                    (string) $configuration['foreign_table'],
                    (string) ($configuration['foreign_field'] ?? ''),
                    (int) ($row['uid'] ?? 0),
                    $baseUrl,
                    $depth + 1,
                );
            }

            if (\in_array($configuration['type'] ?? null, ['file', 'inline'], true)) {
                $record['media'] = array_merge(
                    $record['media'],
                    $this->mediaReferences($table, (int) ($row['uid'] ?? 0), (string) $field, $baseUrl),
                );
            }
        }

        return $record;
    }

    /** @return list<array<string, mixed>> */
    private function inlineChildren(
        string $table,
        string $foreignField,
        int $parentUid,
        string $baseUrl,
        int $depth,
    ): array {
        if ('' === $foreignField || !isset($GLOBALS['TCA'][$table])) {
            return [];
        }

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
        $rows = $queryBuilder
            ->select('*')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq(
                    $foreignField,
                    $queryBuilder->createNamedParameter($parentUid, Connection::PARAM_INT),
                ),
            )
            ->orderBy((string) ($GLOBALS['TCA'][$table]['ctrl']['sortby'] ?? 'uid'))
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            fn (array $row): array => $this->exportRecord($table, $row, $baseUrl, $depth),
            $rows,
        );
    }

    /** @return list<array<string, mixed>> */
    private function mediaReferences(string $table, int $uid, string $field, string $baseUrl): array
    {
        try {
            $references = $this->fileRepository->findByRelation($table, $field, $uid);
        } catch (\Throwable) {
            return [];
        }

        $media = [];

        foreach ($references as $reference) {
            $file = $reference->getOriginalFile();
            $expires = time() + 3600;
            $signature = hash_hmac('sha256', $file->getUid().':'.$expires, $this->signingSecret);
            $media[] = [
                'source_file_uid' => $file->getUid(),
                'field' => $field,
                'name' => $file->getName(),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'sha256' => hash('sha256', $file->getContents()),
                'metadata' => $reference->getProperties(),
                'download_url' => rtrim($baseUrl, '/').'/contentflow/migration/media/'
                    .$file->getUid().'?expires='.$expires.'&signature='.$signature,
            ];
        }

        return $media;
    }

    /** @return array<string, string> */
    private function editableFields(string $table, array $row): array
    {
        $fields = [];

        foreach ($row as $field => $value) {
            if (
                \in_array($field, self::TECHNICAL_FIELDS, true)
                || !isset($GLOBALS['TCA'][$table]['columns'][$field])
                || (!\is_scalar($value) && null !== $value)
            ) {
                continue;
            }

            $fields[$field] = null === $value ? '' : (string) $value;
        }

        return $fields;
    }
}
