<?php

declare(strict_types=1);

namespace ContentFlow\Typo3Translation\Controller;

use ContentFlow\Typo3Translation\Service\ContentFlowClient;
use ContentFlow\Typo3Translation\Service\HtmlSourceScraper;
use ContentFlow\Typo3Translation\Service\MigrationContentWriter;
use ContentFlow\Typo3Translation\Service\MigrationTokenService;
use ContentFlow\Typo3Translation\Service\SourceConnectorClient;
use ContentFlow\Typo3Translation\Service\TargetContentSchema;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

final class MigrationController extends ActionController
{
    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly ContentFlowClient $client,
        private readonly SourceConnectorClient $sourceConnector,
        private readonly HtmlSourceScraper $htmlScraper,
        private readonly TargetContentSchema $targetSchema,
        private readonly MigrationContentWriter $writer,
        private readonly MigrationTokenService $tokens,
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {
    }

    public function indexAction(): ResponseInterface
    {
        $module = $this->moduleTemplateFactory->create($this->request);
        $targetPageUid = $this->request->hasArgument('id') ? (int) $this->request->getArgument('id') : 0;

        try {
            $context = $this->client->integrationContext();
            $providers = \is_array($context['items'] ?? null) ? array_values($context['items']) : [];
        } catch (\Throwable $exception) {
            $context = [];
            $providers = [];
            $this->addFlashMessage(
                $exception->getMessage(),
                'Provider configuration unavailable',
                ContextualFeedbackSeverity::ERROR,
            );
        }

        if (true !== ($context['entitlements']['products']['content_migration'] ?? false)) {
            $module->assignMultiple([
                'plan' => $context['entitlements']['plan'] ?? 'free',
                'migrationTokens' => $this->tokens->all(),
            ]);

            return $module->renderResponse('Migration/Upgrade');
        }

        $module->assignMultiple([
            'providers' => $providers,
            'defaultProvider' => $providers[0]['id'] ?? '',
            'targetPageUid' => $targetPageUid,
            'targetTypes' => $this->targetSchema->availableTypes(),
            'migrationTokens' => $this->tokens->all(),
            'hasConfiguredMigrationToken' => $this->sourceConnector->hasConfiguredToken(),
        ]);

        return $module->renderResponse('Migration/Index');
    }

    public function previewAction(
        string $sourceUrl,
        string $sourceMode,
        int $targetPageUid,
        string $provider,
        string $migrationToken = '',
        string $model = '',
        bool $saveMigrationToken = false,
    ): ResponseInterface {
        try {
            if (!$this->client->hasProduct('content_migration')) {
                throw new \RuntimeException('Content Migration requires the Starter plan or higher.');
            }

            if ($targetPageUid <= 0) {
                throw new \RuntimeException('Please select a target TYPO3 page.');
            }

            if (!\in_array($sourceMode, ['connector', 'html'], true)) {
                throw new \RuntimeException('Select a valid source method.');
            }

            if (
                'connector' === $sourceMode
                && '' === trim($migrationToken)
                && !$this->sourceConnector->hasConfiguredToken()
            ) {
                throw new \RuntimeException('Enter the migration token from the source TYPO3 installation.');
            }

            if ('connector' === $sourceMode && $saveMigrationToken && '' !== trim($migrationToken)) {
                $this->setConfiguredSourceToken(trim($migrationToken));
            }

            $export = 'html' === $sourceMode
                ? $this->htmlScraper->scrape($sourceUrl)
                : $this->sourceConnector->export($sourceUrl, $migrationToken);
            $source = \is_array($export['source'] ?? null) ? $export['source'] : [];
            $elements = \is_array($export['elements'] ?? null) ? array_values($export['elements']) : [];

            if ([] === $elements) {
                throw new \RuntimeException('The source page contains no exportable content elements.');
            }

            $blocks = [];

            foreach ($elements as $element) {
                if (!\is_array($element)) {
                    continue;
                }

                $blocks[] = [
                    'type' => (string) ($element['type'] ?? 'text'),
                    'content' => json_encode(
                        [
                            'fields' => $element['fields'] ?? [],
                            'relations' => $element['relations'] ?? [],
                            'media' => $element['media'] ?? [],
                        ],
                        \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
                    ),
                ];
            }

            $targetTypes = $this->targetSchema->availableTypes();
            $result = $this->client->planMigration(
                (string) ($source['url'] ?? $sourceUrl),
                (string) ($source['title'] ?? $sourceUrl),
                $blocks,
                $targetTypes,
                $provider,
                '' === trim($model) ? null : $model,
            );
            $items = \is_array($result['items'] ?? null) ? array_values($result['items']) : [];

            if ([] === $items) {
                throw new \RuntimeException('No suitable target content elements could be planned.');
            }

            $typeLabels = [];

            foreach ($targetTypes as $targetType) {
                $typeLabels[$targetType['type']] = $targetType['label'];
            }

            foreach ($items as $itemIndex => &$item) {
                if (\is_array($item)) {
                    $item['target_label'] = $typeLabels[(string) ($item['target_type'] ?? '')]
                        ?? (string) ($item['target_type'] ?? '');
                    $sourceIndex = (int) ($item['source_index'] ?? -1);
                    $item['source_record'] = $elements[$sourceIndex] ?? [];
                    $item['enabled'] = true;
                    $item['order'] = $itemIndex;
                }
            }

            unset($item);

            $token = bin2hex(random_bytes(24));
            $this->backendUser()->setAndSaveSessionData('contentflow_migration_'.$token, [
                'sourceUrl' => (string) ($source['url'] ?? $sourceUrl),
                'sourceTitle' => (string) ($source['title'] ?? $sourceUrl),
                'targetPageUid' => $targetPageUid,
                'sourceMode' => $sourceMode,
                'items' => $items,
                'targetTypes' => $targetTypes,
                'migrationId' => (string) ($result['migration_id'] ?? ''),
                'createdAt' => time(),
            ]);

            $module = $this->moduleTemplateFactory->create($this->request);
            $module->assignMultiple([
                'sourceUrl' => (string) ($source['url'] ?? $sourceUrl),
                'sourceTitle' => (string) ($source['title'] ?? $sourceUrl),
                'sourceBlockCount' => \count($blocks),
                'targetPageUid' => $targetPageUid,
                'items' => $items,
                'targetTypes' => $targetTypes,
                'previewToken' => $token,
                'meta' => $result['meta'] ?? [],
                'debug' => $result['_debug'] ?? null,
            ]);

            return $module->renderResponse('Migration/Preview');
        } catch (\Throwable $exception) {
            $this->addFlashMessage(
                $exception->getMessage(),
                'Migration preview failed',
                ContextualFeedbackSeverity::ERROR,
            );

            return $this->redirect('index');
        }
    }

    /** @param array<int|string, mixed> $items */
    public function applyAction(string $previewToken, array $items = []): ResponseInterface
    {
        $sessionKey = 'contentflow_migration_'.$previewToken;

        try {
            if (!$this->client->hasProduct('content_migration')) {
                throw new \RuntimeException('Content Migration requires the Starter plan or higher.');
            }

            $preview = $this->backendUser()->getSessionData($sessionKey);

            if (
                !\is_array($preview)
                || !isset($preview['createdAt'])
                || time() - (int) $preview['createdAt'] > 3600
                || !\is_array($preview['items'] ?? null)
            ) {
                throw new \RuntimeException('The migration preview expired. Please create it again.');
            }

            $editedItems = $this->mergeSubmittedItems($preview['items'], $items);
            $this->client->reportMigrationEvent(
                (string) ($preview['migrationId'] ?? ''),
                'applying',
                'pages:'.(int) $preview['targetPageUid'],
            );
            $created = $this->writer->write(
                (int) $preview['targetPageUid'],
                $editedItems,
            );

            $this->backendUser()->setAndSaveSessionData($sessionKey, null);
            $this->client->reportMigrationEvent(
                (string) ($preview['migrationId'] ?? ''),
                'completed',
                'pages:'.(int) $preview['targetPageUid'],
                ['created_elements' => $created],
            );
            $this->addFlashMessage(
                sprintf('%d content element(s) were added to the target page.', $created),
                'Migration completed',
            );
        } catch (\Throwable $exception) {
            if (isset($preview) && \is_array($preview)) {
                try {
                    $this->client->reportMigrationEvent(
                        (string) ($preview['migrationId'] ?? ''),
                        'failed',
                        'pages:'.(int) ($preview['targetPageUid'] ?? 0),
                        [],
                        ['message' => $exception->getMessage()],
                    );
                } catch (\Throwable) {
                    // The original persistence error remains the actionable failure.
                }
            }

            $this->addFlashMessage(
                $exception->getMessage(),
                'Migration saving failed',
                ContextualFeedbackSeverity::ERROR,
            );
        }

        return $this->redirect('index');
    }

    public function generateTokenAction(string $label = ''): ResponseInterface
    {
        $token = $this->tokens->generate($label);
        $this->backendUser()->setAndSaveSessionData('contentflow_new_migration_token', $token);
        $this->addFlashMessage(
            'Copy this token now. It is shown only once: '.$token,
            'Migration token created',
        );

        return $this->redirect('index');
    }

    public function revokeTokenAction(int $tokenUid): ResponseInterface
    {
        $this->tokens->revoke($tokenUid);
        $this->addFlashMessage('The migration token was revoked.', 'Token revoked');

        return $this->redirect('index');
    }

    public function clearSourceTokenAction(): ResponseInterface
    {
        $this->setConfiguredSourceToken('');
        $this->addFlashMessage(
            'The saved default source migration token was removed.',
            'Migration token removed',
        );

        return $this->redirect('index');
    }

    /**
     * @param list<array<string, mixed>> $storedItems
     * @param array<int|string, mixed>   $submittedItems
     *
     * @return list<array<string, mixed>>
     */
    private function mergeSubmittedItems(array $storedItems, array $submittedItems): array
    {
        $merged = [];

        foreach ($storedItems as $index => $stored) {
            $submitted = \is_array($submittedItems[$index] ?? null) ? $submittedItems[$index] : [];

            if ('1' !== (string) ($submitted['enabled'] ?? '0')) {
                continue;
            }

            $stored['target_type'] = (string) ($submitted['target_type'] ?? $stored['target_type']);
            $stored['order'] = (int) ($submitted['order'] ?? $index);

            if (\is_array($submitted['fields'] ?? null)) {
                $stored['fields'] = array_map(
                    static fn (mixed $value): string => \is_scalar($value) ? (string) $value : '',
                    $submitted['fields'],
                );
            }

            $merged[] = $stored;
        }

        usort($merged, static fn (array $left, array $right): int => $left['order'] <=> $right['order']);

        return $merged;
    }

    private function backendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }

    private function setConfiguredSourceToken(string $token): void
    {
        /** @var array<string, mixed> $configuration */
        $configuration = $this->extensionConfiguration->get('contentflow_translation');
        $configuration['migrationSourceToken'] = $token;
        $this->extensionConfiguration->set('contentflow_translation', $configuration);
    }
}
