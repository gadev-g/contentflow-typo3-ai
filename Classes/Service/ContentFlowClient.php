<?php

declare(strict_types=1);

namespace ContentFlow\Typo3Translation\Service;

use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

final readonly class ContentFlowClient
{
    private string $baseUrl;
    private string $apiKey;
    private bool $debugMode;

    public function __construct(private RequestFactory $requestFactory, ExtensionConfiguration $extensionConfiguration)
    {
        /** @var array{apiUrl?: string, apiKey?: string, debugMode?: bool|int|string} $configuration */
        $configuration = $extensionConfiguration->get('contentflow_translation');
        $this->baseUrl = trim((string) ($configuration['apiUrl'] ?? ''));
        $this->apiKey = trim((string) ($configuration['apiKey'] ?? ''));
        $this->debugMode = filter_var($configuration['debugMode'] ?? false, \FILTER_VALIDATE_BOOL);
    }

    /**
     * @param array<string, string> $fields
     * @return array{job_id: string, records: list<array{reference: string, fields: array<string, string>}>, meta: array<string, mixed>}
     */
    public function translate(string $reference, array $fields, string $sourceLanguage, string $targetLanguage, string $provider, ?string $model): array
    {
        if ('' === trim($this->apiKey)) {
            throw new \RuntimeException('The ContentFlow project API key is not configured in TYPO3 Extension Configuration.');
        }

        $payload = [
            'source_language' => $sourceLanguage,
            'target_language' => $targetLanguage,
            'provider' => $provider,
            'records' => [['reference' => $reference, 'fields' => $fields]],
        ];
        if (null !== $model && '' !== trim($model)) {
            $payload['model'] = $model;
        }
        $response = $this->requestFactory->request(rtrim($this->baseUrl, '/').'/api/v1/integrations/typo3/jobs', 'POST', [
            'headers' => ['X-API-Key' => $this->apiKey, 'Content-Type' => 'application/json'],
            'body' => json_encode($payload, \JSON_THROW_ON_ERROR),
            'timeout' => 180,
        ]);
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, \JSON_THROW_ON_ERROR);
        if ($response->getStatusCode() >= 300) {
            throw new \RuntimeException((string) ($body['error']['message'] ?? 'ContentFlow request failed.'));
        }
        $body = $this->withDebug($body, '/api/v1/integrations/typo3/jobs', $payload, $response->getStatusCode());

        /** @var array{job_id: string, records: list<array{reference: string, fields: array<string, string>}>, meta: array<string, mixed>} $body */
        return $body;
    }

    /** @return list<array{id: string, enabled: bool, configured: bool, capabilities: list<string>}> */
    public function providers(): array
    {
        if ('' === trim($this->apiKey)) {
            throw new \RuntimeException('The ContentFlow project API key is not configured in TYPO3 Extension Configuration.');
        }
        $response = $this->requestFactory->request(rtrim($this->baseUrl, '/').'/api/v1/providers', 'GET', [
            'headers' => ['X-API-Key' => $this->apiKey, 'Accept' => 'application/json'],
            'timeout' => 15,
        ]);
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, \JSON_THROW_ON_ERROR);
        if ($response->getStatusCode() >= 300) {
            throw new \RuntimeException((string) ($body['error']['message'] ?? 'Could not load active ContentFlow providers.'));
        }

        return is_array($body['items'] ?? null) ? array_values($body['items']) : [];
    }

    /** @return array{reference: string, metadata: array{title: string, alternative: string, description: string, keywords: string}, meta: array<string, mixed>} */
    public function analyzeAsset(string $reference, string $mimeType, string $contents, string $language, string $context, string $provider, ?string $model): array
    {
        if ('' === trim($this->apiKey)) {
            throw new \RuntimeException('The ContentFlow project API key is not configured in TYPO3 Extension Configuration.');
        }
        $payload = ['reference' => $reference, 'mime_type' => $mimeType, 'content_base64' => base64_encode($contents), 'language' => $language, 'context' => $context, 'provider' => $provider];
        if (null !== $model && '' !== trim($model)) {
            $payload['model'] = $model;
        }
        $response = $this->requestFactory->request(rtrim($this->baseUrl, '/').'/api/v1/integrations/typo3/assets/analyze', 'POST', [
            'headers' => ['X-API-Key' => $this->apiKey, 'Content-Type' => 'application/json'],
            'body' => json_encode($payload, \JSON_THROW_ON_ERROR),
            'timeout' => 180,
        ]);
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, \JSON_THROW_ON_ERROR);
        if ($response->getStatusCode() >= 300) {
            throw new \RuntimeException((string) ($body['error']['message'] ?? 'ContentFlow asset analysis failed.'));
        }
        $debugPayload = $payload;
        $debugPayload['content_base64'] = sprintf('[image Base64 omitted: %d encoded characters, %d source bytes]', strlen((string) $payload['content_base64']), strlen($contents));
        $body = $this->withDebug($body, '/api/v1/integrations/typo3/assets/analyze', $debugPayload, $response->getStatusCode());

        /** @var array{reference: string, metadata: array{title: string, alternative: string, description: string, keywords: string}, meta: array<string, mixed>} $body */
        return $body;
    }

    /**
     * @param array<string, mixed> $response
     * @param array<string, mixed> $requestPayload
     * @return array<string, mixed>
     */
    private function withDebug(array $response, string $endpoint, array $requestPayload, int $statusCode): array
    {
        if (!$this->debugMode) {
            return $response;
        }
        $response['_debug'] = [
            'method' => 'POST',
            'url' => rtrim($this->baseUrl, '/').$endpoint,
            'headers' => "Content-Type: application/json\nX-API-Key: [redacted]",
            'request' => json_encode($requestPayload, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR),
            'status' => $statusCode,
            'response' => json_encode($response, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR),
        ];

        return $response;
    }
}
