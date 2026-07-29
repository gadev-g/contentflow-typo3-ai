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

    /** @return list<array{type: string, label: string, fields: list<string>}> */
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

            if ([] === $fields) {
                continue;
            }

            $types[] = [
                'type' => $type,
                'label' => $this->itemLabel($item, $type),
                'fields' => $fields,
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
            ]];
        }

        usort(
            $types,
            static fn (array $left, array $right): int => ('text' === $left['type'] ? -1 : 0)
                <=> ('text' === $right['type'] ? -1 : 0),
        );

        return $types;
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

            if (!\in_array($fieldType, ['input', 'text'], true)) {
                continue;
            }

            $fields[] = $field;
        }

        return array_values(array_unique($fields));
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
