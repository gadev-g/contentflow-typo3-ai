<?php

declare(strict_types=1);

namespace ContentFlow\Typo3Translation\Service;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Form\FormDataCompiler;
use TYPO3\CMS\Backend\Form\FormDataGroup\TcaDatabaseRecord;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

final readonly class TargetContentSchema
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

    public function __construct(
        private FormDataCompiler $formDataCompiler,
    ) {
    }

    public function availableTypes(
        int $targetPageUid = 0,
        ?ServerRequestInterface $request = null,
    ): array {
        $items = $GLOBALS['TCA']['tt_content']['columns']['CType']['config']['items'] ?? [];
        $types = [];

        foreach ($items as $item) {
            $type = $this->itemValue($item);

            if ('' === $type || '--div--' === $type || !isset($GLOBALS['TCA']['tt_content']['types'][$type])) {
                continue;
            }

            $processedColumns = $this->processedColumns($type, $targetPageUid, $request);
            $fields = $this->editableFields($type, $processedColumns);
            $relations = $this->editableRelations($type, $processedColumns);
            $fieldOptions = $this->fieldOptions($fields, $processedColumns);

            if ([] === $fields && [] === $relations) {
                continue;
            }

            $types[] = [
                'type' => $type,
                'label' => $this->itemLabel($item, $type),
                'fields' => $fields,
                'field_labels' => $this->fieldLabels($fields, $processedColumns),
                'field_options' => $fieldOptions,
                'field_defaults' => $this->fieldDefaults($fieldOptions),
                'relations' => $relations,
            ];
        }
        if ([] === $types) {
            return [[
                'type' => 'text',
                'label' => 'Text',
                'fields' => ['header', 'bodytext'],
                'field_labels' => ['header' => 'Header', 'bodytext' => 'Text'],
                'field_options' => [],
                'field_defaults' => [],
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

    private function editableRelations(string $type, array $processedColumns): array
    {
        $showItem = (string) ($GLOBALS['TCA']['tt_content']['types'][$type]['showitem'] ?? '');
        $relations = [];

        foreach ($this->expandedFieldDefinitions($showItem) as $fieldDefinition) {
            $field = trim(explode(';', $fieldDefinition)[0]);
            $configuration = $processedColumns[$field]['config']
                ?? $GLOBALS['TCA']['tt_content']['columns'][$field]['config']
                ?? [];
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

    private function editableFields(string $type, array $processedColumns): array
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

            $configuration = $processedColumns[$field]['config']
                ?? $GLOBALS['TCA']['tt_content']['columns'][$field]['config']
                ?? [];
            $fieldType = $configuration['type'] ?? null;

            if (!\in_array($fieldType, ['input', 'text', 'select', 'check'], true)) {
                continue;
            }

            $fields[] = $field;
        }

        return array_values(array_unique($fields));
    }

    private function fieldOptions(array $fields, array $processedColumns): array
    {
        $options = [];

        foreach ($fields as $field) {
            $configuration = $processedColumns[$field]['config']
                ?? $GLOBALS['TCA']['tt_content']['columns'][$field]['config']
                ?? [];

            if ('check' === ($configuration['type'] ?? null)) {
                $options[$field] = [
                    '0' => 'No',
                    '1' => 'Yes',
                ];

                continue;
            }
            if ('select' !== ($configuration['type'] ?? null)) {
                continue;
            }
            foreach (\is_array($configuration['items'] ?? null) ? $configuration['items'] : [] as $item) {
                $value = $this->itemValue($item);

                if ('--div--' === $value) {
                    continue;
                }

                $options[$field][$value] = $this->itemLabel($item, $value);
            }
        }

        return $options;
    }

    private function fieldDefaults(array $fieldOptions): array
    {
        $defaults = [];

        if (isset($fieldOptions['frame_class']['container'])) {
            $defaults['frame_class'] = 'container';
        }
        foreach (['space_before_class', 'space_after_class'] as $spacingField) {
            $firstSpacing = array_key_first($fieldOptions[$spacingField] ?? []);

            if (null !== $firstSpacing && '' !== (string) $firstSpacing) {
                $defaults[$spacingField] = (string) $firstSpacing;
            }
        }

        return $defaults;
    }

    private function fieldLabels(array $fields, array $processedColumns): array
    {
        $labels = [];

        foreach ($fields as $field) {
            $label = (string) (
                $processedColumns[$field]['label']
                ?? $GLOBALS['TCA']['tt_content']['columns'][$field]['label']
                ?? $field
            );
            $labels[$field] = str_starts_with($label, 'LLL:')
                ? (LocalizationUtility::translate($label) ?: $field)
                : $label;
        }

        return $labels;
    }

    private function processedColumns(
        string $type,
        int $targetPageUid,
        ?ServerRequestInterface $request,
    ): array {
        if ($targetPageUid <= 0 || null === $request) {
            return [];
        }
        try {
            $formData = $this->formDataCompiler->compile(
                [
                    'request' => $request,
                    'tableName' => 'tt_content',
                    'vanillaUid' => $targetPageUid,
                    'command' => 'new',
                    'defaultValues' => [
                        'tt_content' => [
                            'CType' => $type,
                            'pid' => $targetPageUid,
                        ],
                    ],
                ],
                GeneralUtility::makeInstance(TcaDatabaseRecord::class),
            );
        } catch (\Throwable) {
            return [];
        }

        $columns = $formData['processedTca']['columns'] ?? [];

        return \is_array($columns) ? $columns : [];
    }

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
