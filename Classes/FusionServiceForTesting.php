<?php

namespace Sandstorm\E2ETestTools;

use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\Fusion\Core\FusionConfiguration;
use Neos\Fusion\Core\FusionSourceCodeCollection;
use Neos\Neos\Domain\Service\FusionService;
use Sandstorm\E2ETestTools\Tests\Behavior\Bootstrap\FusionRenderingTrait;

/**
 * Implementation detail of {@see FusionRenderingTrait}
 * @internal
 */
class FusionServiceForTesting extends FusionService
{
    /**
     * Same as {@see FusionService::createFusionConfigurationFromSite()}, but without needing a Site entity
     * (and uncached), plus $extraFusionCode appended last so it can override everything else.
     */
    public function getMergedFusionObjectTreeForPackage(string $siteResourcesPackageKey, string $extraFusionCode, ContentRepositoryId $contentRepositoryId): FusionConfiguration
    {
        return $this->fusionParser->parseFromSource(
            $this->fusionAutoIncludeHandler->loadFusionFromPackage(
                $siteResourcesPackageKey,
                $this->fusionSourceCodeFactory->createFromNodeTypeDefinitions($contentRepositoryId)
                    ->union(
                        $this->fusionSourceCodeFactory->createFromAutoIncludes()
                    )
            )->union(
                FusionSourceCodeCollection::fromString($extraFusionCode)
            )
        );
    }
}
