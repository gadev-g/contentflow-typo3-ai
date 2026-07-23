<?php

declare(strict_types=1);

namespace ContentFlow\Typo3Translation\Service;

use TYPO3\CMS\Core\Database\ConnectionPool;

final readonly class TranslatableRecordReader
{
    private const ALLOWED_TABLES = ['pages', 'tt_content', 'sys_file_reference', 'sys_file_metadata'];
    private const BLOCKED_FIELDS = [
        'uid',
        'pid',
        'tstamp',
        'crdate',
        'deleted',
        'hidden',
        'sorting',
        'sys_language_uid',
        'l10n_parent',
        'l10n_source',
        't3ver_oid',
        't3ver_wsid',
    ];
    private const TRANSLATABLE_TYPES = ['input', 'text', 'email'];

    public function __construct(private ConnectionPool $connectionPool)
    {
    }

    /** @return array<string, string> */
    public function read(string $table, int $uid): array
    {
        if (!$this->isAllowedTable($table)) {
            throw new \InvalidArgumentException('This table is not enabled for ContentFlow translations.');
        }

        $record = $this->record($table, $uid);

        $fields = [];

        foreach (($GLOBALS['TCA'][$table]['columns'] ?? []) as $name => $configuration) {
            $isEmptyOrBlocked = \in_array($name, self::BLOCKED_FIELDS, true)
                || !isset($record[$name])
                || !\is_string($record[$name])
                || '' === trim($record[$name]);

            if ($isEmptyOrBlocked) {
                continue;
            }

            $configuration = $this->effectiveFieldConfiguration($table, $record, $name, $configuration);
            $type = $configuration['config']['type'] ?? '';

            $isTranslatable = \in_array($type, self::TRANSLATABLE_TYPES, true)
                && !$this->isTechnicalInput($configuration['config'] ?? []);

            if ($isTranslatable) {
                $fields[$name] = $record[$name];
            }
        }

        return $fields;
    }

    /**
     * Resolve localizable TCA inline records such as Content Blocks
     * collections. The records are returned in their configured sort order.
     *
     * @return list<array{table: string, uid: int}>
     */
    public function relatedCollectionRecords(string $table, int $uid): array
    {
        if (!$this->isAllowedTable($table)) {
            return [];
        }

        $record = $this->record($table, $uid);
        $related = [];

        foreach (($GLOBALS['TCA'][$table]['columns'] ?? []) as $name => $configuration) {
            if (!\is_array($configuration)) {
                continue;
            }

            $configuration = $this->effectiveFieldConfiguration($table, $record, $name, $configuration);
            $config = $configuration['config'] ?? [];

            if (!\is_array($config) || 'inline' !== ($config['type'] ?? null)) {
                continue;
            }

            $foreignTable = $config['foreign_table'] ?? null;
            $foreignField = $config['foreign_field'] ?? null;

            if (
                !\is_string($foreignTable)
                || !\is_string($foreignField)
                || !$this->isAllowedCollectionTable($foreignTable)
            ) {
                continue;
            }

            $query = $this->connectionPool->getQueryBuilderForTable($foreignTable);
            $conditions = [
                $query->expr()->eq(
                    $foreignField,
                    $query->createNamedParameter($uid, \Doctrine\DBAL\ParameterType::INTEGER),
                ),
            ];

            $foreignTableField = $config['foreign_table_field'] ?? null;

            if (\is_string($foreignTableField) && '' !== $foreignTableField) {
                $conditions[] = $query->expr()->eq(
                    $foreignTableField,
                    $query->createNamedParameter($table),
                );
            }

            foreach (($config['foreign_match_fields'] ?? []) as $field => $value) {
                if (!\is_string($field)) {
                    continue;
                }

                $conditions[] = $query->expr()->eq(
                    $field,
                    $query->createNamedParameter($value),
                );
            }

            $languageField = $GLOBALS['TCA'][$foreignTable]['ctrl']['languageField'] ?? null;

            if (\is_string($languageField) && '' !== $languageField) {
                $conditions[] = $query->expr()->eq(
                    $languageField,
                    $query->createNamedParameter(0, \Doctrine\DBAL\ParameterType::INTEGER),
                );
            }

            $query
                ->select('uid')
                ->from($foreignTable)
                ->where(...$conditions);

            $sortField = $config['foreign_sortby'] ?? null;

            if (\is_string($sortField) && '' !== $sortField) {
                $query->orderBy($sortField);
            } else {
                $query->orderBy('uid');
            }

            foreach ($query->executeQuery()->fetchFirstColumn() as $relatedUid) {
                $related[] = [
                    'table' => $foreignTable,
                    'uid' => (int) $relatedUid,
                ];
            }
        }

        return $related;
    }

    /** @return list<string> */
    public function allowedTables(): array
    {
        return self::ALLOWED_TABLES;
    }

    /**
     * Custom content types can override the field configuration below
     * TCA/types/<CType>/columnsOverrides. Use that effective configuration so
     * project-specific fields are treated exactly like core fields.
     *
     * @param array<string, mixed> $record
     * @param array<string, mixed> $configuration
     * @return array<string, mixed>
     */
    private function effectiveFieldConfiguration(
        string $table,
        array $record,
        string $field,
        array $configuration,
    ): array {
        $typeField = $GLOBALS['TCA'][$table]['ctrl']['type'] ?? null;
        if (!\is_string($typeField) || !isset($record[$typeField])) {
            return $configuration;
        }

        $recordType = (string) $record[$typeField];
        $override = $GLOBALS['TCA'][$table]['types'][$recordType]['columnsOverrides'][$field] ?? null;

        return \is_array($override) ? array_replace_recursive($configuration, $override) : $configuration;
    }

    /** @param array<string, mixed> $config */
    private function isTechnicalInput(array $config): bool
    {
        $eval = array_filter(array_map('trim', explode(',', (string) ($config['eval'] ?? ''))));

        return [] !== array_intersect(
            $eval,
            ['int', 'double2', 'date', 'datetime', 'time', 'timesec', 'year', 'unixTimestamp'],
        );
    }

    /** @return array<string, mixed> */
    private function record(string $table, int $uid): array
    {
        $query = $this->connectionPool->getQueryBuilderForTable($table);
        $record = $query
            ->select('*')
            ->from($table)
            ->where($query->expr()->eq(
                'uid',
                $query->createNamedParameter($uid, \Doctrine\DBAL\ParameterType::INTEGER),
            ))
            ->executeQuery()
            ->fetchAssociative();

        if (!$record) {
            throw new \RuntimeException('Record not found.');
        }

        return $record;
    }

    private function isAllowedTable(string $table): bool
    {
        return \in_array($table, self::ALLOWED_TABLES, true)
            || $this->isAllowedCollectionTable($table);
    }

    private function isAllowedCollectionTable(string $table): bool
    {
        if (
            1 !== preg_match('/^[a-zA-Z0-9_]+$/D', $table)
            || !isset($GLOBALS['TCA'][$table])
        ) {
            return false;
        }

        $control = $GLOBALS['TCA'][$table]['ctrl'] ?? [];

        return \is_string($control['languageField'] ?? null)
            && \is_string($control['transOrigPointerField'] ?? null)
            && $this->isReferencedInlineTable($table);
    }

    private function isReferencedInlineTable(string $table): bool
    {
        foreach ($GLOBALS['TCA'] as $parentConfiguration) {
            if (!\is_array($parentConfiguration)) {
                continue;
            }

            foreach (($parentConfiguration['columns'] ?? []) as $fieldConfiguration) {
                if (
                    \is_array($fieldConfiguration)
                    && 'inline' === ($fieldConfiguration['config']['type'] ?? null)
                    && $table === ($fieldConfiguration['config']['foreign_table'] ?? null)
                ) {
                    return true;
                }
            }
        }

        return false;
    }
}
