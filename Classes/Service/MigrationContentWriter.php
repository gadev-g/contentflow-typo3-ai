<?php

declare(strict_types=1);

namespace ContentFlow\Typo3Translation\Service;

use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final readonly class MigrationContentWriter
{
    public function __construct(
        private TargetContentSchema $schema,
        private SourceMediaImporter $mediaImporter,
    ) {
    }

    /**
     * @param list<array{source_index: int, target_type: string, fields: array<string, string>}> $items
     */
    public function write(int $pageUid, array $items): int
    {
        if ($pageUid <= 0) {
            throw new \RuntimeException('Select a valid target page.');
        }

        $allowedTypes = [];

        foreach ($this->schema->availableTypes() as $type) {
            $allowedTypes[$type['type']] = array_fill_keys($type['fields'], true);
        }

        $data = [];
        $sorting = $this->nextContentSorting($pageUid);
        $sortingByIdentifier = [];

        foreach ($items as $index => $item) {
            $targetType = $item['target_type'];

            if (!isset($allowedTypes[$targetType])) {
                continue;
            }

            $sourceRecord = \is_array($item['source_record'] ?? null) ? $item['source_record'] : [];
            $itemFields = $this->rewriteLinkedDocuments(
                $item['fields'],
                \is_array($sourceRecord['linked_files'] ?? null) ? $sourceRecord['linked_files'] : [],
            );
            $fields = [];

            foreach ($itemFields as $field => $value) {
                if (isset($allowedTypes[$targetType][$field]) && '' !== trim($value)) {
                    $fields[$field] = $this->sanitizeField($field, $value);
                }
            }

            $relations = \is_array($item['relations'] ?? null) ? $item['relations'] : [];
            $sourceMedia = false === ($item['import_media'] ?? true)
                ? []
                : (\is_array($sourceRecord['media'] ?? null) ? $sourceRecord['media'] : []);
            $sourceRelations = \is_array($sourceRecord['relations'] ?? null)
                ? $sourceRecord['relations']
                : [];
            $containerChildren = \is_array($sourceRelations['contentflow_grid_children'] ?? null)
                ? $sourceRelations['contentflow_grid_children']
                : [];
            $containerColumns = \is_array($item['container_columns'] ?? null)
                ? $item['container_columns']
                : [];

            if (
                [] === $fields
                && [] === $relations
                && [] === $sourceMedia
                && ([] === $containerChildren || [] === $containerColumns)
            ) {
                continue;
            }

            $identifier = 'NEW_contentflow_migration_' . $index;
            $data['tt_content'][$identifier] = [
                'pid' => $pageUid,
                'CType' => $targetType,
                'colPos' => 0,
                'sorting' => $sorting,
                ...$fields,
            ];
            $sortingByIdentifier[$identifier] = $sorting;
            $sorting += 256;
        }

        if ([] === $data) {
            throw new \RuntimeException('The migration preview contains no writable content elements.');
        }

        $handler = GeneralUtility::makeInstance(DataHandler::class);
        $handler->start($data, []);
        $handler->process_datamap();

        if ([] !== $handler->errorLog) {
            throw new \RuntimeException(implode(' ', $handler->errorLog));
        }

        $this->enforceSorting('tt_content', 'sorting', $sortingByIdentifier, $handler);
        $created = \count($data['tt_content']);
        $this->writeRelationsAndMedia($handler, $pageUid, $items);
        $created += $this->writeContainerChildren($handler, $pageUid, $items);

        return $created;
    }

    /** @param list<array<string, mixed>> $items */
    private function writeRelationsAndMedia(DataHandler $parentHandler, int $pageUid, array $items): void
    {
        foreach ($items as $index => $item) {
            $parentUid = (int) ($parentHandler->substNEWwithIDs['NEW_contentflow_migration_' . $index] ?? 0);
            $sourceRecord = \is_array($item['source_record'] ?? null) ? $item['source_record'] : [];
            $relations = \is_array($item['relations'] ?? null)
                ? $item['relations']
                : (\is_array($sourceRecord['relations'] ?? null) ? $sourceRecord['relations'] : []);
            if ($parentUid <= 0) {
                continue;
            }

            $this->writeInlineRelations('tt_content', $parentUid, $pageUid, $relations,);
            if (false !== ($item['import_media'] ?? true)) {
                $this->writeMedia(
                    'tt_content',
                    $parentUid,
                    $pageUid,
                    \is_array($sourceRecord['media'] ?? null) ? $sourceRecord['media'] : [],
                );
            }
        }
    }

    /** @param array<string, mixed> $relations */
    private function writeInlineRelations(string $parentTable, int $parentUid, int $pageUid, array $relations): void
    {
        foreach ($relations as $field => $children) {
            $configuration = $GLOBALS['TCA'][$parentTable]['columns'][$field]['config'] ?? [];
            $childTable = (string) ($configuration['foreign_table'] ?? '');
            $foreignField = (string) ($configuration['foreign_field'] ?? '');

            if ('' === $childTable || '' === $foreignField || !\is_array($children)) {
                continue;
            }

            $data = [];
            $sortingField = $this->relationSortingField($childTable, $configuration);
            $sortingByIdentifier = [];

            foreach (array_values($children) as $index => $child) {
                if (!\is_array($child)) {
                    continue;
                }

                $childFields = $this->rewriteLinkedDocuments(
                    \is_array($child['fields'] ?? null) ? $child['fields'] : [],
                    \is_array($child['linked_files'] ?? null) ? $child['linked_files'] : [],
                );
                $safeFields = [];

                foreach ($childFields as $childField => $value) {
                    if (
                        \is_string($childField)
                        && isset($GLOBALS['TCA'][$childTable]['columns'][$childField])
                        && \is_scalar($value)
                    ) {
                        $safeFields[$childField] = $this->sanitizeRecordField(
                            $childTable,
                            $childField,
                            (string) $value,
                        );
                    }
                }

                $childData = [
                    'pid' => $pageUid,
                    $foreignField => $parentUid,
                    ...$safeFields,
                ];

                if (null !== $sortingField) {
                    $childData[$sortingField] = ($index + 1) * 256;
                    $sortingByIdentifier['NEW_contentflow_relation_' . $parentUid . '_' . $index]
                        = ($index + 1) * 256;
                }

                $data[$childTable]['NEW_contentflow_relation_' . $parentUid . '_' . $index] = $childData;
            }

            if ([] === $data) {
                continue;
            }

            $handler = GeneralUtility::makeInstance(DataHandler::class);
            $handler->start($data, []);
            $handler->process_datamap();

            if ([] !== $handler->errorLog) {
                throw new \RuntimeException(implode(' ', $handler->errorLog));
            }

            if (null !== $sortingField) {
                $this->enforceSorting($childTable, $sortingField, $sortingByIdentifier, $handler);
            }

            foreach (array_values($children) as $index => $child) {
                if (!\is_array($child)) {
                    continue;
                }

                $newIdentifier = 'NEW_contentflow_relation_' . $parentUid . '_' . $index;
                $childUid = (int) ($handler->substNEWwithIDs[$newIdentifier] ?? 0);

                if ($childUid <= 0) {
                    continue;
                }

                $this->writeInlineRelations(
                    $childTable,
                    $childUid,
                    $pageUid,
                    \is_array($child['relations'] ?? null) ? $child['relations'] : [],
                );

                $this->writeMedia(
                    $childTable,
                    $childUid,
                    $pageUid,
                    \is_array($child['media'] ?? null) ? $child['media'] : [],
                );
            }
        }
    }

    /** @param list<array<string, mixed>> $mediaItems */
    private function writeMedia(string $parentTable, int $parentUid, int $pageUid, array $mediaItems): void
    {
        $data = [];

        foreach ($mediaItems as $index => $media) {
            if (!\is_array($media)) {
                continue;
            }

            $field = (string) ($media['field'] ?? '');

            if (!isset($GLOBALS['TCA'][$parentTable]['columns'][$field])) {
                continue;
            }

            $fileUid = $this->mediaImporter->import($media);
            $data['sys_file_reference']['NEW_contentflow_media_' . $parentUid . '_' . $index] = [
                'pid' => $pageUid,
                'uid_local' => $fileUid,
                'uid_foreign' => $parentUid,
                'tablenames' => $parentTable,
                'fieldname' => $field,
                'sorting_foreign' => ($index + 1) * 256,
                'title' => (string) ($media['metadata']['title'] ?? ''),
                'alternative' => (string) ($media['metadata']['alternative'] ?? ''),
                'description' => (string) ($media['metadata']['description'] ?? ''),
            ];
        }

        if ([] === $data) {
            return;
        }

        $handler = GeneralUtility::makeInstance(DataHandler::class);
        $handler->start($data, []);
        $handler->process_datamap();

        if ([] !== $handler->errorLog) {
            throw new \RuntimeException(implode(' ', $handler->errorLog));
        }
    }

    /** @param list<array<string, mixed>> $items */
    private function writeContainerChildren(
        DataHandler $parentHandler,
        int $pageUid,
        array $items,
    ): int {
        $allowedTypes = [];

        foreach ($this->schema->availableTypes() as $type) {
            $allowedTypes[$type['type']] = array_fill_keys($type['fields'], true);
        }

        $created = 0;

        foreach ($items as $itemIndex => $item) {
            $columns = array_values(array_filter(
                array_map('intval', \is_array($item['container_columns'] ?? null)
                    ? $item['container_columns']
                    : []),
                static fn (int $column): bool => $column > 0,
            ));
            $parentUid = (int) (
                $parentHandler->substNEWwithIDs['NEW_contentflow_migration_' . $itemIndex]
                ?? 0
            );

            if ([] === $columns || $parentUid <= 0) {
                continue;
            }
            $parentField = (string) ($item['container_parent_field'] ?? '');
            $columnField = (string) ($item['container_column_field'] ?? '');

            if (
                !\in_array($parentField, ['tx_container_parent', 'tx_gridelements_container'], true)
                || !\in_array($columnField, ['colPos', 'tx_gridelements_columns'], true)
                || !isset($GLOBALS['TCA']['tt_content']['columns'][$parentField])
                || !isset($GLOBALS['TCA']['tt_content']['columns'][$columnField])
            ) {
                continue;
            }

            $sourceRecord = \is_array($item['source_record'] ?? null) ? $item['source_record'] : [];
            $relations = \is_array($sourceRecord['relations'] ?? null) ? $sourceRecord['relations'] : [];
            $children = \is_array($relations['contentflow_grid_children'] ?? null)
                ? array_values($relations['contentflow_grid_children'])
                : [];

            if ([] === $children) {
                continue;
            }

            $sourceColumns = [];

            foreach ($children as $child) {
                if (\is_array($child)) {
                    $sourceColumns[] = (int) ($child['column'] ?? 0);
                }
            }

            $sourceColumns = array_values(array_unique($sourceColumns));
            sort($sourceColumns);
            $columnMap = [];
            $hasSourceColumnLayout = \count($sourceColumns) > 1;

            foreach ($sourceColumns as $index => $sourceColumn) {
                $columnMap[$sourceColumn] = $columns[min($index, \count($columns) - 1)];
            }

            $data = [];
            $preparedChildren = [];

            foreach ($children as $index => $child) {
                if (!\is_array($child)) {
                    continue;
                }

                $sourceFields = \is_array($child['fields'] ?? null) ? $child['fields'] : [];
                $sourceMedia = \is_array($child['media'] ?? null) ? $child['media'] : [];
                $targetType = $this->containerChildType(
                    (string) ($child['type'] ?? ''),
                    $sourceFields,
                    $sourceMedia,
                    $allowedTypes,
                );

                if ('' === $targetType) {
                    continue;
                }

                $safeFields = [];
                $rewrittenFields = $this->rewriteLinkedDocuments(
                    $sourceFields,
                    \is_array($child['linked_files'] ?? null) ? $child['linked_files'] : [],
                );

                foreach ($rewrittenFields as $field => $value) {
                    if (
                        \is_string($field)
                        && \is_scalar($value)
                        && isset($allowedTypes[$targetType][$field])
                    ) {
                        $safeFields[$field] = $this->sanitizeField($field, (string) $value);
                    }
                }

                $sourceColumn = (int) ($child['column'] ?? 0);
                $targetColumn = $hasSourceColumnLayout
                    ? ($columnMap[$sourceColumn] ?? $columns[$index % \count($columns)])
                    : $columns[$index % \count($columns)];
                $identifier = 'NEW_contentflow_container_' . $parentUid . '_' . $index;
                $childData = [
                    'pid' => $pageUid,
                    'CType' => $targetType,
                    'sorting' => ($index + 1) * 256,
                    ...$safeFields,
                ];
                $childData[$parentField] = $parentUid;
                $childData[$columnField] = $targetColumn;
                $childData['colPos'] = 'colPos' === $columnField
                    ? $targetColumn
                    : (int) ($item['container_child_col_pos'] ?? 18181);
                $data['tt_content'][$identifier] = $childData;
                $preparedChildren[$identifier] = $child;
            }

            if ([] === $data) {
                continue;
            }

            $handler = GeneralUtility::makeInstance(DataHandler::class);
            $handler->start($data, []);
            $handler->process_datamap();

            if ([] !== $handler->errorLog) {
                throw new \RuntimeException(implode(' ', $handler->errorLog));
            }

            foreach ($preparedChildren as $identifier => $child) {
                $childUid = (int) ($handler->substNEWwithIDs[$identifier] ?? 0);

                if ($childUid <= 0) {
                    continue;
                }

                $this->writeMedia(
                    'tt_content',
                    $childUid,
                    $pageUid,
                    \is_array($child['media'] ?? null) ? $child['media'] : [],
                );
                ++$created;
            }
        }

        return $created;
    }

    /**
     * @param array<string, mixed>                    $fields
     * @param list<array<string, mixed>>              $media
     * @param array<string, array<string, true>>       $allowedTypes
     */
    private function containerChildType(
        string $sourceType,
        array $fields,
        array $media,
        array $allowedTypes,
    ): string {
        if (isset($allowedTypes[$sourceType])) {
            return $sourceType;
        }

        if ([] !== $media && isset($allowedTypes['textpic'])) {
            return 'textpic';
        }

        if ([] !== $media && isset($allowedTypes['image'])) {
            return 'image';
        }

        if (
            ('' !== trim((string) ($fields['bodytext'] ?? ''))
                || '' !== trim((string) ($fields['header'] ?? '')))
            && isset($allowedTypes['text'])
        ) {
            return 'text';
        }

        return '';
    }

    private function sanitizeField(string $field, string $value): string
    {
        return $this->sanitizeRecordField('tt_content', $field, $value);
    }

    private function sanitizeRecordField(string $table, string $field, string $value): string
    {
        $configuration = $GLOBALS['TCA'][$table]['columns'][$field]['config'] ?? [];

        if ('text' === ($configuration['type'] ?? null)) {
            return $this->sanitizeRichText($value);
        }

        return $this->sanitizeGeneric($value);
    }

    private function sanitizeGeneric(string $value): string
    {
        return trim(strip_tags($value));
    }

    private function sanitizeRichText(string $value): string
    {
        $html = strip_tags(
            $value,
            '<p><br><ul><ol><li><strong><em><b><i><a><blockquote><h2><h3><h4>',
        );
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="utf-8" ?><body>' . $html . '</body>',
            \LIBXML_NONET | \LIBXML_NOERROR | \LIBXML_NOWARNING,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($document);

        foreach ($xpath->query('//body//*') ?: [] as $element) {
            if (!$element instanceof \DOMElement) {
                continue;
            }

            $attributes = [];

            foreach ($element->attributes as $attribute) {
                $attributes[] = $attribute->name;
            }

            foreach ($attributes as $attribute) {
                if ('href' !== $attribute || 'a' !== strtolower($element->tagName)) {
                    $element->removeAttribute($attribute);
                }
            }

            if ($element->hasAttribute('href')) {
                $href = trim($element->getAttribute('href'));

                if (!preg_match('~^(?:https?://[^\s]+|/[^\s]*|#[^\s]*|t3://file\?uid=\d+)$~i', $href)) {
                    $element->removeAttribute('href');
                } else {
                    $element->setAttribute('rel', 'noopener noreferrer');
                }
            }
        }

        $body = $xpath->query('//body')->item(0);
        $result = '';

        foreach ($body?->childNodes ?? [] as $node) {
            $result .= $document->saveHTML($node) ?: '';
        }

        return trim($result);
    }

    /**
     * @param array<string, mixed>       $fields
     * @param list<array<string, mixed>> $linkedFiles
     *
     * @return array<string, mixed>
     */
    private function rewriteLinkedDocuments(array $fields, array $linkedFiles): array
    {
        foreach ($linkedFiles as $linkedFile) {
            $originalHref = trim((string) ($linkedFile['original_href'] ?? ''));

            if ('' === $originalHref) {
                continue;
            }

            $fileUid = $this->mediaImporter->import($linkedFile);
            $targetHref = 't3://file?uid=' . $fileUid;

            foreach ($fields as $field => $value) {
                if (!\is_string($value) || '' === $value) {
                    continue;
                }

                if ($this->linkedDocumentHrefMatches(trim($value), $linkedFile)) {
                    $fields[$field] = $targetHref;

                    continue;
                }

                $fields[$field] = str_replace(
                    [
                        'href="' . $originalHref . '"',
                        "href='" . $originalHref . "'",
                        '<link ' . $originalHref . '>',
                    ],
                    [
                        'href="' . $targetHref . '"',
                        "href='" . $targetHref . "'",
                        '<a href="' . $targetHref . '">',
                    ],
                    $value,
                );
                $fields[$field] = preg_replace(
                    '#<link\s+' . preg_quote($originalHref, '#') . '(?:\s+[^>]*)?>#i',
                    '<a href="' . $targetHref . '">',
                    (string) $fields[$field],
                ) ?? $fields[$field];
                $fields[$field] = preg_replace_callback(
                    '/href\s*=\s*(["\'])(.*?)\1/i',
                    fn (array $match): string => $this->linkedDocumentHrefMatches(
                        html_entity_decode((string) $match[2], \ENT_QUOTES | \ENT_HTML5),
                        $linkedFile,
                    )
                        ? 'href=' . $match[1] . $targetHref . $match[1]
                        : $match[0],
                    (string) $fields[$field],
                ) ?? $fields[$field];
                $fields[$field] = preg_replace_callback(
                    '/<link\s+([^\s>]+)([^>]*)>/i',
                    fn (array $match): string => $this->linkedDocumentHrefMatches(
                        html_entity_decode((string) $match[1], \ENT_QUOTES | \ENT_HTML5),
                        $linkedFile,
                    )
                        ? '<a href="' . $targetHref . '">'
                        : $match[0],
                    (string) $fields[$field],
                ) ?? $fields[$field];
                $fields[$field] = str_replace('</link>', '</a>', (string) $fields[$field]);
            }
        }

        return $fields;
    }

    /** @param array<string, mixed> $linkedFile */
    private function linkedDocumentHrefMatches(string $href, array $linkedFile): bool
    {
        $href = html_entity_decode(trim($href), \ENT_QUOTES | \ENT_HTML5);
        $originalHref = html_entity_decode(
            trim((string) ($linkedFile['original_href'] ?? '')),
            \ENT_QUOTES | \ENT_HTML5,
        );

        if ('' !== $originalHref && $href === $originalHref) {
            return true;
        }

        $sourceUid = (int) ($linkedFile['source_file_uid'] ?? 0);

        if (
            $sourceUid > 0
            && (
                1 === preg_match('/^file:' . $sourceUid . '$/i', $href)
                || (
                    1 === preg_match('/^t3:\/\/file\?([^#]+)/i', $href, $match)
                    && (string) $sourceUid === (string) (
                        $this->queryParameter($match[1], 'uid')
                        ?? $this->queryParameter($match[1], 'identifier')
                        ?? ''
                    )
                )
            )
        ) {
            return true;
        }

        $fileName = rawurldecode(trim((string) ($linkedFile['name'] ?? '')));
        $path = rawurldecode((string) parse_url($href, \PHP_URL_PATH));

        return '' !== $fileName
            && '' !== $path
            && 0 === strcasecmp(basename($path), $fileName);
    }

    private function queryParameter(string $query, string $name): ?string
    {
        parse_str(html_entity_decode($query, \ENT_QUOTES | \ENT_HTML5), $parameters);
        $value = $parameters[$name] ?? null;

        return \is_scalar($value) ? (string) $value : null;
    }

    private function nextContentSorting(int $pageUid): int
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tt_content');
        $maximum = $connection->fetchOne(
            'SELECT MAX(sorting) FROM tt_content WHERE pid = ? AND colPos = ? AND deleted = 0',
            [$pageUid, 0],
        );

        return max(0, (int) $maximum) + 256;
    }

    /** @param array<string, mixed> $configuration */
    private function relationSortingField(string $childTable, array $configuration): ?string
    {
        $foreignSorting = $configuration['foreign_sortby'] ?? null;

        if (
            \is_string($foreignSorting)
            && '' !== $foreignSorting
            && isset($GLOBALS['TCA'][$childTable]['columns'][$foreignSorting])
        ) {
            return $foreignSorting;
        }

        $tableSorting = $GLOBALS['TCA'][$childTable]['ctrl']['sortby'] ?? null;

        if (
            \is_string($tableSorting)
            && '' !== $tableSorting
            && isset($GLOBALS['TCA'][$childTable]['columns'][$tableSorting])
        ) {
            return $tableSorting;
        }

        return null;
    }

    /**
     * @param array<string, int> $sortingByIdentifier
     */
    private function enforceSorting(
        string $table,
        string $sortingField,
        array $sortingByIdentifier,
        DataHandler $handler,
    ): void {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable($table);

        foreach ($sortingByIdentifier as $identifier => $sorting) {
            $uid = (int) ($handler->substNEWwithIDs[$identifier] ?? 0);

            if ($uid > 0) {
                $connection->update($table, [$sortingField => $sorting], ['uid' => $uid]);
            }
        }
    }
}
