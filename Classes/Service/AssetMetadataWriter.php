<?php

declare(strict_types=1);

namespace ContentFlow\Typo3Translation\Service;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class AssetMetadataWriter
{
    public function __construct(private ConnectionPool $connectionPool, private LocalizedRecordWriter $localizedWriter)
    {
    }

    /** @param array<string, string> $metadata */
    public function write(int $fileUid, int $languageId, array $metadata): int
    {
        $query = $this->connectionPool->getQueryBuilderForTable('sys_file_metadata');
        $query->getRestrictions()->removeAll();

        $metadataUid = $query
            ->select('uid')
            ->from('sys_file_metadata')
            ->where(
                $query->expr()->eq(
                    'file',
                    $query->createNamedParameter($fileUid, ParameterType::INTEGER),
                ),
                $query->expr()->eq(
                    'sys_language_uid',
                    $query->createNamedParameter(0, ParameterType::INTEGER),
                ),
            )
            ->executeQuery()
            ->fetchOne();

        if (false === $metadataUid) {
            throw new \RuntimeException(
                'TYPO3 has no metadata record for the selected file. Re-index the file and try again.',
            );
        }

        if ($languageId > 0) {
            return $this->localizedWriter->write('sys_file_metadata', (int) $metadataUid, $languageId, $metadata);
        }

        $handler = GeneralUtility::makeInstance(DataHandler::class);
        $handler->start(['sys_file_metadata' => [(int) $metadataUid => $metadata]], []);
        $handler->process_datamap();

        if ([] !== $handler->errorLog) {
            throw new \RuntimeException(implode(' ', $handler->errorLog));
        }

        return (int) $metadataUid;
    }
}
