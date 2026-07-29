<?php

declare(strict_types=1);

namespace ContentFlow\Typo3Translation\Service;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

final readonly class ReferencePagePatternCatalog
{
    public function __construct(private ConnectionPool $connectionPool)
    {
    }

    /**
     * @param list<array<string, mixed>> $targetTypes
     *
     * @return list<array<string, mixed>>
     */
    public function enrich(
        array $targetTypes,
        int $referencePageUid,
        string $patternField = 'reference_patterns',
    ): array {
        if ($referencePageUid <= 0) {
            return $targetTypes;
        }

        if (!\in_array($patternField, ['reference_patterns', 'catalog_patterns'], true)) {
            throw new \InvalidArgumentException('Unsupported migration pattern field.');
        }

        $typesByName = [];

        foreach ($targetTypes as $index => $targetType) {
            $type = \is_string($targetType['type'] ?? null) ? $targetType['type'] : '';

            if ('' === $type) {
                continue;
            }

            $targetTypes[$index][$patternField] = [];
            $typesByName[$type] = $index;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $rows = $queryBuilder
            ->select('*')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq(
                    'pid',
                    $queryBuilder->createNamedParameter($referencePageUid, Connection::PARAM_INT),
                ),
                $queryBuilder->expr()->eq(
                    'sys_language_uid',
                    $queryBuilder->createNamedParameter(0, Connection::PARAM_INT),
                ),
            )
            ->orderBy('colPos')
            ->addOrderBy('sorting')
            ->executeQuery()
            ->fetchAllAssociative();

        foreach ($rows as $position => $row) {
            $type = (string) ($row['CType'] ?? '');
            $targetIndex = $typesByName[$type] ?? null;

            if (null === $targetIndex) {
                continue;
            }

            if (\count($targetTypes[$targetIndex][$patternField]) >= 25) {
                continue;
            }

            $allowedFields = \is_array($targetTypes[$targetIndex]['fields'] ?? null)
                ? $targetTypes[$targetIndex]['fields']
                : [];
            $fieldOptions = \is_array($targetTypes[$targetIndex]['field_options'] ?? null)
                ? $targetTypes[$targetIndex]['field_options']
                : [];
            $fieldValues = [];
            $optionValues = [];
            $emptyFields = [];

            foreach ($allowedFields as $field) {
                if (
                    !\is_string($field)
                    || !\is_scalar($row[$field] ?? null)
                ) {
                    continue;
                }

                $value = (string) $row[$field];

                if ('' !== trim($value)) {
                    $fieldValues[$field] = mb_substr($value, 0, 2000);
                }

                if (
                    '' === trim($value)
                    && \in_array($field, ['header', 'subheader'], true)
                ) {
                    $emptyFields[] = $field;
                }

                if (
                    \is_array($fieldOptions[$field] ?? null)
                    && \array_key_exists($value, $fieldOptions[$field])
                ) {
                    $optionValues[$field] = $value;
                }
            }

            $targetTypes[$targetIndex][$patternField][] = [
                'id' => \sprintf('pages:%d:tt_content:%d', $referencePageUid, (int) $row['uid']),
                'label' => $this->patternLabel($targetTypes[$targetIndex], $row),
                'reference_page_uid' => $referencePageUid,
                'reference_record_uid' => (int) $row['uid'],
                'position' => $position,
                'column' => (int) ($row['colPos'] ?? 0),
                'field_values' => $fieldValues,
                'option_values' => $optionValues,
                'empty_fields' => $emptyFields,
                'relation_counts' => $this->relationCounts($targetTypes[$targetIndex], (int) $row['uid']),
                'container_columns' => $this->containerColumns(
                    $referencePageUid,
                    (int) $row['uid'],
                ),
            ];
        }

        return $targetTypes;
    }

    /** @param array<string, mixed> $targetType */
    private function patternLabel(array $targetType, array $row): string
    {
        $typeLabel = \is_string($targetType['label'] ?? null)
            ? $targetType['label']
            : (string) ($row['CType'] ?? 'Content element');
        $header = trim((string) ($row['header'] ?? ''));

        return '' === $header ? $typeLabel : $typeLabel . ': ' . mb_substr(strip_tags($header), 0, 100);
    }

    /**
     * @param array<string, mixed> $targetType
     *
     * @return array<string, int>
     */
    private function relationCounts(array $targetType, int $parentUid): array
    {
        $counts = [];

        foreach (\is_array($targetType['relations'] ?? null) ? $targetType['relations'] : [] as $field => $relation) {
            if (!\is_string($field) || !\is_array($relation)) {
                continue;
            }

            $table = \is_string($relation['table'] ?? null) ? $relation['table'] : '';
            $foreignField = (string) (
                $GLOBALS['TCA']['tt_content']['columns'][$field]['config']['foreign_field']
                ?? ''
            );

            if ('' === $table || '' === $foreignField) {
                continue;
            }

            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
            $counts[$field] = (int) $queryBuilder
                ->count('uid')
                ->from($table)
                ->where(
                    $queryBuilder->expr()->eq(
                        $foreignField,
                        $queryBuilder->createNamedParameter($parentUid, Connection::PARAM_INT),
                    ),
                )
                ->executeQuery()
                ->fetchOne();
        }

        return $counts;
    }

    /** @return list<int> */
    private function containerColumns(int $pageUid, int $parentUid): array
    {
        $connection = $this->connectionPool->getConnectionForTable('tt_content');
        $schemaManager = $connection->createSchemaManager();

        if (
            !$schemaManager->tablesExist(['tt_content'])
            || !$schemaManager->introspectTable('tt_content')->hasColumn('tx_container_parent')
        ) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $columns = $queryBuilder
            ->select('colPos')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq(
                    'pid',
                    $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT),
                ),
                $queryBuilder->expr()->eq(
                    'tx_container_parent',
                    $queryBuilder->createNamedParameter($parentUid, Connection::PARAM_INT),
                ),
            )
            ->orderBy('colPos')
            ->addOrderBy('sorting')
            ->executeQuery()
            ->fetchFirstColumn();

        return array_values(array_unique(array_map('intval', $columns)));
    }
}
