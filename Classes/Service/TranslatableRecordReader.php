<?php

declare(strict_types=1);

namespace ContentFlow\Typo3Translation\Service;

use TYPO3\CMS\Core\Database\ConnectionPool;

final readonly class TranslatableRecordReader
{
    private const ALLOWED_TABLES = ['pages', 'tt_content', 'sys_file_reference', 'sys_file_metadata'];
    private const BLOCKED_FIELDS = ['uid', 'pid', 'tstamp', 'crdate', 'deleted', 'hidden', 'sorting', 'sys_language_uid', 'l10n_parent', 'l10n_source', 't3ver_oid', 't3ver_wsid'];

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
            $type = $configuration['config']['type'] ?? '';
            if (\in_array($type, ['input', 'text', 'email', 'link'], true)) {
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
}
