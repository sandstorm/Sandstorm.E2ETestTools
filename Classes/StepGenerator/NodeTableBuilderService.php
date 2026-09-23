<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\StepGenerator;

use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
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

    /**
     * @Flow\Inject
     */
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    public function nodeTable(): NodeTableBuilder
    {
        return new NodeTableBuilder($this->packageManager, $this->contentRepositoryRegistry);
    }

}
