<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\StepGenerator;

use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Package\PackageManager;
use Neos\Flow\Annotations as Flow;

/**
 * Entry point of the StepGenerator for your own command controllers: inject it and start with nodeTable().
 * (A singleton, so Flow can inject the dependencies the per-use {@see NodeTableBuilder} needs.)
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
