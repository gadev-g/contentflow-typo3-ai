<?php

declare(strict_types=1);

namespace ContentFlow\Typo3Translation\Service;

use TYPO3\CMS\Core\DataHandling\DataHandler;
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
        $sorting = 256;

        foreach ($items as $index => $item) {
            $targetType = $item['target_type'];

            if (!isset($allowedTypes[$targetType])) {
                continue;
            }

            $fields = [];

            foreach ($item['fields'] as $field => $value) {
                if (isset($allowedTypes[$targetType][$field]) && '' !== trim($value)) {
                    $fields[$field] = $this->sanitizeField($field, $value);
                }
            }

            $relations = \is_array($item['relations'] ?? null) ? $item['relations'] : [];
            $sourceRecord = \is_array($item['source_record'] ?? null) ? $item['source_record'] : [];
            $sourceMedia = \is_array($sourceRecord['media'] ?? null) ? $sourceRecord['media'] : [];
            if ([] === $fields && [] === $relations && [] === $sourceMedia) {
                continue;
            }
            $data['tt_content']['NEW_contentflow_migration_' . $index] = [
                'pid' => $pageUid,
                'CType' => $targetType,
                'colPos' => 0,
                'sorting' => $sorting,
                ...$fields,
            ];
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

        $created = \count($data['tt_content']);
        $this->writeRelationsAndMedia($handler, $pageUid, $items);

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
            $this->writeMedia(
                'tt_content',
                $parentUid,
                $pageUid,
                \is_array($sourceRecord['media'] ?? null) ? $sourceRecord['media'] : [],
            );
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

            foreach (array_values($children) as $index => $child) {
                if (!\is_array($child)) {
                    continue;
                }

                $childFields = \is_array($child['fields'] ?? null) ? $child['fields'] : [];
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

                $data[$childTable]['NEW_contentflow_relation_' . $parentUid . '_' . $index] = [
                    'pid' => $pageUid,
                    $foreignField => $parentUid,
                    ...$safeFields,
                ];
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

                if (!preg_match('#^(https?://|/|#)#i', $href)) {
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
}
