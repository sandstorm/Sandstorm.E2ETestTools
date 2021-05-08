<?php

namespace Sandstorm\E2ETestTools;

use Neos\Flow\Annotations as Flow;
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
        $siteRootFusionCode = $this->readExternalFusionFile($siteRootFusionPathAndFilename);

        $mergedFusionCode = '';
        $mergedFusionCode .= $this->generateNodeTypeDefinitions();
        $mergedFusionCode .= $this->getFusionIncludes($this->prepareAutoIncludeFusion());
        $mergedFusionCode .= $this->getFusionIncludes($this->prependFusionIncludes);
        $mergedFusionCode .= $siteRootFusionCode;
        $mergedFusionCode .= $this->getFusionIncludes($this->appendFusionIncludes);

        $mergedFusionCode .= $extraFusionCode;

        return $this->fusionParser->parse($mergedFusionCode, $siteRootFusionPathAndFilename);
    }
}
