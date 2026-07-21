<?php

declare(strict_types=1);

namespace ContentFlow\Typo3Translation\Backend;

use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\Buttons\DropDown\DropDownDivider;
use TYPO3\CMS\Backend\Template\Components\Buttons\DropDown\DropDownHeader;
use TYPO3\CMS\Backend\Template\Components\Buttons\DropDown\DropDownItem;
use TYPO3\CMS\Backend\Template\Components\ModifyButtonBarEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Site\SiteFinder;

#[AsEventListener('contentflow-record-edit-button')]
final readonly class ContentFlowButtonBar
{
    public function __construct(
        private UriBuilder $uriBuilder,
        private IconFactory $iconFactory,
        private SiteFinder $siteFinder,
    ) {
    }

    public function __invoke(ModifyButtonBarEvent $event): void
    {
        $pageUid = $this->editedPageUid();

        if ($pageUid <= 0) {
            return;
        }

        $dropDown = $event->getButtonBar()
            ->makeDropDownButton()
            ->setLabel('ContentFlow')
            ->setTitle('ContentFlow AI actions for this page')
            ->setShowLabelText(true)
            ->setIcon($this->iconFactory->getIcon('contentflow-ai-module', IconSize::SMALL));

        $dropDown->addItem(
            (new DropDownItem())
                ->setLabel('Analyze SEO with AI')
                ->setTitle('Create an SEO preview for this page')
                ->setIcon($this->iconFactory->getIcon('actions-search', IconSize::SMALL))
                ->setHref($this->moduleUri('contentflow_translation.Seo_index', $pageUid)),
        );

        $languages = $this->targetLanguages($pageUid);

        if ([] !== $languages) {
            $dropDown->addItem(new DropDownDivider());
            $dropDown->addItem((new DropDownHeader())->setLabel('Translate complete page'));

            foreach ($languages as $language) {
                $dropDown->addItem(
                    (new DropDownItem())
                        ->setLabel('Translate to '.$language['title'])
                        ->setTitle('Open a translation preview for '.$language['title'])
                        ->setIcon($this->iconFactory->getIcon('actions-localize', IconSize::SMALL))
                        ->setHref($this->moduleUri(
                            'contentflow_translation.Translation_index',
                            $pageUid,
                            ['targetLanguage' => $language['code']],
                        )),
                );
            }
        }

        $buttons = $event->getButtons();
        $buttons[ButtonBar::BUTTON_POSITION_LEFT][8][] = $dropDown;
        ksort($buttons[ButtonBar::BUTTON_POSITION_LEFT]);
        $event->setButtons($buttons);
    }

    private function editedPageUid(): int
    {
        $edit = $GLOBALS['TYPO3_REQUEST']->getQueryParams()['edit']['pages'] ?? [];

        if (!\is_array($edit) || 1 !== \count($edit)) {
            return 0;
        }

        $uid = array_key_first($edit);

        return 'edit' === ($edit[$uid] ?? null) ? (int) $uid : 0;
    }

    /** @return list<array{code: string, title: string}> */
    private function targetLanguages(int $pageUid): array
    {
        try {
            $site = $this->siteFinder->getSiteByPageId($pageUid);
        } catch (\Throwable) {
            return [];
        }

        $languages = [];

        foreach ($site->getAllLanguages() as $language) {
            if (0 === $language->getLanguageId()) {
                continue;
            }

            $languages[] = [
                'code' => $language->getLocale()->getLanguageCode(),
                'title' => $language->getTitle(),
            ];
        }

        return $languages;
    }

    /** @param array<string, string> $arguments */
    private function moduleUri(string $route, int $pageUid, array $arguments = []): string
    {
        return (string) $this->uriBuilder->buildUriFromRoute($route, [
            'id' => $pageUid,
            'uid' => $pageUid,
            'table' => 'pages',
            'scope' => 'page',
            ...$arguments,
        ]);
    }
}
