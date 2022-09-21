<?php

namespace Sandstorm\E2ETestTools\StepGenerator;

use Neos\Flow\Package\PackageManager;
use Neos\Flow\Annotations as Flow;

/**
 * Public builder API to configure and create a NodeTable to use in your step generator command controller.
 *
 * @Flow\Scope("singleton")
 */
class NodeTableBuilderService
{

    /**
     * @Flow\Inject
     */
    protected PackageManager $packageManager;

    public function nodeTable(): NodeTableBuilder
    {
        return new NodeTableBuilder($this->packageManager);
    }

}
