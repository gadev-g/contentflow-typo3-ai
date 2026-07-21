<?php

declare(strict_types=1);

namespace ContentFlow\Typo3Translation\Service;

use TYPO3\CMS\Core\Database\ConnectionPool;

final readonly class TranslatableRecordReader
{
    private const ALLOWED_TABLES = ['pages', 'tt_content', 'sys_file_reference', 'sys_file_metadata'];
    private const BLOCKED_FIELDS = ['uid', 'pid', 'tstamp', 'crdate', 'deleted', 'hidden', 'sorting', 'sys_language_uid', 'l10n_parent', 'l10n_source', 't3ver_oid', 't3ver_wsid'];
    private const TRANSLATABLE_TYPES = ['input', 'text', 'email'];

    public function __construct(private ConnectionPool $connectionPool)
    {
    }

    /** @return array<string, string> */
    public function read(string $table, int $uid): array
    {
        if (!\in_array($table, self::ALLOWED_TABLES, true) || !isset($GLOBALS['TCA'][$table])) {
            throw new \InvalidArgumentException('This table is not enabled for ContentFlow translations.');
        }
        $query = $this->connectionPool->getQueryBuilderForTable($table);
        $record = $query->select('*')->from($table)->where($query->expr()->eq('uid', $query->createNamedParameter($uid, \Doctrine\DBAL\ParameterType::INTEGER)))->executeQuery()->fetchAssociative();
        if (!$record) {
            throw new \RuntimeException('Record not found.');
        }
        $fields = [];
        foreach (($GLOBALS['TCA'][$table]['columns'] ?? []) as $name => $configuration) {
            if (\in_array($name, self::BLOCKED_FIELDS, true) || !isset($record[$name]) || !\is_string($record[$name]) || '' === trim($record[$name])) {
                continue;
            }
            $configuration = $this->effectiveFieldConfiguration($table, $record, $name, $configuration);
            $type = $configuration['config']['type'] ?? '';
            if (\in_array($type, self::TRANSLATABLE_TYPES, true) && !$this->isTechnicalInput($configuration['config'] ?? [])) {
                $fields[$name] = $record[$name];
            }
        }

        return $fields;
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
    private function effectiveFieldConfiguration(string $table, array $record, string $field, array $configuration): array
    {
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

        return [] !== array_intersect($eval, ['int', 'double2', 'date', 'datetime', 'time', 'timesec', 'year', 'unixTimestamp']);
    }
}
