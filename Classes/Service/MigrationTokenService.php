<?php

declare(strict_types=1);

namespace ContentFlow\Typo3Translation\Service;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class MigrationTokenService
{
    private const TABLE = 'tx_contentflow_migration_token';

    public function generate(string $label): string
    {
        $plainToken = 'cfmi_'.bin2hex(random_bytes(32));
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, [
            'pid' => 0,
            'label' => mb_substr(trim($label) ?: 'Migration token', 0, 120),
            'token_hash' => password_hash($plainToken, \PASSWORD_DEFAULT),
            'token_prefix' => substr($plainToken, 0, 13),
            'created_at' => time(),
            'last_used_at' => 0,
            'revoked' => 0,
        ]);

        return $plainToken;
    }

    public function validate(string $plainToken): bool
    {
        if (!str_starts_with($plainToken, 'cfmi_')) {
            return false;
        }

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable(self::TABLE);
        $rows = $connection->fetchAllAssociative(
            'SELECT uid, token_hash FROM '.self::TABLE.' WHERE revoked = 0 AND token_prefix = ?',
            [substr($plainToken, 0, 13)],
        );

        foreach ($rows as $row) {
            if (!password_verify($plainToken, (string) $row['token_hash'])) {
                continue;
            }

            $connection->update(self::TABLE, ['last_used_at' => time()], ['uid' => (int) $row['uid']]);

            return true;
        }

        return false;
    }

    public function revoke(int $uid): void
    {
        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable(self::TABLE)
            ->update(self::TABLE, ['revoked' => 1], ['uid' => $uid]);
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable(self::TABLE)
            ->fetchAllAssociative(
                'SELECT uid, label, token_prefix, created_at, last_used_at, revoked'
                .' FROM '.self::TABLE.' ORDER BY created_at DESC',
            );
    }
}
