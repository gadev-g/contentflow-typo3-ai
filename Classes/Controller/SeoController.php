<?php

declare(strict_types=1);

namespace ContentFlow\Typo3Translation\Controller;

use ContentFlow\Typo3Translation\Service\ContentFlowClient;
use ContentFlow\Typo3Translation\Service\TranslatableRecordReader;
use Doctrine\DBAL\ParameterType;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

final class SeoController extends ActionController
{
    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly ContentFlowClient $client,
        private readonly TranslatableRecordReader $reader,
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    public function indexAction(): ResponseInterface
    {
        $module = $this->moduleTemplateFactory->create($this->request);
        try {
            $context = $this->client->integrationContext();
            $providers = is_array($context['items'] ?? null) ? array_values($context['items']) : [];
        } catch (\Throwable $exception) {
            $context = [];
            $providers = [];

            $this->addFlashMessage(
                $exception->getMessage(),
                'Provider configuration unavailable',
                ContextualFeedbackSeverity::ERROR,
            );
        }

        if (true !== ($context['entitlements']['products']['seo_intelligence'] ?? false)) {
            $module->assign('plan', $context['entitlements']['plan'] ?? 'free');

            return $module->renderResponse('Seo/Upgrade');
        }

        $module->assignMultiple([
            'providers' => $providers,
            'defaultProvider' => $providers[0]['id'] ?? '',
        ]);

        return $module->renderResponse('Seo/Index');
    }

    public function previewAction(int $uid, string $language, string $provider, string $model = ''): ResponseInterface
    {
        try {
            if (!$this->client->hasProduct('seo_intelligence')) {
                throw new \RuntimeException('SEO Intelligence requires the Starter plan or higher.');
            }

            if ($uid <= 0) {
                throw new \RuntimeException('Please select a TYPO3 page.');
            }

            $pageFields = $this->reader->read('pages', $uid);
            $title = trim((string) ($pageFields['title'] ?? ''));

            if ('' === $title) {
                throw new \RuntimeException('The selected page has no title.');
            }

            $content = $this->pageContent($uid, $pageFields);
            $result = $this->client->analyzeSeo(
                $uid,
                $title,
                $content,
                $language,
                $provider,
                '' === trim($model) ? null : $model,
            );

            $metadata = is_array($result['metadata'] ?? null) ? $result['metadata'] : [];
            $token = bin2hex(random_bytes(24));

            $this->backendUser()->setAndSaveSessionData('contentflow_seo_' . $token, [
                'pageUid' => $uid,
                'metadata' => $metadata,
                'createdAt' => time(),
            ]);

            $module = $this->moduleTemplateFactory->create($this->request);
            $module->assignMultiple([
                'pageUid' => $uid,
                'pageTitle' => $title,
                'metadata' => $metadata,
                'meta' => $result['meta'] ?? [],
                'debug' => $result['_debug'] ?? null,
                'previewToken' => $token,
            ]);

            return $module->renderResponse('Seo/Preview');
        } catch (\Throwable $exception) {
            $this->addFlashMessage($exception->getMessage(), 'SEO analysis failed', ContextualFeedbackSeverity::ERROR);

            return $this->redirect('index');
        }
    }

    public function applyAction(string $previewToken): ResponseInterface
    {
        try {
            if (!$this->client->hasProduct('seo_intelligence')) {
                throw new \RuntimeException('SEO Intelligence requires the Starter plan or higher.');
            }

            $sessionKey = 'contentflow_seo_' . $previewToken;
            $preview = $this->backendUser()->getSessionData($sessionKey);

            if (
                !is_array($preview)
                || !isset($preview['createdAt'])
                || time() - (int) $preview['createdAt'] > 3600
                || !is_array($preview['metadata'] ?? null)
            ) {
                throw new \RuntimeException('The SEO preview expired. Please analyze the page again.');
            }

            $metadata = $preview['metadata'];
            $schemaOrg = json_encode(
                $metadata['schema_org'] ?? [],
                \JSON_THROW_ON_ERROR
                | \JSON_PRETTY_PRINT
                | \JSON_UNESCAPED_SLASHES
                | \JSON_UNESCAPED_UNICODE,
            );

            $this->connectionPool->getConnectionForTable('pages')->update('pages', [
                'seo_title' => (string) ($metadata['seo_title'] ?? ''),
                'description' => (string) ($metadata['meta_description'] ?? ''),
                'tx_contentflow_focus_keywords' => (string) ($metadata['focus_keywords'] ?? ''),
                'tx_contentflow_schema_org' => $schemaOrg,
            ], ['uid' => (int) $preview['pageUid']], ['uid' => ParameterType::INTEGER]);

            $this->backendUser()->setAndSaveSessionData($sessionKey, null);
            $this->addFlashMessage('SEO metadata and Schema.org JSON-LD were saved to the page.', 'SEO metadata saved');
        } catch (\Throwable $exception) {
            $this->addFlashMessage($exception->getMessage(), 'Saving failed', ContextualFeedbackSeverity::ERROR);
        }

        return $this->redirect('index');
    }

    /** @param array<string, string> $pageFields */
    private function pageContent(int $pageUid, array $pageFields): string
    {
        $parts = array_values($pageFields);
        $query = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $uids = $query
            ->select('uid')
            ->from('tt_content')
            ->where(
                $query->expr()->eq(
                    'pid',
                    $query->createNamedParameter($pageUid, ParameterType::INTEGER),
                ),
            )
            ->orderBy('sorting')
            ->executeQuery()
            ->fetchFirstColumn();

        foreach ($uids as $uid) {
            foreach ($this->reader->read('tt_content', (int) $uid) as $value) {
                $parts[] = $value;
            }
        }

        return trim(preg_replace('/\s+/', ' ', strip_tags(implode("\n", $parts))) ?? '');
    }

    private function backendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }
}
