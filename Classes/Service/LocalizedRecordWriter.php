<?php

declare(strict_types=1);

namespace ContentFlow\Typo3Translation\Service;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final readonly class LocalizedRecordWriter
{
    public function __construct(private ConnectionPool $connectionPool)
    {
    }

    /** @param array<string, string> $fields */
    public function write(string $table, int $sourceUid, int $languageId, array $fields): int
    {
        $control = $GLOBALS['TCA'][$table]['ctrl'] ?? [];
        $languageField = $control['languageField'] ?? null;
        $parentField = $control['transOrigPointerField'] ?? null;

        if (!\is_string($languageField) || !\is_string($parentField)) {
            throw new \RuntimeException('Table is not configured for connected localization.');
        }

        $query = $this->connectionPool->getQueryBuilderForTable($table);
        $query->getRestrictions()->removeAll();
        $localizedUid = $query->select('uid')->from($table)->where(
            $query->expr()->eq(
                $parentField,
                $query->createNamedParameter($sourceUid, \Doctrine\DBAL\ParameterType::INTEGER),
            ),
            $query->expr()->eq(
                $languageField,
                $query->createNamedParameter($languageId, \Doctrine\DBAL\ParameterType::INTEGER),
            ),
        )->executeQuery()->fetchOne();

        if (false === $localizedUid) {
            $handler = GeneralUtility::makeInstance(DataHandler::class);
            $handler->start([], [$table => [$sourceUid => ['localize' => $languageId]]]);
            $handler->process_cmdmap();

            if ([] !== $handler->errorLog) {
                throw new \RuntimeException(implode(' ', $handler->errorLog));
            }

            $query = $this->connectionPool->getQueryBuilderForTable($table);
            $query->getRestrictions()->removeAll();
            $localizedUid = $query->select('uid')->from($table)->where(
                $query->expr()->eq(
                    $parentField,
                    $query->createNamedParameter($sourceUid, \Doctrine\DBAL\ParameterType::INTEGER),
                ),
                $query->expr()->eq(
                    $languageField,
                    $query->createNamedParameter($languageId, \Doctrine\DBAL\ParameterType::INTEGER),
                ),
            )->executeQuery()->fetchOne();
        }

        if (false === $localizedUid) {
            throw new \RuntimeException('TYPO3 did not create the localized record.');
        }

        if ([] !== $fields) {
            $handler = GeneralUtility::makeInstance(DataHandler::class);
            $handler->start([$table => [(int) $localizedUid => $fields]], []);
            $handler->process_datamap();

            if ([] !== $handler->errorLog) {
                throw new \RuntimeException(implode(' ', $handler->errorLog));
            }
        }

        return (int) $localizedUid;
    }
}
