<?php

declare(strict_types=1);

namespace ContentFlow\Typo3Translation\Service;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final readonly class SourceMediaImporter
{
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/svg+xml',
        'application/pdf',
    ];

    public function __construct(
        private RequestFactory $requestFactory,
        private ResourceFactory $resourceFactory,
    ) {
    }

    public function import(array $media): int
    {
        $expectedHash = strtolower((string) ($media['sha256'] ?? ''));
        $existingUid = $this->existingFileUid($expectedHash);

        if ($existingUid > 0) {
            return $existingUid;
        }

        $downloadUrl = (string) ($media['download_url'] ?? '');
        $expectedMimeType = strtolower((string) ($media['mime_type'] ?? ''));
        $expectedSize = (int) ($media['size'] ?? 0);

        if (
            false === filter_var($downloadUrl, \FILTER_VALIDATE_URL)
            || !\in_array($expectedMimeType, self::ALLOWED_MIME_TYPES, true)
            || $expectedSize <= 0
            || $expectedSize > 20_000_000
        ) {
            throw new \RuntimeException('A source media file failed validation.');
        }

        $response = $this->requestFactory->request($downloadUrl, 'GET', [
            'allow_redirects' => false,
            'timeout' => 60,
        ]);
        $contents = (string) $response->getBody();

        if (
            200 !== $response->getStatusCode()
            || '' === $contents
            || \strlen($contents) > 20_000_000
            || ('' !== $expectedHash && !hash_equals($expectedHash, hash('sha256', $contents)))
        ) {
            throw new \RuntimeException('A source media download was incomplete or modified.');
        }

        $temporaryFile = GeneralUtility::tempnam('contentflow-migration-');
        file_put_contents($temporaryFile, $contents);

        try {
            $storage = $this->resourceFactory->getDefaultStorage();

            if (null === $storage) {
                throw new \RuntimeException('No writable TYPO3 file storage is available.');
            }

            $folder = $storage->hasFolder('contentflow-migration')
                ? $storage->getFolder('contentflow-migration')
                : $storage->createFolder('contentflow-migration');
            $file = $storage->addFile(
                $temporaryFile,
                $folder,
                $this->safeFileName((string) ($media['name'] ?? 'migration-file')),
            );

            return $file->getUid();
        } finally {
            if (is_file($temporaryFile)) {
                unlink($temporaryFile);
            }
        }
    }

    private function existingFileUid(string $sha256): int
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $sha256)) {
            return 0;
        }

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('sys_file');
        $rows = $connection->fetchAllAssociative(
            'SELECT uid, identifier FROM sys_file WHERE missing = 0 ORDER BY uid DESC',
        );

        foreach ($rows as $row) {
            try {
                $file = $this->resourceFactory->getFileObject((int) $row['uid']);

                if (hash_equals($sha256, hash('sha256', $file->getContents()))) {
                    return (int) $row['uid'];
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return 0;
    }

    private function safeFileName(string $name): string
    {
        $name = preg_replace('/[^a-zA-Z0-9._-]+/', '-', basename($name)) ?? 'migration-file';

        return trim($name, '-.') ?: 'migration-file';
    }
}
