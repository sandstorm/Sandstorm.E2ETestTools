<?php

namespace Sandstorm\E2ETestTools\StepGenerator;

use Neos\Flow\ResourceManagement\PersistentResource;

/**
 * Model for persistent resources fixtures collected in a NodeTable.
 */
class PersistentResourceFixtures
{

    public static array $PERSISTENT_RESOURCE_DEFAULT_HEADER = [
        'Filename',
        'Collection',
        'Relative Publication Path',
        'Path'
    ];

    private ?string $fixtureBasePath;

    private array $defaultProperties = [];
    private array $fixtureResources = [];

    public function __construct(?string $fixtureBasePath, array $defaultProperties = [])
    {
        $this->fixtureBasePath = $fixtureBasePath . (str_ends_with($fixtureBasePath, '/') ? '' : '/');
        $this->defaultProperties = $defaultProperties;
    }

    /**
     * Adds a persistent resource to the fixtures for later storing.
     * Returns the gherkin table cells of the PersistentResource to be printed in a Media fixture table row.
     *
     * @param PersistentResource $persistentResource
     * @return array the PersistentResource table cells
     */
    public function addPersistentResource(PersistentResource $persistentResource): array
    {
        if ($this->fixtureBasePath === null) {
            throw new \Exception("No fixture base path given for NodeTable");
        }
        $fixturePath = $this->fixtureBasePath . $persistentResource->getSha1() . '.' . $persistentResource->getFileExtension();
        if (!array_key_exists($persistentResource->getSha1(), $this->fixtureResources)) {
            $this->fixtureResources[$persistentResource->getSha1()] = [
                'object' => $persistentResource,
                'path' => $fixturePath
            ];
        }
        return array_merge($this->defaultProperties, [
            'Filename' => $persistentResource->getFilename(),
            'Collection' => $persistentResource->getCollectionName(),
            'Relative Publication Path' => $persistentResource->getRelativePublicationPath(),
            'Path' => $fixturePath
        ]);
    }

    public function storeFixtures(): void
    {
        if (count($this->fixtureResources) === 0) {
            return;
        }
        if (!file_exists($this->fixtureBasePath)) {
            mkdir($this->fixtureBasePath, 0777, true);
        }
        foreach ($this->fixtureResources as $sha1 => $persistentResourceObjectAndPath) {
            $path = $persistentResourceObjectAndPath['path'];
            if (!file_exists($path)) {
                /**
                 * @var PersistentResource $persistentResource
                 */
                $persistentResource = $persistentResourceObjectAndPath['object'];
                file_put_contents($path, $persistentResource->getStream());
            }
        }
    }

}
