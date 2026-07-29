# ContentFlow Legacy Source Connector for TYPO3 8.7

This small source-only extension is intentionally separate from the modern ContentFlow TYPO3
extension. It supports TYPO3 8.7 and PHP 7 and never writes to the legacy database.

1. Copy this directory to `typo3conf/ext/contentflow_legacy_source`.
2. Rename the directory to `contentflow_legacy_source` and activate it in Extension Manager.
3. Generate a token and its SHA-256 hash:

   ```bash
   php -r '$token="cfmi_".bin2hex(random_bytes(32)); echo $token.PHP_EOL.hash("sha256", $token).PHP_EOL;'
   ```

4. Store only the second line in Extension Configuration as `migrationTokenHash`.
5. Copy the first line once into the Migration Assistant on the new TYPO3 installation.

The modern connector first calls `/contentflow/migration/export` and automatically falls back to
`/?eID=contentflow_migration_export` for TYPO3 8. The legacy resolver supports classic `?id=123`
URLs and RealURL page segments stored in `alias` or `tx_realurl_pathsegment`.

For public pages where the connector cannot be installed, select **Public HTML page scraper** in
the Migration Assistant. HTML mode does not preserve TYPO3-specific IRRE, FAL, or container
relations.
