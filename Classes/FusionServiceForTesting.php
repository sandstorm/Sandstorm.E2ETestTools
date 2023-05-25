<?php

namespace Sandstorm\E2ETestTools;

use Neos\Flow\Annotations as Flow;
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
     * @Flow\InjectConfiguration("fusion.autoInclude", package="Neos.Neos")
     * @var array
     */
    protected $autoIncludeConfiguration = [];

    public function getMergedFusionObjectTreeForPackage(string $siteResourcesPackageKey, string $extraFusionCode)
    {
        $siteRootFusionPathAndFilename = sprintf($this->siteRootFusionPattern, $siteResourcesPackageKey);

        return $this->fusionParser->parseFromSource(
            $this->fusionSourceCodeFactory->createFromNodeTypeDefinitions()
                ->union(
                    $this->fusionSourceCodeFactory->createFromAutoIncludes()
                )
                ->union(
                    FusionSourceCodeCollection::tryFromFilePath($siteRootFusionPathAndFilename)
                )
                ->union(
                    FusionSourceCodeCollection::fromString($extraFusionCode)
                )
        );
    }
}
