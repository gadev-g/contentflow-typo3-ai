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

        $localizedUid = $this->findLocalizedUid(
            $table,
            $sourceUid,
            $languageId,
            $languageField,
            $parentField,
            $control,
        );

        if (false === $localizedUid) {
            $localizedUid = $this->restoreDeletedLocalizedRecord(
                $table,
                $sourceUid,
                $languageId,
                $languageField,
                $parentField,
                $control,
            );
        }

        if (false === $localizedUid) {
            $handler = GeneralUtility::makeInstance(DataHandler::class);
            $handler->start([], [$table => [$sourceUid => ['localize' => $languageId]]]);
            $handler->process_cmdmap();

            if ([] !== $handler->errorLog) {
                throw new \RuntimeException(implode(' ', $handler->errorLog));
            }

            $localizedUid = $this->findLocalizedUid(
                $table,
                $sourceUid,
                $languageId,
                $languageField,
                $parentField,
                $control,
            );
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

    /**
     * Restore a soft-deleted translation before writing its newly approved
     * fields. This avoids both invisible updates and duplicate localizations.
     *
     * @param array<string, mixed> $control
     *
     * @return int|string|false
     */
    private function restoreDeletedLocalizedRecord(
        string $table,
        int $sourceUid,
        int $languageId,
        string $languageField,
        string $parentField,
        array $control,
    ): int|string|false {
        $deleteField = $control['delete'] ?? null;

        if (!\is_string($deleteField) || '' === $deleteField) {
            return false;
        }

        $localizedUid = $this->findLocalizedUid(
            $table,
            $sourceUid,
            $languageId,
            $languageField,
            $parentField,
            $control,
            true,
        );

        if (false === $localizedUid) {
            return false;
        }

        $handler = GeneralUtility::makeInstance(DataHandler::class);
        $handler->start([], [$table => [(int) $localizedUid => ['undelete' => 1]]]);
        $handler->process_cmdmap();

        if ([] !== $handler->errorLog) {
            throw new \RuntimeException(implode(' ', $handler->errorLog));
        }

        return $this->findLocalizedUid(
            $table,
            $sourceUid,
            $languageId,
            $languageField,
            $parentField,
            $control,
        );
    }

    /**
     * Deleted translations must not be reused. Otherwise DataHandler updates a
     * record that remains hidden in TYPO3 and the approved translation appears
     * not to have been saved.
     *
     * @param array<string, mixed> $control
     *
     * @return int|string|false
     */
    private function findLocalizedUid(
        string $table,
        int $sourceUid,
        int $languageId,
        string $languageField,
        string $parentField,
        array $control,
        bool $deleted = false,
    ): int|string|false {
        $query = $this->connectionPool->getQueryBuilderForTable($table);
        $query->getRestrictions()->removeAll();
        $conditions = [
            $query->expr()->eq(
                $parentField,
                $query->createNamedParameter($sourceUid, \Doctrine\DBAL\ParameterType::INTEGER),
            ),
            $query->expr()->eq(
                $languageField,
                $query->createNamedParameter($languageId, \Doctrine\DBAL\ParameterType::INTEGER),
            ),
        ];

        $deleteField = $control['delete'] ?? null;

        if (\is_string($deleteField) && '' !== $deleteField) {
            $conditions[] = $query->expr()->eq(
                $deleteField,
                $query->createNamedParameter($deleted ? 1 : 0, \Doctrine\DBAL\ParameterType::INTEGER),
            );
        }

        return $query
            ->select('uid')
            ->from($table)
            ->where(...$conditions)
            ->executeQuery()
            ->fetchOne();
    }
}
