<?php

declare(strict_types=1);

namespace ContentFlow\Typo3Translation\Service;

use TYPO3\CMS\Core\Resource\ResourceFactory;

final class AssetReader
{
    public function __construct(private ResourceFactory $resourceFactory)
    {
    }

    /** @return array{uid: int, name: string, mimeType: string, contents: string, publicUrl: string, context: string} */
    public function read(int $fileUid): array
    {
        $file = $this->resourceFactory->getFileObject($fileUid);
        $mimeType = $file->getMimeType();
        if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
            throw new \RuntimeException('Please select a supported JPG, PNG, WebP, or GIF image.');
        }
        $contents = $file->getContents();
        if (strlen($contents) > 10 * 1024 * 1024) {
            throw new \RuntimeException('The selected image is larger than 10 MB.');
        }
        $metadata = $file->getMetaData()->get();
        $context = implode("\n", array_filter([
            isset($metadata['title']) ? 'Existing title: '.$metadata['title'] : '',
            isset($metadata['description']) ? 'Existing description: '.$metadata['description'] : '',
        ]));

        return ['uid' => $fileUid, 'name' => $file->getName(), 'mimeType' => $mimeType, 'contents' => $contents, 'publicUrl' => $file->getPublicUrl(), 'context' => $context];
    }
}
