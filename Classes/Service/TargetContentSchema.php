<?php

declare(strict_types=1);

namespace ContentFlow\Typo3Translation\Service;

use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

final class TargetContentSchema
{
    private const EXCLUDED_FIELDS = [
        'uid',
        'pid',
        'CType',
        'colPos',
        'sorting',
        'hidden',
        'deleted',
        'starttime',
        'endtime',
        'sys_language_uid',
        'l18n_parent',
        'l10n_source',
    ];

    /**
     * @return list<array{
     *     type: string,
     *     label: string,
     *     fields: list<string>,
     *     field_options: array<string, array<string, string>>,
     *     relations: array<string, array{
     *         table: string,
     *         fields: list<string>,
     *         media_fields: list<string>
     *     }>
     * }>
     */
    public function availableTypes(): array
    {
        $items = $GLOBALS['TCA']['tt_content']['columns']['CType']['config']['items'] ?? [];
        $types = [];

        foreach ($items as $item) {
            $type = $this->itemValue($item);

            if ('' === $type || '--div--' === $type || !isset($GLOBALS['TCA']['tt_content']['types'][$type])) {
                continue;
            }

            $fields = $this->editableFields($type);
            $relations = $this->editableRelations($type);

            if ([] === $fields && [] === $relations) {
                continue;
            }

            $types[] = [
                'type' => $type,
                'label' => $this->itemLabel($item, $type),
                'fields' => $fields,
                'field_options' => $this->fieldOptions($fields),
                'relations' => $relations,
            ];

            if (\count($types) >= 50) {
                break;
            }
        }

        if ([] === $types) {
            return [[
                'type' => 'text',
                'label' => 'Text',
                'fields' => ['header', 'bodytext'],
                'field_options' => [],
                'relations' => [],
            ]];
        }

        usort(
            $types,
            static fn (array $left, array $right): int => ('text' === $left['type'] ? -1 : 0)
                <=> ('text' === $right['type'] ? -1 : 0),
        );

        return $types;
    }

    /**
     * @return array<string, array{
     *     table: string,
     *     fields: list<string>,
     *     media_fields: list<string>
     * }>
     */
    private function editableRelations(string $type): array
    {
        $showItem = (string) ($GLOBALS['TCA']['tt_content']['types'][$type]['showitem'] ?? '');
        $relations = [];

        foreach ($this->expandedFieldDefinitions($showItem) as $fieldDefinition) {
            $field = trim(explode(';', $fieldDefinition)[0]);
            $configuration = $GLOBALS['TCA']['tt_content']['columns'][$field]['config'] ?? [];
            $childTable = (string) ($configuration['foreign_table'] ?? '');
            $foreignField = (string) ($configuration['foreign_field'] ?? '');

            if (
                '' === $field
                || 'inline' !== ($configuration['type'] ?? null)
                || '' === $childTable
                || '' === $foreignField
                || 'sys_file_reference' === $childTable
                || !isset($GLOBALS['TCA'][$childTable])
            ) {
                continue;
            }

            $childFields = [];
            $mediaFields = [];

            foreach (($GLOBALS['TCA'][$childTable]['columns'] ?? []) as $childField => $childDefinition) {
                $childConfiguration = $childDefinition['config'] ?? [];
                $childFieldType = $childConfiguration['type'] ?? null;

                if (\in_array($childFieldType, ['input', 'text', 'link'], true)) {
                    $childFields[] = (string) $childField;
                }

                if (
                    'file' === $childFieldType
                    || (
                        'inline' === $childFieldType
                        && 'sys_file_reference' === ($childConfiguration['foreign_table'] ?? null)
                    )
                ) {
                    $mediaFields[] = (string) $childField;
                }
            }

            if ([] === $childFields && [] === $mediaFields) {
                continue;
            }

            $relations[$field] = [
                'table' => $childTable,
                'fields' => array_values(array_unique($childFields)),
                'media_fields' => array_values(array_unique($mediaFields)),
            ];
        }

        return $relations;
    }

    /** @return list<string> */
    private function editableFields(string $type): array
    {
        $showItem = (string) ($GLOBALS['TCA']['tt_content']['types'][$type]['showitem'] ?? '');
        $fields = [];

        foreach ($this->expandedFieldDefinitions($showItem) as $fieldDefinition) {
            $field = trim(explode(';', $fieldDefinition)[0]);

            if (
                '' === $field
                || str_starts_with($field, '--')
                || \in_array($field, self::EXCLUDED_FIELDS, true)
            ) {
                continue;
            }

            $configuration = $GLOBALS['TCA']['tt_content']['columns'][$field]['config'] ?? [];
            $fieldType = $configuration['type'] ?? null;

            if (!\in_array($fieldType, ['input', 'text', 'select'], true)) {
                continue;
            }

            $fields[] = $field;
        }

        return array_values(array_unique($fields));
    }

    /**
     * @param list<string> $fields
     *
     * @return array<string, array<string, string>>
     */
    private function fieldOptions(array $fields): array
    {
        $options = [];

        foreach ($fields as $field) {
            $configuration = $GLOBALS['TCA']['tt_content']['columns'][$field]['config'] ?? [];

            if ('select' !== ($configuration['type'] ?? null)) {
                continue;
            }

            foreach (\is_array($configuration['items'] ?? null) ? $configuration['items'] : [] as $item) {
                $value = $this->itemValue($item);

                if ('' === $value || '--div--' === $value) {
                    continue;
                }

                $options[$field][$value] = $this->itemLabel($item, $value);
            }
        }

        return $options;
    }

    /** @return list<string> */
    private function expandedFieldDefinitions(string $showItem): array
    {
        $definitions = [];

        foreach (explode(',', $showItem) as $fieldDefinition) {
            $fieldDefinition = trim($fieldDefinition);

            if (!str_starts_with($fieldDefinition, '--palette--')) {
                $definitions[] = $fieldDefinition;

                continue;
            }

            $parts = explode(';', $fieldDefinition);
            $palette = (string) ($parts[2] ?? '');
            $paletteShowItem = (string) ($GLOBALS['TCA']['tt_content']['palettes'][$palette]['showitem'] ?? '');

            if ('' !== $paletteShowItem) {
                $definitions = array_merge($definitions, explode(',', $paletteShowItem));
            }
        }

        return $definitions;
    }

    private function itemValue(mixed $item): string
    {
        if (\is_array($item)) {
            return (string) ($item['value'] ?? $item[1] ?? '');
        }

        return '';
    }

    private function itemLabel(mixed $item, string $fallback): string
    {
        if (!\is_array($item)) {
            return $fallback;
        }

        $label = (string) ($item['label'] ?? $item[0] ?? $fallback);

        return str_starts_with($label, 'LLL:')
            ? (LocalizationUtility::translate($label) ?: $fallback)
            : $label;
    }
}
