<?php

declare(strict_types=1);

namespace ContentFlow\Typo3Translation\Service;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\RequestFactory;

final readonly class SourceConnectorClient
{
    private bool $allowPrivateHosts;

    public function __construct(
        private RequestFactory $requestFactory,
        ExtensionConfiguration $extensionConfiguration,
    ) {
        /** @var array{allowPrivateSourceHosts?: bool|int|string} $configuration */
        $configuration = $extensionConfiguration->get('contentflow_translation');
        $this->allowPrivateHosts = filter_var(
            $configuration['allowPrivateSourceHosts'] ?? false,
            \FILTER_VALIDATE_BOOL,
        );
    }

    /** @return array<string, mixed> */
    public function export(string $sourceUrl, string $migrationToken): array
    {
        $parts = parse_url(trim($sourceUrl));

        if (
            !\is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || !\in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
        ) {
            throw new \RuntimeException('Enter a valid TYPO3 source URL.');
        }

        $this->assertSafeHost((string) $parts['host']);
        $origin = $parts['scheme'].'://'.$parts['host'];

        if (isset($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        $response = $this->requestFactory->request(
            $origin.'/contentflow/migration/export',
            'POST',
            [
                'headers' => [
                    'Authorization' => 'Bearer '.trim($migrationToken),
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode(['source_url' => $sourceUrl], \JSON_THROW_ON_ERROR),
                'timeout' => 60,
                'allow_redirects' => false,
            ],
        );
        $body = json_decode((string) $response->getBody(), true);

        if (!\is_array($body)) {
            throw new \RuntimeException('The source connector returned an invalid response.');
        }

        if ($response->getStatusCode() >= 300) {
            throw new \RuntimeException((string) ($body['error']['message'] ?? 'The source connector rejected the export.'));
        }

        if ('1.0' !== ($body['schema_version'] ?? null) || !\is_array($body['elements'] ?? null)) {
            throw new \RuntimeException('The source connector returned an unsupported export schema.');
        }

        return $body;
    }

    private function assertSafeHost(string $host): void
    {
        if ($this->allowPrivateHosts) {
            return;
        }

        $addresses = filter_var($host, \FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);

        if ([] === $addresses) {
            throw new \RuntimeException('The source TYPO3 host could not be resolved.');
        }

        foreach ($addresses as $address) {
            if (false === filter_var(
                $address,
                \FILTER_VALIDATE_IP,
                \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE,
            )) {
                throw new \RuntimeException('Private source hosts are disabled. Enable them explicitly in Extension Configuration.');
            }
        }
    }
}
