<?php

namespace Sandstorm\E2ETestTools\Tests\Behavior\Bootstrap;

use Behat\Gherkin\Node\PyStringNode;
use Neos\Flow\Core\Bootstrap;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\ResourceManagement\ResourceManager;
use Neos\Media\Domain\Model\Document;
use Neos\Media\Domain\Repository\AssetRepository;
use Neos\Utility\ObjectAccess;

trait PersistentResourceTrait
{
    /**
     * @var AssetRepository
     */
    private AssetRepository $PersistentResourceTrait_assetRepository;

    /**
     * @var ResourceManager
     */
    private ResourceManager $PersistentResourceTrait_resourceManager;

    /**
     * @var PersistenceManagerInterface
     */
    private PersistenceManagerInterface $PersistentResourceTrait_persistenceManager;

    /**
     * @var Bootstrap
     */
    private Bootstrap $PersistentResourceTrait_bootstrap;

    public function PersistentResourceTrait_setupServices(ObjectManagerInterface $objectManager): void
    {
        $this->PersistentResourceTrait_assetRepository = $objectManager->get(AssetRepository::class);
        $this->PersistentResourceTrait_resourceManager = $objectManager->get(ResourceManager::class);
        $this->PersistentResourceTrait_persistenceManager = $objectManager->get(PersistenceManagerInterface::class);
        $this->PersistentResourceTrait_bootstrap = $objectManager->get(Bootstrap::class);
    }

    /**
     * @Given I have a textual persistent resource :uuid named :filename with the following content:
     * @throws Exception failure while storing the resource
     */
    public function iHaveATextualPersistentResourceWithTheFollowingContent(string $uuid, string $filename, PyStringNode $content): void
    {
        $resource = $this->PersistentResourceTrait_resourceManager->importResourceFromContent(
            $content->getRaw(),
            $filename);
        $document = new Document($resource);
        ObjectAccess::setProperty($document, 'Persistence_Object_Identifier', $uuid, true);
        $this->PersistentResourceTrait_assetRepository->add($document);
        $this->PersistentResourceTrait_persistenceManager->persistAll();

        $flowContext = $this->PersistentResourceTrait_bootstrap->getContext();
        exec("FLOW_CONTEXT=$flowContext ./flow resource:publish", $output, $resultCode);
    }
}
