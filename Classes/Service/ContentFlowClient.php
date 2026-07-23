<?php

declare(strict_types=1);

namespace ContentFlow\Typo3Translation\Service;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\RequestFactory;

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
     * @return array<string, mixed>
     */
    public function translate(
        string $reference,
        array $fields,
        string $sourceLanguage,
        string $targetLanguage,
        string $provider,
        ?string $model,
    ): array {
        return $this->translateBatch(
            [['reference' => $reference, 'fields' => $fields]],
            $sourceLanguage,
            $targetLanguage,
            $provider,
            $model,
        );
    }

    /**
     * @param list<array{reference: string, fields: array<string, string>}> $records
     * @return array<string, mixed>
     */
    public function translateBatch(
        array $records,
        string $sourceLanguage,
        string $targetLanguage,
        string $provider,
        ?string $model,
    ): array {
        if ('' === trim($this->apiKey)) {
            throw new \RuntimeException(
                'The ContentFlow project API key is not configured in TYPO3 Extension Configuration.',
            );
        }

        $payload = [
            'source_language' => $sourceLanguage,
            'target_language' => $targetLanguage,
            'provider' => $provider,
            'records' => $records,
        ];
        if (null !== $model && '' !== trim($model)) {
            $payload['model'] = $model;
        }

        $response = $this->requestFactory->request(
            rtrim($this->baseUrl, '/') . '/api/v1/integrations/typo3/jobs',
            'POST',
            [
                'headers' => ['X-API-Key' => $this->apiKey, 'Content-Type' => 'application/json'],
                'body' => json_encode($payload, \JSON_THROW_ON_ERROR),
                'timeout' => 180,
            ],
        );

        $body = $this->decodeResponse(
            $response,
            '/api/v1/integrations/typo3/jobs',
            [
                'reference' => $records[0]['reference'] ?? 'translation-batch',
                'provider' => $provider,
                'model' => $model,
            ],
        );

        if ($response->getStatusCode() >= 300) {
            throw new \RuntimeException((string) ($body['error']['message'] ?? 'ContentFlow request failed.'));
        }

        $body = $this->withDebug($body, '/api/v1/integrations/typo3/jobs', $payload, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        return $body;
    }

    /** @return list<array{id: string, enabled: bool, configured: bool, capabilities: list<string>}> */
    public function providers(): array
    {
        $context = $this->integrationContext();

        return is_array($context['items'] ?? null) ? array_values($context['items']) : [];
    }

    /** @return array<string, mixed> */
    public function integrationContext(): array
    {
        if ('' === trim($this->apiKey)) {
            throw new \RuntimeException(
                'The ContentFlow project API key is not configured in TYPO3 Extension Configuration.',
            );
        }

        $response = $this->requestFactory->request(rtrim($this->baseUrl, '/') . '/api/v1/providers', 'GET', [
            'headers' => ['X-API-Key' => $this->apiKey, 'Accept' => 'application/json'],
            'timeout' => 15,
        ]);
        $body = $this->decodeResponse(
            $response,
            '/api/v1/providers',
            ['reference' => 'provider-context'],
        );

        if ($response->getStatusCode() >= 300) {
            throw new \RuntimeException(
                (string) ($body['error']['message'] ?? 'Could not load active ContentFlow providers.'),
            );
        }

        return $body;
    }

    public function hasProduct(string $product): bool
    {
        $context = $this->integrationContext();

        return true === ($context['entitlements']['products'][$product] ?? false);
    }

    /** @return array<string, mixed> */
    public function analyzeSeo(
        int $pageUid,
        string $title,
        string $content,
        string $language,
        string $provider,
        ?string $model,
        string $url = '',
    ): array {
        $payload = [
            'reference' => 'pages:' . $pageUid,
            'title' => $title,
            'content' => $content,
            'language' => $language,
            'provider' => $provider,
            'url' => $url,
        ];
        if (null !== $model && '' !== trim($model)) {
            $payload['model'] = $model;
        }

        $response = $this->requestFactory->request(
            rtrim($this->baseUrl, '/') . '/api/v1/integrations/typo3/seo/analyze',
            'POST',
            [
                'headers' => ['X-API-Key' => $this->apiKey, 'Content-Type' => 'application/json'],
                'body' => json_encode($payload, \JSON_THROW_ON_ERROR),
                'timeout' => 180,
            ],
        );
        $body = $this->decodeResponse(
            $response,
            '/api/v1/integrations/typo3/seo/analyze',
            [
                'reference' => 'pages:' . $pageUid,
                'provider' => $provider,
                'model' => $model,
            ],
        );
        if ($response->getStatusCode() >= 300) {
            throw new \RuntimeException((string) ($body['error']['message'] ?? 'ContentFlow SEO analysis failed.'));
        }

        return $this->withDebug($body, '/api/v1/integrations/typo3/seo/analyze', $payload, $response->getStatusCode());
    }

    /** @return array<string, mixed> */
    public function analyzeAsset(
        string $reference,
        string $mimeType,
        string $contents,
        string $language,
        string $context,
        string $provider,
        ?string $model,
    ): array {
        if ('' === trim($this->apiKey)) {
            throw new \RuntimeException(
                'The ContentFlow project API key is not configured in TYPO3 Extension Configuration.',
            );
        }
        $payload = [
            'reference' => $reference,
            'mime_type' => $mimeType,
            'content_base64' => base64_encode($contents),
            'language' => $language,
            'context' => $context,
            'provider' => $provider,
        ];

        if (null !== $model && '' !== trim($model)) {
            $payload['model'] = $model;
        }

        $response = $this->requestFactory->request(
            rtrim($this->baseUrl, '/') . '/api/v1/integrations/typo3/assets/analyze',
            'POST',
            [
                'headers' => ['X-API-Key' => $this->apiKey, 'Content-Type' => 'application/json'],
                'body' => json_encode($payload, \JSON_THROW_ON_ERROR),
                'timeout' => 180,
            ],
        );

        $body = $this->decodeResponse(
            $response,
            '/api/v1/integrations/typo3/assets/analyze',
            [
                'reference' => $reference,
                'provider' => $provider,
                'model' => $model,
            ],
        );

        if ($response->getStatusCode() >= 300) {
            throw new \RuntimeException((string) ($body['error']['message'] ?? 'ContentFlow asset analysis failed.'));
        }

        $debugPayload = $payload;
        $debugPayload['content_base64'] = sprintf(
            '[image Base64 omitted: %d encoded characters, %d source bytes]',
            strlen((string) $payload['content_base64']),
            strlen($contents),
        );

        $body = $this->withDebug(
            $body,
            '/api/v1/integrations/typo3/assets/analyze',
            $debugPayload,
            $response->getStatusCode(),
        );

        /** @var array<string, mixed> $body */
        return $body;
    }

    /**
     * @param array{reference?: string, provider?: string, model?: ?string} $context
     * @return array<string, mixed>
     */
    private function decodeResponse(ResponseInterface $response, string $endpoint, array $context): array
    {
        $rawBody = (string) $response->getBody();

        try {
            $decoded = json_decode($rawBody, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $contentType = $response->getHeaderLine('Content-Type');
            $excerpt = $this->responseExcerpt($rawBody);
            $serverRequestId = $this->responseRequestId($rawBody);

            $this->reportClientError(
                $endpoint,
                $response->getStatusCode(),
                $contentType,
                $excerpt,
                $context,
                $exception->getMessage(),
                $serverRequestId,
            );

            $details = sprintf(
                'ContentFlow returned an invalid JSON response for %s (HTTP %d%s).',
                $endpoint,
                $response->getStatusCode(),
                '' === $contentType ? '' : ', ' . $contentType,
            );

            if ('' !== $excerpt) {
                $details .= ' Response: ' . $excerpt;
            }

            throw new \RuntimeException($details, 0, $exception);
        }

        if (!\is_array($decoded)) {
            throw new \RuntimeException(
                sprintf('ContentFlow returned an unsupported JSON response for %s.', $endpoint),
            );
        }

        return $decoded;
    }

    /**
     * @param array{reference?: string, provider?: string, model?: ?string} $context
     */
    private function reportClientError(
        string $endpoint,
        int $statusCode,
        string $contentType,
        string $responseExcerpt,
        array $context,
        string $message,
        ?string $serverRequestId,
    ): void {
        if ('' === $this->apiKey) {
            return;
        }

        $payload = [
            'reference' => $context['reference'] ?? null,
            'provider' => $context['provider'] ?? null,
            'model' => $context['model'] ?? null,
            'endpoint' => $endpoint,
            'server_request_id' => $serverRequestId,
            'http_status' => $statusCode,
            'content_type' => $contentType,
            'response_excerpt' => $responseExcerpt,
            'error_code' => 'invalid_api_response',
            'message' => 'The integration received invalid JSON: ' . $message,
        ];

        try {
            $this->requestFactory->request(
                rtrim($this->baseUrl, '/') . '/api/v1/integrations/typo3/errors',
                'POST',
                [
                    'headers' => ['X-API-Key' => $this->apiKey, 'Content-Type' => 'application/json'],
                    'body' => json_encode($payload, \JSON_THROW_ON_ERROR),
                    'timeout' => 10,
                ],
            );
        } catch (\Throwable) {
            // A diagnostic request must never hide the original integration error.
        }
    }

    private function responseExcerpt(string $body): string
    {
        $plainText = html_entity_decode(strip_tags($body), \ENT_QUOTES | \ENT_HTML5);
        $plainText = preg_replace('/\s+/', ' ', $plainText) ?? $plainText;

        return mb_substr(trim($plainText), 0, 1000);
    }

    private function responseRequestId(string $body): ?string
    {
        if (!preg_match('/"id"\s*:\s*"([0-9a-f-]{36})"/i', $body, $matches)) {
            return null;
        }

        return $matches[1];
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
            'url' => rtrim($this->baseUrl, '/') . $endpoint,
            'headers' => "Content-Type: application/json\nX-API-Key: [redacted]",
            'request' => json_encode(
                $requestPayload,
                \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
            ),
            'status' => $statusCode,
            'response' => json_encode(
                $response,
                \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
            ),
        ];

        return $response;
    }
}
