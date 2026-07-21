<?php

declare(strict_types=1);

namespace ContentFlow\Typo3Translation\Controller;

use ContentFlow\Typo3Translation\Service\ContentFlowClient;
use Doctrine\DBAL\ParameterType;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

final class HubController extends ActionController
{
    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly ContentFlowClient $client,
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    public function indexAction(): ResponseInterface
    {
        $module = $this->moduleTemplateFactory->create($this->request);
        try {
            $context = $this->client->integrationContext();
        } catch (\Throwable) {
            $context = [];
        }

        $module->assignMultiple([
            'plan' => $context['entitlements']['plan'] ?? 'unknown',
            'products' => $context['entitlements']['products'] ?? [],
        ]);

        return $module->renderResponse('Hub/Index');
    }

    public function coverageAction(): ResponseInterface
    {
        $module = $this->moduleTemplateFactory->create($this->request);
        $module->assign('coverage', $this->contentCoverage());

        return $module->renderResponse('Hub/Coverage');
    }

    /** @return array<string, mixed> */
    private function contentCoverage(): array
    {
        $pages = $this->defaultPages();
        $pageTranslations = $this->translationCounts('pages');
        $contentTranslations = $this->translationCounts('tt_content');
        $assets = $this->defaultAssetMetadata();
        $assetTranslations = $this->translationCounts('sys_file_metadata');
        $seoPages = 0;
        $translatedPages = 0;
        $enrichedAssets = 0;
        $translatedAssets = 0;
        $imageCount = $this->imageCount();

        foreach ($pages as &$page) {
            $page['translationCount'] = $pageTranslations[(int) $page['uid']] ?? 0;
            $page['hasSeo'] = $this->hasValue($page, ['seo_title', 'description', 'focus_keywords', 'schema_org']);
            $translatedPages += $page['translationCount'] > 0 ? 1 : 0;
            $seoPages += $page['hasSeo'] ? 1 : 0;
        }
        unset($page);

        foreach ($assets as &$asset) {
            $asset['translationCount'] = $assetTranslations[(int) $asset['uid']] ?? 0;
            $asset['hasAltText'] = '' !== trim((string) ($asset['alternative'] ?? ''));
            $asset['hasMetadata'] = $this->hasValue($asset, ['title', 'alternative', 'description']);
            $translatedAssets += $asset['translationCount'] > 0 ? 1 : 0;
            $enrichedAssets += $asset['hasMetadata'] ? 1 : 0;
        }
        unset($asset);

        return [
            'summary' => [
                'pages' => \count($pages),
                'translatedPages' => $translatedPages,
                'seoPages' => $seoPages,
                'translatedContentElements' => \count($contentTranslations),
                'images' => $imageCount,
                'enrichedAssets' => $enrichedAssets,
                'translatedAssets' => $translatedAssets,
                'translatedPagePercent' => $this->percentage($translatedPages, \count($pages)),
                'seoPagePercent' => $this->percentage($seoPages, \count($pages)),
                'enrichedAssetPercent' => $this->percentage($enrichedAssets, $imageCount),
            ],
            'pages' => \array_slice($pages, 0, 50),
            'assets' => \array_slice($assets, 0, 50),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function defaultPages(): array
    {
        $query = $this->connectionPool->getQueryBuilderForTable('pages');

        return $query
            ->select(
                'uid',
                'title',
                'seo_title',
                'description',
                'tx_contentflow_focus_keywords AS focus_keywords',
                'tx_contentflow_schema_org AS schema_org',
            )
            ->from('pages')
            ->where(
                $query->expr()->eq(
                    'sys_language_uid',
                    $query->createNamedParameter(0, ParameterType::INTEGER),
                ),
            )
            ->orderBy('uid', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /** @return list<array<string, mixed>> */
    private function defaultAssetMetadata(): array
    {
        $query = $this->connectionPool->getQueryBuilderForTable('sys_file_metadata');

        return $query
            ->select(
                'metadata.uid',
                'metadata.file',
                'metadata.title',
                'metadata.alternative',
                'metadata.description',
                'file.name AS file_name',
                'file.identifier',
            )
            ->from('sys_file_metadata', 'metadata')
            ->innerJoin('metadata', 'sys_file', 'file', $query->expr()->eq('file.uid', 'metadata.file'))
            ->where(
                $query->expr()->eq(
                    'metadata.sys_language_uid',
                    $query->createNamedParameter(0, ParameterType::INTEGER),
                ),
                $query->expr()->like('file.mime_type', $query->createNamedParameter('image/%')),
            )
            ->orderBy('metadata.uid', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /** @return array<int, int> */
    private function translationCounts(string $table): array
    {
        $query = $this->connectionPool->getConnectionForTable($table)->createQueryBuilder();
        $query->getRestrictions()->removeAll();
        $parentField = 'tt_content' === $table ? 'l18n_parent' : 'l10n_parent';
        $constraints = [
            $query->expr()->gt(
                'sys_language_uid',
                $query->createNamedParameter(0, ParameterType::INTEGER),
            ),
            $query->expr()->gt(
                $parentField,
                $query->createNamedParameter(0, ParameterType::INTEGER),
            ),
        ];

        if ('sys_file_metadata' !== $table) {
            $constraints[] = $query->expr()->eq(
                'deleted',
                $query->createNamedParameter(0, ParameterType::INTEGER),
            );
        }

        $rows = $query
            ->select($parentField.' AS parent_uid')
            ->addSelectLiteral('COUNT(uid) AS translation_count')
            ->from($table)
            ->where(...$constraints)
            ->groupBy($parentField)
            ->executeQuery()
            ->fetchAllAssociative();
        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row['parent_uid']] = (int) $row['translation_count'];
        }

        return $counts;
    }

    private function imageCount(): int
    {
        $query = $this->connectionPool->getQueryBuilderForTable('sys_file');

        return (int) $query
            ->count('uid')
            ->from('sys_file')
            ->where($query->expr()->like('mime_type', $query->createNamedParameter('image/%')))
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * @param array<string, mixed> $record
     * @param list<string>         $fields
     */
    private function hasValue(array $record, array $fields): bool
    {
        foreach ($fields as $field) {
            if ('' !== trim((string) ($record[$field] ?? ''))) {
                return true;
            }
        }

        return false;
    }

    private function percentage(int $completed, int $total): int
    {
        return $total > 0 ? (int) round(($completed / $total) * 100) : 0;
    }
}
