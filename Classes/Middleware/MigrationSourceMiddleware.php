<?php

declare(strict_types=1);

namespace ContentFlow\Typo3Translation\Middleware;

use ContentFlow\Typo3Translation\Service\MigrationTokenService;
use ContentFlow\Typo3Translation\Service\SourcePageExporter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Resource\ResourceFactory;

final readonly class MigrationSourceMiddleware implements MiddlewareInterface
{
    public function __construct(
        private MigrationTokenService $tokens,
        private SourcePageExporter $exporter,
        private ResourceFactory $resourceFactory,
        private ExtensionConfiguration $extensionConfiguration,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        if ('/contentflow/migration/export' === $path && 'POST' === strtoupper($request->getMethod())) {
            return $this->export($request);
        }
        if (
            'GET' === strtoupper($request->getMethod())
            && preg_match('#^/contentflow/migration/media/(\d+)$#', $path, $match)
        ) {
            return $this->media($request, (int) $match[1]);
        }

        return $handler->handle($request);
    }

    private function export(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->tokens->validate($this->bearerToken($request))) {
            return new JsonResponse(['error' => ['code' => 'unauthorized', 'message' => 'Invalid migration token.']], 401);
        }
        try {
            $payload = json_decode((string) $request->getBody(), true, 32, \JSON_THROW_ON_ERROR);
            $sourceUrl = \is_array($payload) ? trim((string) ($payload['source_url'] ?? '')) : '';

            if (false === filter_var($sourceUrl, \FILTER_VALIDATE_URL)) {
                return new JsonResponse(['error' => ['code' => 'validation_failed', 'message' => 'source_url is required.']], 422);
            }
            if (strtolower((string) parse_url($sourceUrl, \PHP_URL_HOST)) !== strtolower($request->getUri()->getHost())) {
                return new JsonResponse(['error' => ['code' => 'host_mismatch', 'message' => 'The URL must belong to this TYPO3 host.']], 422);
            }

            $baseUrl = $request->getUri()->getScheme() . '://' . $request->getUri()->getAuthority();

            return new JsonResponse($this->exporter->export($sourceUrl, $baseUrl));
        } catch (\JsonException) {
            return new JsonResponse(['error' => ['code' => 'invalid_json', 'message' => 'Request body must be JSON.']], 400);
        } catch (\Throwable $exception) {
            return new JsonResponse(['error' => ['code' => 'export_failed', 'message' => $exception->getMessage()]], 422);
        }
    }

    private function media(ServerRequestInterface $request, int $fileUid): ResponseInterface
    {
        $query = $request->getQueryParams();
        $expires = (int) ($query['expires'] ?? 0);
        $signature = (string) ($query['signature'] ?? '');

        $configuration = $this->extensionConfiguration->get('contentflow_translation');
        $secret = trim((string) ($configuration['migrationSigningSecret'] ?? ''))
            ?: (string) ($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? '');
        $expected = hash_hmac('sha256', $fileUid . ':' . $expires, $secret);

        if ($expires < time() || '' === $signature || !hash_equals($expected, $signature)) {
            return new JsonResponse(['error' => ['code' => 'invalid_signature', 'message' => 'The media link expired.']], 403);
        }
        try {
            $file = $this->resourceFactory->getFileObject($fileUid);
            $response = new Response();
            $response->getBody()->write($file->getContents());

            return $response
                ->withHeader('Content-Type', $file->getMimeType())
                ->withHeader('Content-Disposition', 'attachment; filename="' . addslashes($file->getName()) . '"');
        } catch (\Throwable) {
            return new JsonResponse(['error' => ['code' => 'not_found', 'message' => 'Media file not found.']], 404);
        }
    }

    private function bearerToken(ServerRequestInterface $request): string
    {
        $authorization = $request->getHeaderLine('Authorization');

        return preg_match('/^Bearer\s+(.+)$/i', $authorization, $match) ? trim($match[1]) : '';
    }
}
