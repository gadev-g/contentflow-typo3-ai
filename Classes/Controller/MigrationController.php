<?php

declare(strict_types=1);

namespace ContentFlow\Typo3Translation\Controller;

use ContentFlow\Typo3Translation\Service\ContentFlowClient;
use ContentFlow\Typo3Translation\Service\HtmlSourceScraper;
use ContentFlow\Typo3Translation\Service\MigrationContentWriter;
use ContentFlow\Typo3Translation\Service\MigrationTokenService;
use ContentFlow\Typo3Translation\Service\ReferencePagePatternCatalog;
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
    private const APPEARANCE_FIELDS = [
        'layout',
        'header_layout',
        'header_size',
        'header_position',
        'frame_class',
        'space_before_class',
        'space_after_class',
    ];

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly ContentFlowClient $client,
        private readonly SourceConnectorClient $sourceConnector,
        private readonly HtmlSourceScraper $htmlScraper,
        private readonly TargetContentSchema $targetSchema,
        private readonly MigrationContentWriter $writer,
        private readonly MigrationTokenService $tokens,
        private readonly ReferencePagePatternCatalog $referencePatterns,
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
            'targetTypes' => $this->targetSchema->availableTypes($targetPageUid, $this->request),
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
        int $referencePageUid = 0,
        int $patternPageUid = 0,
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
            $sourceTitle = trim((string) ($source['title'] ?? ''));
            $elements = $this->prependSourceTitle($elements, $sourceTitle, $source);

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

            $targetTypes = $this->targetSchema->availableTypes($targetPageUid, $this->request);
            $targetTypes = $this->referencePatterns->enrich($targetTypes, $referencePageUid);
            $targetTypes = $this->referencePatterns->enrich(
                $targetTypes,
                $patternPageUid,
                'catalog_patterns',
            );
            $result = $this->client->planMigration(
                (string) ($source['url'] ?? $sourceUrl),
                '' !== $sourceTitle ? $sourceTitle : $sourceUrl,
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
            $typesByName = [];
            $referencePatternLabels = [];
            $catalogPatternLabels = [];
            $patternsById = [];
            $patternsByType = [];
            $usedReferencePatterns = [];
            $outsideReferenceCount = 0;

            foreach ($targetTypes as $targetType) {
                $typeName = (string) ($targetType['type'] ?? '');
                $typeLabels[$typeName] = $targetType['label'];
                $typesByName[$typeName] = $targetType;

                foreach (
                    \is_array($targetType['reference_patterns'] ?? null)
                        ? $targetType['reference_patterns']
                        : [] as $referencePattern
                ) {
                    if (
                        \is_array($referencePattern)
                        && \is_string($referencePattern['id'] ?? null)
                    ) {
                        $referencePatternLabels[$referencePattern['id']] = (string) (
                            $referencePattern['label']
                            ?? $referencePattern['id']
                        );
                        $patternsById[$referencePattern['id']] = $referencePattern;
                        $patternsByType[$typeName][$referencePattern['id']] = $referencePattern;
                    }
                }

                foreach (
                    \is_array($targetType['catalog_patterns'] ?? null)
                        ? $targetType['catalog_patterns']
                        : [] as $catalogPattern
                ) {
                    if (
                        \is_array($catalogPattern)
                        && \is_string($catalogPattern['id'] ?? null)
                    ) {
                        $catalogPatternLabels[$catalogPattern['id']] = (string) (
                            $catalogPattern['label']
                            ?? $catalogPattern['id']
                        );
                        $patternsById[$catalogPattern['id']] = $catalogPattern;
                        $patternsByType[$typeName][$catalogPattern['id']] = $catalogPattern;
                    }
                }
            }

            foreach ($items as $itemIndex => &$item) {
                if (\is_array($item)) {
                    $item = $this->applyPatternAppearance($item, $patternsById, $patternsByType);
                    $item['target_label'] = $typeLabels[(string) ($item['target_type'] ?? '')]
                        ?? (string) ($item['target_type'] ?? '');
                    $item['reference_pattern_label'] = $referencePatternLabels[
                        (string) ($item['reference_pattern_id'] ?? '')
                    ] ?? '';
                    $item['catalog_pattern_label'] = $catalogPatternLabels[
                        (string) ($item['catalog_pattern_id'] ?? '')
                    ] ?? '';
                    $referencePatternId = (string) ($item['reference_pattern_id'] ?? '');

                    if ('' !== $referencePatternId) {
                        $usedReferencePatterns[$referencePatternId] = true;
                    }

                    $isOutsideReference = 'outside_reference' === (
                        $item['reference_blueprint_status']
                        ?? ''
                    );

                    if ($isOutsideReference) {
                        ++$outsideReferenceCount;
                    }

                    $sourceIndex = (int) ($item['source_index'] ?? -1);
                    $sourceIndices = \is_array($item['source_indices'] ?? null)
                        ? $item['source_indices']
                        : [$sourceIndex];
                    $item['source_record'] = $this->combinedSourceRecord($elements, $sourceIndices);
                    $item['relations'] = \is_array($item['relations'] ?? null) ? $item['relations'] : [];
                    $item['enabled'] = !$isOutsideReference;
                    $item['order'] = $itemIndex;
                    $item['field_definitions'] = $this->fieldDefinitions(
                        $typesByName[(string) ($item['target_type'] ?? '')] ?? [],
                        \is_array($item['fields'] ?? null) ? $item['fields'] : [],
                    );
                }
            }

            unset($item);

            $missingReferencePatterns = [];

            foreach ($referencePatternLabels as $patternId => $patternLabel) {
                if (!isset($usedReferencePatterns[$patternId])) {
                    $missingReferencePatterns[] = $patternLabel;
                }
            }

            $token = bin2hex(random_bytes(24));
            $targetTypeSchemaJson = json_encode(
                $targetTypes,
                \JSON_THROW_ON_ERROR
                | \JSON_HEX_AMP
                | \JSON_HEX_APOS
                | \JSON_HEX_QUOT
                | \JSON_HEX_TAG
                | \JSON_UNESCAPED_UNICODE,
            );
            $this->backendUser()->setAndSaveSessionData('contentflow_migration_' . $token, [
                'sourceUrl' => (string) ($source['url'] ?? $sourceUrl),
                'sourceTitle' => '' !== $sourceTitle ? $sourceTitle : $sourceUrl,
                'targetPageUid' => $targetPageUid,
                'referencePageUid' => $referencePageUid,
                'patternPageUid' => $patternPageUid,
                'sourceMode' => $sourceMode,
                'items' => $items,
                'targetTypes' => $targetTypes,
                'migrationId' => (string) ($result['migration_id'] ?? ''),
                'createdAt' => time(),
            ]);

            $module = $this->moduleTemplateFactory->create($this->request);
            $module->assignMultiple([
                'sourceUrl' => (string) ($source['url'] ?? $sourceUrl),
                'sourceTitle' => '' !== $sourceTitle ? $sourceTitle : $sourceUrl,
                'sourceBlockCount' => \count($blocks),
                'targetPageUid' => $targetPageUid,
                'referencePageUid' => $referencePageUid,
                'patternPageUid' => $patternPageUid,
                'missingReferencePatterns' => $missingReferencePatterns,
                'outsideReferenceCount' => $outsideReferenceCount,
                'items' => $items,
                'targetTypes' => $targetTypes,
                'targetTypeSchemaJson' => $targetTypeSchemaJson,
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
        $sessionKey = 'contentflow_migration_' . $previewToken;

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

            $editedItems = $this->mergeSubmittedItems(
                $preview['items'],
                $items,
                \is_array($preview['targetTypes'] ?? null) ? $preview['targetTypes'] : [],
            );
            $this->client->reportMigrationEvent(
                (string) ($preview['migrationId'] ?? ''),
                'applying',
                'pages:' . (int) $preview['targetPageUid'],
            );
            $created = $this->writer->write(
                (int) $preview['targetPageUid'],
                $editedItems,
            );

            $this->backendUser()->setAndSaveSessionData($sessionKey, null);
            $this->client->reportMigrationEvent(
                (string) ($preview['migrationId'] ?? ''),
                'completed',
                'pages:' . (int) $preview['targetPageUid'],
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
                        'pages:' . (int) ($preview['targetPageUid'] ?? 0),
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
            'Copy this token now. It is shown only once: ' . $token,
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
        $this->addFlashMessage('The saved default source migration token was removed.', 'Migration token removed',);
        return $this->redirect('index');
    }
    /**
     * @param list<array<string, mixed>> $storedItems
     * @param array<int|string, mixed>   $submittedItems
     *
     * @return list<array<string, mixed>>
     */
    private function mergeSubmittedItems(
        array $storedItems,
        array $submittedItems,
        array $targetTypes = [],
    ): array
    {
        $merged = [];
        $typesByName = [];

        foreach ($targetTypes as $targetType) {
            if (\is_array($targetType) && \is_string($targetType['type'] ?? null)) {
                $typesByName[$targetType['type']] = $targetType;
            }
        }

        foreach ($storedItems as $index => $stored) {
            $submitted = \is_array($submittedItems[$index] ?? null) ? $submittedItems[$index] : [];

            if ('1' !== (string) ($submitted['enabled'] ?? '0')) {
                continue;
            }

            $previousTargetType = (string) ($stored['target_type'] ?? '');
            $stored['target_type'] = (string) ($submitted['target_type'] ?? $previousTargetType);
            $stored['order'] = (int) ($submitted['order'] ?? $index);

            if (\is_array($submitted['fields'] ?? null)) {
                $stored['fields'] = array_map(
                    static fn (mixed $value): string => \is_scalar($value) ? (string) $value : '',
                    $submitted['fields'],
                );
            }

            if ($stored['target_type'] !== $previousTargetType) {
                $stored = $this->applySelectedTypePattern(
                    $stored,
                    $typesByName[$stored['target_type']] ?? [],
                );
            }

            $merged[] = $stored;
        }

        usort($merged, static fn (array $left, array $right): int => $left['order'] <=> $right['order']);

        return $merged;
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $targetType
     *
     * @return array<string, mixed>
     */
    private function applySelectedTypePattern(array $item, array $targetType): array
    {
        $patterns = array_merge(
            \is_array($targetType['reference_patterns'] ?? null)
                ? $targetType['reference_patterns']
                : [],
            \is_array($targetType['catalog_patterns'] ?? null)
                ? $targetType['catalog_patterns']
                : [],
        );
        $pattern = \is_array($patterns[0] ?? null) ? $patterns[0] : [];
        $patternFields = array_merge(
            \is_array($pattern['field_values'] ?? null) ? $pattern['field_values'] : [],
            \is_array($pattern['option_values'] ?? null) ? $pattern['option_values'] : [],
        );

        foreach (self::APPEARANCE_FIELDS as $field) {
            if (\is_scalar($patternFields[$field] ?? null)) {
                $item['fields'][$field] = (string) $patternFields[$field];
            }
        }

        $item['reference_pattern_id'] = '';
        $item['catalog_pattern_id'] = '';
        $item['container_columns'] = \is_array($pattern['container_columns'] ?? null)
            ? array_values(array_map('intval', $pattern['container_columns']))
            : [];

        return $item;
    }

    /**
     * @param array<string, mixed> $targetType
     * @param array<string, mixed> $values
     *
     * @return list<array{
     *     name: string,
     *     label: string,
     *     value: string,
     *     options: array<string, string>,
     *     is_select: bool,
     *     is_quick_setting: bool
     * }>
     */
    private function fieldDefinitions(array $targetType, array $values): array
    {
        $fields = \is_array($targetType['fields'] ?? null) ? $targetType['fields'] : [];
        $labels = \is_array($targetType['field_labels'] ?? null) ? $targetType['field_labels'] : [];
        $fieldOptions = \is_array($targetType['field_options'] ?? null)
            ? $targetType['field_options']
            : [];
        $fieldDefaults = \is_array($targetType['field_defaults'] ?? null)
            ? $targetType['field_defaults']
            : [];
        $quickSettings = [
            'header_layout',
            'header_size',
            'header_position',
            'frame_class',
            'space_before_class',
            'space_after_class',
        ];
        $definitions = [];

        foreach ($fields as $field) {
            if (!\is_string($field)) {
                continue;
            }

            $options = \is_array($fieldOptions[$field] ?? null) ? $fieldOptions[$field] : [];
            $value = \is_scalar($values[$field] ?? null)
                ? (string) $values[$field]
                : (\is_scalar($fieldDefaults[$field] ?? null) ? (string) $fieldDefaults[$field] : '');
            $definitions[] = [
                'name' => $field,
                'label' => \is_string($labels[$field] ?? null) ? $labels[$field] : $field,
                'value' => $value,
                'options' => $options,
                'is_select' => [] !== $options,
                'is_quick_setting' => \in_array($field, $quickSettings, true),
            ];
        }

        return $definitions;
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, array<string, mixed>> $patternsById
     * @param array<string, array<string, array<string, mixed>>> $patternsByType
     *
     * @return array<string, mixed>
     */
    private function applyPatternAppearance(array $item, array $patternsById, array $patternsByType): array
    {
        $patternId = (string) ($item['reference_pattern_id'] ?? '');

        if ('' === $patternId) {
            $patternId = (string) ($item['catalog_pattern_id'] ?? '');
        }

        $pattern = \is_array($patternsById[$patternId] ?? null) ? $patternsById[$patternId] : [];

        if ([] === $pattern) {
            $typePatterns = \is_array($patternsByType[(string) ($item['target_type'] ?? '')] ?? null)
                ? array_values($patternsByType[(string) ($item['target_type'] ?? '')])
                : [];

            if (1 === \count($typePatterns)) {
                $pattern = $typePatterns[0];
            }
        }

        if ([] === $pattern) {
            return $item;
        }

        $fields = \is_array($item['fields'] ?? null) ? $item['fields'] : [];
        $patternValues = array_merge(
            \is_array($pattern['field_values'] ?? null) ? $pattern['field_values'] : [],
            \is_array($pattern['option_values'] ?? null) ? $pattern['option_values'] : [],
        );

        foreach (self::APPEARANCE_FIELDS as $field) {
            if (!\is_scalar($patternValues[$field] ?? null)) {
                continue;
            }

            $fields[$field] = (string) $patternValues[$field];
        }

        $item['fields'] = $fields;

        return $item;
    }

    /**
     * @param list<array<string, mixed>> $elements
     * @param array<string, mixed>       $source
     *
     * @return list<array<string, mixed>>
     */
    private function prependSourceTitle(array $elements, string $sourceTitle, array $source): array
    {
        $sourceUrl = \is_scalar($source['url'] ?? null) ? (string) $source['url'] : '';

        if (
            '' === $sourceTitle
            || $this->normalizeEditorialText($sourceTitle) === $this->normalizeEditorialText($sourceUrl)
            || $this->containsSourceTitle($elements, $sourceTitle)
        ) {
            return $elements;
        }

        array_unshift($elements, [
            'source_table' => 'pages',
            'source_uid' => (int) ($source['page_uid'] ?? 0),
            'type' => 'header',
            'column' => 0,
            'sorting' => 0,
            'fields' => ['header' => $sourceTitle],
            'relations' => [],
            'media' => [],
            'synthetic' => true,
        ]);

        return $elements;
    }

    /** @param list<array<string, mixed>> $elements */
    private function containsSourceTitle(array $elements, string $sourceTitle): bool
    {
        $normalizedTitle = $this->normalizeEditorialText($sourceTitle);

        foreach ($elements as $element) {
            $fields = \is_array($element['fields'] ?? null) ? $element['fields'] : [];

            foreach (['header', 'title', 'headline'] as $field) {
                if (
                    \is_scalar($fields[$field] ?? null)
                    && $normalizedTitle === $this->normalizeEditorialText((string) $fields[$field])
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $elements
     * @param list<mixed>                $sourceIndices
     *
     * @return array<string, mixed>
     */
    private function combinedSourceRecord(array $elements, array $sourceIndices): array
    {
        $combined = [];
        $media = [];
        $linkedFiles = [];
        $relations = [];

        foreach ($sourceIndices as $sourceIndex) {
            $index = filter_var($sourceIndex, \FILTER_VALIDATE_INT);
            $record = false !== $index && \is_array($elements[$index] ?? null)
                ? $elements[$index]
                : [];

            if ([] === $record) {
                continue;
            }

            if ([] === $combined) {
                $combined = $record;
            }

            foreach (\is_array($record['media'] ?? null) ? $record['media'] : [] as $mediaItem) {
                if (\is_array($mediaItem)) {
                    $media[] = $mediaItem;
                }
            }

            foreach (\is_array($record['linked_files'] ?? null) ? $record['linked_files'] : [] as $linkedFile) {
                if (\is_array($linkedFile)) {
                    $linkedFiles[] = $linkedFile;
                }
            }

            foreach (\is_array($record['relations'] ?? null) ? $record['relations'] : [] as $field => $children) {
                if (\is_string($field) && \is_array($children)) {
                    $relations[$field] = array_merge($relations[$field] ?? [], $children);
                }
            }
        }

        $combined['media'] = $media;
        $combined['linked_files'] = $linkedFiles;
        $combined['relations'] = $relations;

        return $combined;
    }

    private function normalizeEditorialText(string $value): string
    {
        $plainText = html_entity_decode(strip_tags($value), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $plainText)), 'UTF-8');
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
