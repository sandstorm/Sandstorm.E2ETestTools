<?php

namespace Sandstorm\E2ETestTools\StepGenerator;

use Neos\Media\Domain\Model\ImageInterface;

/**
 * Builder for printing a persistent resource fixture gherkin table.
 */
class ImageTable
{

    private static array $IMAGE_DEFAULT_HEADER = [
        'Image ID',
        'Width',
        'Height'
    ];

    private GherkinTable $gherkinTable;
    private PersistentResourceFixtures $persistentResourceFixtures;

    private array $defaultProperties;

    public function __construct(PersistentResourceFixtures $persistentResourceFixtures, array $defaultProperties = [])
    {
        $this->persistentResourceFixtures = $persistentResourceFixtures;
        $this->defaultProperties = $defaultProperties;
        $this->gherkinTable = new GherkinTable(
            array_merge(
                self::$IMAGE_DEFAULT_HEADER,
                array_keys($defaultProperties),
                PersistentResourceFixtures::$PERSISTENT_RESOURCE_DEFAULT_HEADER
            )
        );
    }

    public function addImage(string $objectIdentifier, ImageInterface $image): void
    {
        $persistentResourceCells = $this->persistentResourceFixtures->addPersistentResource($image->getResource());
        $imageCells = array_merge(
            $this->defaultProperties,
            [
                'Image ID' => $objectIdentifier,
                'Width' => $image->getWidth(),
                'Height' => $image->getHeight()
            ],
            $persistentResourceCells
        );
        $this->gherkinTable->addRow($imageCells);
    }

    public function print(): void
    {
        if ($this->gherkinTable->isEmpty()) {
            echo '# I have no images';
            echo "\n";
            return;
        }
        echo 'Given I have the following images:';
        echo "\n";
        $this->gherkinTable->print();
    }

}
