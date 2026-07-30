<?php

declare(strict_types=1);

namespace ContentFlow\Typo3Translation\Service;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\RequestFactory;

final readonly class HtmlSourceScraper
{
    private bool $allowPrivateHosts;

    public function __construct(
        private RequestFactory $requestFactory,
        ExtensionConfiguration $extensionConfiguration,
    ) {
        $configuration = $extensionConfiguration->get('contentflow_translation');
        $this->allowPrivateHosts = filter_var(
            $configuration['allowPrivateSourceHosts'] ?? false,
            \FILTER_VALIDATE_BOOL,
        );
    }

    public function scrape(string $sourceUrl): array
    {
        $parts = parse_url(trim($sourceUrl));

        if (
            !\is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || !\in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
        ) {
            throw new \RuntimeException('Enter a valid public source page URL.');
        }

        $this->assertSafeHost((string) $parts['host']);
        $response = $this->requestFactory->request($sourceUrl, 'GET', [
            'headers' => [
                'Accept' => 'text/html,application/xhtml+xml',
                'User-Agent' => 'ContentFlow-Migration/1.0',
            ],
            'timeout' => 30,
            'allow_redirects' => false,
        ]);
        $contentType = strtolower($response->getHeaderLine('Content-Type'));
        $html = (string) $response->getBody();

        if ($response->getStatusCode() >= 300 || !str_contains($contentType, 'text/html')) {
            throw new \RuntimeException('The source URL did not return an HTML page.');
        }
        if ('' === trim($html) || \strlen($html) > 5_000_000) {
            throw new \RuntimeException('The source HTML is empty or exceeds the 5 MB limit.');
        }

        return $this->parse($sourceUrl, $html);
    }

    private function parse(string $sourceUrl, string $html): array
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($html, \LIBXML_NONET | \LIBXML_NOERROR | \LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($document);

        foreach ($xpath->query('//script|//style|//noscript|//template|//nav|//footer') ?: [] as $node) {
            $node->parentNode?->removeChild($node);
        }

        $title = trim((string) ($xpath->evaluate('string(//title)') ?: $sourceUrl));
        $root = $xpath->query('//main|//*[@role="main"]|//body')->item(0);

        if (!$root instanceof \DOMElement) {
            throw new \RuntimeException('The source page has no readable document body.');
        }

        $elements = [];
        $seen = [];
        $nodes = $xpath->query(
            './/*[self::h1 or self::h2 or self::h3 or self::h4 or self::p or self::ul or self::ol or self::blockquote or self::figure or self::img]',
            $root,
        );

        foreach ($nodes ?: [] as $node) {
            if (!$node instanceof \DOMElement || $this->hasSelectedAncestor($node, $seen)) {
                continue;
            }

            $tag = strtolower($node->tagName);

            if ('img' === $tag || 'figure' === $tag) {
                $image = 'img' === $tag ? $node : $xpath->query('.//img', $node)->item(0);

                if ($image instanceof \DOMElement && '' !== trim($image->getAttribute('src'))) {
                    $alternative = trim($image->getAttribute('alt'));
                    $caption = trim($xpath->evaluate('string(.//figcaption)', $node));
                    $elements[] = [
                        'uid' => \count($elements) + 1,
                        'table' => 'html',
                        'type' => 'text',
                        'fields' => [
                            'bodytext' => trim(
                                ($alternative ? '<p>Image: ' . htmlspecialchars($alternative) . '</p>' : '')
                                . ($caption ? '<p>' . htmlspecialchars($caption) . '</p>' : ''),
                            ),
                        ],
                        'relations' => [],
                        'media' => [],
                    ];
                    $seen[spl_object_id($node)] = true;
                }

                continue;
            }

            $content = trim($document->saveHTML($node) ?: '');

            if ('' === trim(strip_tags($content))) {
                continue;
            }

            $isHeading = preg_match('/^h[1-4]$/', $tag) === 1;
            $elements[] = [
                'uid' => \count($elements) + 1,
                'table' => 'html',
                'type' => $isHeading ? 'header' : 'text',
                'fields' => $isHeading
                    ? ['header' => trim($node->textContent)]
                    : ['bodytext' => $content],
                'relations' => [],
                'media' => [],
            ];
            $seen[spl_object_id($node)] = true;
        }
        if ([] === $elements) {
            throw new \RuntimeException('No editorial content could be detected in the source HTML.');
        }

        return [
            'schema_version' => '1.0',
            'source' => ['url' => $sourceUrl, 'title' => $title, 'mode' => 'html'],
            'elements' => array_slice($elements, 0, 100),
            'media' => [],
        ];
    }

    private function hasSelectedAncestor(\DOMElement $node, array $seen): bool
    {
        $parent = $node->parentNode;

        while ($parent instanceof \DOMElement) {
            if (isset($seen[spl_object_id($parent)])) {
                return true;
            }

            $parent = $parent->parentNode;
        }

        return false;
    }

    private function assertSafeHost(string $host): void
    {
        if ($this->allowPrivateHosts) {
            return;
        }

        $addresses = filter_var($host, \FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);

        if ([] === $addresses) {
            throw new \RuntimeException('The source host could not be resolved.');
        }
        foreach ($addresses as $address) {
            if (
                false === filter_var(
                    $address,
                    \FILTER_VALIDATE_IP,
                    \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE,
                )
            ) {
                throw new \RuntimeException('Private source hosts are disabled in Extension Configuration.');
            }
        }
    }
}
