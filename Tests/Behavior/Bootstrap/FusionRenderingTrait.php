<?php

namespace Sandstorm\E2ETestTools\Tests\Behavior\Bootstrap;

use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Gherkin\Node\PyStringNode;
use Behat\Gherkin\Node\TableNode;
use Behat\Step\Given;
use Behat\Testwork\Hook\Scope\AfterSuiteScope;
use Behat\Testwork\Hook\Scope\BeforeSuiteScope;
use GuzzleHttp\Psr7\ServerRequest;
use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Factory\ContentRepositoryServiceFactoryDependencies;
use Neos\ContentRepository\Core\Factory\ContentRepositoryServiceFactoryInterface;
use Neos\ContentRepository\Core\Factory\ContentRepositoryServiceInterface;
use Neos\ContentRepository\Core\Feature\NodeCreation\Command\CreateNodeAggregateWithNode;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\PropertyValuesToWrite;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\SerializedPropertyValue;
use Neos\ContentRepository\Core\Feature\NodeReferencing\Command\SetNodeReferences;
use Neos\ContentRepository\Core\Feature\NodeReferencing\Dto\NodeReferencesForName;
use Neos\ContentRepository\Core\Feature\NodeReferencing\Dto\NodeReferencesToWrite;
use Neos\ContentRepository\Core\Feature\RootNodeCreation\Command\CreateRootNodeAggregateWithNode;
use Neos\ContentRepository\Core\Infrastructure\Property\PropertyConverter;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\Service\ContentRepositoryMaintainerFactory;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateIds;
use Neos\ContentRepository\Core\SharedModel\Node\NodeName;
use Neos\ContentRepository\Core\SharedModel\Node\ReferenceName;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Cache\CacheManager;
use Neos\Flow\Http\ServerRequestAttributes;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Mvc\ActionResponse;
use Neos\Flow\Mvc\Controller\Arguments;
use Neos\Flow\Mvc\Controller\ControllerContext;
use Neos\Flow\Mvc\Routing\Dto\RouteParameters;
use Neos\Flow\Mvc\Routing\UriBuilder;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Fusion\Core\Runtime;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Model\WorkspaceDescription;
use Neos\Neos\Domain\Model\WorkspaceRoleAssignments;
use Neos\Neos\Domain\Model\WorkspaceTitle;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Repository\WorkspaceMetadataAndRoleRepository;
use Neos\Neos\Domain\Service\WorkspaceService;
use Neos\Utility\Files;
use PHPUnit\Framework\Assert;
use Sandstorm\E2ETestTools\FusionServiceForTesting;
use Sandstorm\E2ETestTools\FusionRenderingResult;
use Symfony\Component\DomCrawler\Crawler;

require_once(__DIR__ . "/PersistentResourceTrait.php");

/**
 * This trait is only useful in NEOS applications; not in Symfony projects.
 */
trait FusionRenderingTrait
{
    use PersistentResourceTrait;

    abstract public function getObjectManager(): ObjectManagerInterface;

    private string $sitePackageKey;

    private ContentRepository $contentRepository;

    private PropertyConverter $propertyConverter;

    private NodeAggregateId $sitesNodeAggregateId;

    public function setupFusionRendering(string $sitePackageKey)
    {
        $this->sitePackageKey = $sitePackageKey;
        $this->PersistentResourceTrait_setupServices($this->getObjectManager());
    }

    /**
     * @Given I have a site for Site Node :siteNodeName
     */
    public function iHaveASite($siteNodeName)
    {
        $this->createAndPersistSite($siteNodeName);
    }

    /**
     * @Given I have a site for Site Node :siteNodeName with name :siteName
     */
    public function iHaveASiteWithName($siteNodeName, $siteName)
    {
        /** @var SiteRepository $siteRepository */
        $siteRepository = $this->getObjectManager()->get(SiteRepository::class);
        if (
            $siteRepository->findOneByNodeName($siteNodeName) == null
            && $siteRepository->findDefault()?->getNodeName() != $siteNodeName
        ) {
            $this->createAndPersistSite($siteNodeName, function ($site) use ($siteName) {
                $site->setName($siteName);
                return $site;
            });
        }
    }

    protected function createAndPersistSite($siteNodeName, $mapper = null)
    {
        $site = new Site($siteNodeName);
        $site->setState(Site::STATE_ONLINE);
        $site->setSiteResourcesPackageKey($this->sitePackageKey);
        if ($mapper !== null) {
            $site = $mapper($site);
        }
        /** @var SiteRepository $siteRepository */
        $siteRepository = $this->getObjectManager()->get(SiteRepository::class);
        $siteRepository->add($site);

        $this->getObjectManager()->get(PersistenceManagerInterface::class)->persistAll();
    }

    /**
     * Initialize the Neos 9 content repository for a test scenario.
     *
     * Prunes all CR event streams, creates the live workspace and the Neos.Neos:Sites root node.
     * Must be called before any node creation steps (e.g. from a @BeforeScenario hook).
     */
    public function setupContentRepository(): void
    {
        /** @var ContentRepositoryRegistry $registry */
        $registry = $this->getObjectManager()->get(ContentRepositoryRegistry::class);
        $crId = ContentRepositoryId::fromString('default');

        $maintainer = $registry->buildService($crId, new ContentRepositoryMaintainerFactory());

        $setupError = $maintainer->setUp();
        if ($setupError !== null) {
            throw new \RuntimeException('CR setUp failed: ' . $setupError->getMessage());
        }

        $pruneError = $maintainer->prune();
        if ($pruneError !== null) {
            throw new \RuntimeException('CR prune failed: ' . $pruneError->getMessage());
        }
        $workspaceMetadataAndRoleRepository = $this->getObjectManager()->get(WorkspaceMetadataAndRoleRepository::class);
        $workspaceMetadataAndRoleRepository->pruneWorkspaceMetadata($crId);
        $workspaceMetadataAndRoleRepository->pruneRoleAssignments($crId);

        $this->contentRepository = $registry->get($crId);

        $this->propertyConverter = $registry->buildService(
            $crId,
            new class implements ContentRepositoryServiceFactoryInterface {
                public function build(ContentRepositoryServiceFactoryDependencies $deps): ContentRepositoryServiceInterface {
                    return new class($deps->propertyConverter) implements ContentRepositoryServiceInterface {
                        public function __construct(public readonly PropertyConverter $converter) {}
                    };
                }
            }
        )->converter;

        $liveWorkspace = $this->contentRepository->findWorkspaceByName(WorkspaceName::forLive());
        if ($liveWorkspace === null) {
            $this->getObjectManager()->get(WorkspaceService::class)->createRootWorkspace(
                $crId,
                WorkspaceName::forLive(),
                WorkspaceTitle::fromString('live'),
                WorkspaceDescription::createEmpty(),
                WorkspaceRoleAssignments::createForLiveWorkspace()
            );
        }

        $this->sitesNodeAggregateId = NodeAggregateId::fromString('sites');
        $this->contentRepository->handle(CreateRootNodeAggregateWithNode::create(
            WorkspaceName::forLive(),
            $this->sitesNodeAggregateId,
            NodeTypeName::fromString('Neos.Neos:Sites')
        ));

        $cacheManager = $this->getObjectManager()->get(CacheManager::class);
        foreach (['Flow_Mvc_Routing_Route', 'Flow_Mvc_Routing_Resolve', 'Neos_Fusion_Content'] as $cacheIdentifier) {
            $cacheManager->getCache($cacheIdentifier)->flush();
        }
    }

    /**
     * @var FusionRenderingResult
     */
    protected $lastFusionRenderingResult;

    /**
     * @When I render the Fusion object :fusionPath:
     */
    public function iRenderTheFusionObject($fusionPath, PyStringNode $additionalFusion)
    {
        $fusionRenderingResult = new FusionRenderingResult();
        $this->internalRender('e2eTestRoot', $additionalFusion->getRaw(), [
            // both used in Root.fusion
            'fusionRenderingResult' => $fusionRenderingResult,
            'renderPath' => $fusionPath
        ], 'e2eTestRoot');
        $this->lastFusionRenderingResult = $fusionRenderingResult;
    }

    /**
     * @When I render the Fusion object :fusionPath with the current context node:
     */
    public function iRenderTheFusionObjectWithNode($fusionPath, PyStringNode $additionalFusion)
    {
        // NOTE: $this->currentNodes is not available in Neos 9 — this step requires additional setup
        Assert::assertEquals(1, count($this->currentNodes));

        $fusionRenderingResult = new FusionRenderingResult();
        $node = $this->currentNodes[0];
        try {
            $documentNode = (new \Neos\Eel\FlowQuery\FlowQuery([$node]))->closest('[instanceof Neos.Neos:Document]')->get(0);
        } catch (\Exception $e) {
            $documentNode = null;
        }

        $this->internalRender('e2eTestRoot', $additionalFusion->getRaw(), [
            'node' => $node,
            'documentNode' => $documentNode,
            'site' => $documentNode,
            // both used in Root.fusion
            'fusionRenderingResult' => $fusionRenderingResult,
            'renderPath' => $fusionPath
        ]);

        $this->lastFusionRenderingResult = $fusionRenderingResult;
    }

    /**
     * @When I render the page
     */
    public function iRenderThePage()
    {
        // NOTE: $this->currentNodes is not available in Neos 9 — this step requires additional setup
        $fusionRenderingResult = new FusionRenderingResult();
        $additionalFusion = "
            prototype(Neos.Neos:Page) {
                @class = 'Neos\\\\Fusion\\\\FusionObjects\\\\JoinImplementation'
                httpResponseHead >
            }
        ";
        $result = $this->internalRender('root', $additionalFusion, [
            'node' => $this->currentNodes[0],
            'site' => $this->currentNodes[0],
            'documentNode' => $this->currentNodes[0],
        ]);

        $fusionRenderingResult->setAndReturnRenderedElement($result);
        $fusionRenderingResult->setAndReturnRenderedPage($result);

        $this->lastFusionRenderingResult = $fusionRenderingResult;
    }

    private function internalRender(string $fusionPath, string $additionalFusion, $fusionContext = [])
    {
        $fusionService = $this->getObjectManager()->get(FusionServiceForTesting::class);
        $fusionObjectTree = $fusionService->getMergedFusionObjectTreeForPackage($this->sitePackageKey, $additionalFusion);

        // to generate links without /index.php/
        putenv('FLOW_REWRITEURLS=1');
        $httpRequest = new ServerRequest('GET', 'http://neos.test/');
        $httpRequest = $httpRequest->withAttribute(ServerRequestAttributes::ROUTING_PARAMETERS, RouteParameters::createEmpty()->withParameter('requestUriHost', 'neos.test'));
        $actionRequest = ActionRequest::fromHttpRequest($httpRequest);
        // needed to generate links
        $actionRequest->setFormat('html');
        $uriBuilder = new UriBuilder();
        $uriBuilder->setRequest($actionRequest);
        $controllerContext = new ControllerContext($actionRequest, new ActionResponse(), new Arguments([]), $uriBuilder);
        $runtime = new Runtime($fusionObjectTree, $controllerContext);

        $runtime->pushContextArray($fusionContext);
        // as a side effect of rendering, $fusionContext['fusionRenderingResult'] gets filled.
        $result = $runtime->evaluate($fusionPath);
        $runtime->popContext();

        return $result;
    }


    /**
     * @Then the Fusion output should equal to :expected
     */
    public function theFusionOutputShouldEqualTo($expected)
    {
        Assert::assertEquals($expected, $this->lastFusionRenderingResult->getRenderedElement());
    }

    /**
     * @BeforeSuite
     */
    public static function removeStyleguideOnBoot(BeforeSuiteScope $scope)
    {
        // we need to instanciate the constructor here, in order to have the autoloader
        // of Flow start up (so that we can load the Files class)
        new static();
        if (is_dir(FLOW_PATH_WEB . 'styleguide')) {
            Files::removeDirectoryRecursively(FLOW_PATH_WEB . 'styleguide');
        }
    }

    private BeforeScenarioScope $fusionRendering_currentStep;

    /**
     * @BeforeScenario
     */
    public function fusionRenderingBeforeScenario(BeforeScenarioScope $event): void
    {
        $this->fusionRendering_currentStep = $event;
    }

    /**
     * @Then I store the Fusion output in the styleguide as :name
     */
    public function iStoreTheFusionOutputInTheStyleguideAs(string $name)
    {
        $this->storeFusionOutputInStyleguideInternal($name, '');
    }


    /**
     * @Then I store the Fusion output in the styleguide as :name using viewport width :viewportWidth
     */
    public function iStoreTheFusionOutputInTheStyleguideAsUsingViewportWidth(string $name, string $viewportWidth)
    {
        $this->storeFusionOutputInStyleguideInternal($name, sprintf('page.setViewportSize({width: %s, height: 720});', $viewportWidth));
    }

    private function storeFusionOutputInStyleguideInternal(string $name, string $extraScript)
    {
        Files::createDirectoryRecursively(FLOW_PATH_WEB . 'styleguide');

        file_put_contents(FLOW_PATH_WEB . 'styleguide/' . $name . '.html', $this->lastFusionRenderingResult->getRenderedPage());

        if (!property_exists($this, 'playwrightConnector')) {
            throw new \RuntimeException('You need to run setupPlaywright() from PlaywrightTrait before calling this method.');
        }

        if ($this->playwrightContext === null) {
            throw new \RuntimeException('You need to annotate your Feature with @playwright if you want to use the styleguide feature');
        }

        $base64Image = $this->playwrightConnector->execute($this->playwrightContext, sprintf('
                const page = await context.newPage();
                %s
                await page.goto("BASEURL/styleguide/%s.html");
                const contentHandle = await page.$(".sandstorm_e2etesttools_fullwrapper");
                if (contentHandle) {
                    const buffer = await contentHandle.screenshot();
                    return buffer.toString("base64");
                } else {
                    const buffer = await page.screenshot({fullPage: true});
                    return buffer.toString("base64");
                }
            ', $extraScript, $name));
        $image = base64_decode($base64Image);

        file_put_contents(FLOW_PATH_WEB . 'styleguide/' . $name . '.png', $image);

    }

    /**
     * @AfterSuite
     */
    public static function renderStyleguideIndexFile(AfterSuiteScope $scope)
    {
        if (is_dir(FLOW_PATH_WEB . 'styleguide')) {
            $indexFileContents = '<html><head><title>Styleguide</title></head><body>';

            foreach (glob(FLOW_PATH_WEB . 'styleguide/*.html') as $filename) {
                $basename = basename($filename, '.html');
                $indexFileContents .= sprintf('<h2>%s</h2><a href="%s.html"><img src="%s.png" /></a>', $basename, $basename, $basename);
            }
            $indexFileContents .= '</body></html>';
            file_put_contents(FLOW_PATH_WEB . 'styleguide/index.html', $indexFileContents);

            echo 'The STYLEGUIDE can be found at http://127.0.0.1:8080/styleguide/';
        }
    }

    /**
     * @Then in the fusion output, the inner HTML of CSS selector :selector matches :expected
     */
    public function inTheFusionOutputTheInnerHtmlOfCssSelectorMatches($selector, $expected)
    {
        $crawler = new Crawler($this->lastFusionRenderingResult->getRenderedElement());
        $crawler = $crawler->filter($selector);
        $actual = $crawler->html();
        Assert::assertEquals($expected, $actual);
    }

    /**
     * @Then in the fusion output, the attributes of CSS selector :selector are:
     */
    public function inTheFusionOutputTheAttributesOfSelectorAre($selector, TableNode $expected)
    {
        $crawler = new Crawler($this->lastFusionRenderingResult->getRenderedElement());
        $crawler = $crawler->filter($selector);

        foreach ($expected->getHash() as $row) {
            assert(isset($row['Key']));
            assert(isset($row['Value']));
            $key = $row['Key'];
            $expected = $row['Value'];

            $actual = trim($crawler->attr($key));
            Assert::assertEquals($expected, $actual, 'The attribute values for ' . $key . ' do not match.');

        }
    }

    /**
     * Deserialises fixture property values from their JSON-decoded form to the PHP types
     * expected by Neos 9 CR, using the same Symfony Serializer normalizer stack that the
     * event store uses when reading properties back.
     *
     * Feature files should express values in the event-store serialised form:
     *   - scalars (string/int/float/bool) as-is
     *   - DateTime as ISO 8601 string, e.g. "2022-08-02T00:00:00+00:00"
     *   - Doctrine entities as {"__flow_object_type":"…","__identifier":"uuid"}
     *   - null or omitted key to leave a property unset
     */
    private function deserializePropertyValues(array $properties, string $nodeTypeName): array
    {
        $nodeType = $this->contentRepository->getNodeTypeManager()->getNodeType($nodeTypeName);

        foreach ($properties as $propertyName => $value) {
            if ($value === null) {
                continue;
            }
            $declaredType = $nodeType->getPropertyType($propertyName);
            $properties[$propertyName] = $this->propertyConverter->deserializePropertyValue(
                SerializedPropertyValue::create($value, $declaredType)
            );
        }

        return $properties;
    }

    /**
     * Creates nodes in the content repository from a Gherkin table.
     *
     * Supported columns: NodeAggregateId, Parent, Node Type, Properties (JSON), Language
     * The /sites root node is created automatically by setupContentRepository() and must not be repeated here.
     */
    #[Given("I have the following nodes in site :siteName:")]
    #[Given("I create the following nodes in site :siteName:")]
    public function iHaveTheFollowingNodesInSite(string $siteName, $table)
    {
        // TODO:
        //   - allow setting defaults for dimensionSpacePoint ("Given I am in ...")
        //   - add option to specify content repository and workspace (optional, use default/live if not given/empty)
        $rows = $table instanceof TableNode ? $table->getHash() : $table->getHash();

        foreach ($rows as $row) {
            $parentValue = $row['Parent'] ?? '';
            $isDirectSiteChild = $parentValue === '';

            $nodeAggregateId = NodeAggregateId::fromString($row['NodeAggregateId']);

            //TODO: default for dimension space point
            $dimensionSpacePointJson = !empty($row['DimensionSpacePoint']) ? $row['DimensionSpacePoint'] : null;
            $dimensionSpacePoint = $dimensionSpacePointJson !== null
                ? DimensionSpacePoint::fromArray(json_decode($dimensionSpacePointJson, associative: true))
                : DimensionSpacePoint::fromArray([]);
            $originDimensionSpacePoint = OriginDimensionSpacePoint::fromDimensionSpacePoint($dimensionSpacePoint);

            if ($isDirectSiteChild) {
                $parentNodeAggregateId = $this->sitesNodeAggregateId;
            } elseif (str_contains($parentValue, '/')) {
                [$ownerId, $childString] = explode('/', $parentValue, 2);
                $children = explode('/', $childString);

                $parentNodeAggregateId = NodeAggregateId::fromString($ownerId);
                foreach ($children as $childName) {
                    $parentNodeAggregateId = $this->findTetheredChildId(
                        $parentNodeAggregateId,
                        NodeName::fromString($childName)
                    );
                }
            } else {
                $parentNodeAggregateId = NodeAggregateId::fromString($parentValue);
            }

            $propertiesJson = !empty($row['Properties']) ? $row['Properties'] : '[]';
            $propertiesArray = json_decode($propertiesJson, true) ?? [];
            $propertiesArray = $this->deserializePropertyValues($propertiesArray, $row['NodeType']);
            $propertyValues = PropertyValuesToWrite::fromArray($propertiesArray);

            $command = CreateNodeAggregateWithNode::create(
                WorkspaceName::forLive(),
                $nodeAggregateId,
                NodeTypeName::fromString($row['NodeType']),
                $originDimensionSpacePoint,
                $parentNodeAggregateId,
                initialPropertyValues: $propertyValues
            );

            if ($isDirectSiteChild) {
                $command = $command->withNodeName(NodeName::fromString($siteName));
            }

            $this->contentRepository->handle($command);
        }
    }

    /**
     * Sets node references (type: references) from a Gherkin table.
     *
     * Use this step after "I have the following nodes in site" to express node references.
     *
     * Supported columns: NodeAggregateId, ReferenceName, Targets (comma-separated NodeAggregateIds), DimensionSpacePoint
     */
    #[Given("the following node references:")]
    public function iSetTheFollowingNodeReferencesInSite(TableNode $table): void
    {
        foreach ($table->getHash() as $row) {
            $dimensionSpacePointJson = !empty($row['DimensionSpacePoint']) ? $row['DimensionSpacePoint'] : null;
            $dimensionSpacePoint = $dimensionSpacePointJson !== null
                ? DimensionSpacePoint::fromArray(json_decode($dimensionSpacePointJson, associative: true))
                : DimensionSpacePoint::fromArray([]);

            $targetIds = array_map(
                fn(string $id) => NodeAggregateId::fromString(trim($id)),
                explode(',', $row['Targets'])
            );

            $this->contentRepository->handle(SetNodeReferences::create(
                WorkspaceName::forLive(),
                NodeAggregateId::fromString($row['NodeAggregateId']),
                OriginDimensionSpacePoint::fromDimensionSpacePoint($dimensionSpacePoint),
                NodeReferencesToWrite::create(
                    NodeReferencesForName::fromTargets(
                        ReferenceName::fromString($row['ReferenceName']),
                        NodeAggregateIds::create(...$targetIds)
                    )
                )
            ));
        }
    }

    private function findTetheredChildId(NodeAggregateId $parentId, NodeName $childName): NodeAggregateId
    {
        $contentGraph = $this->contentRepository->getContentGraph(WorkspaceName::forLive());
        $childAggregate = $contentGraph->findChildNodeAggregateByName($parentId, $childName);
        if ($childAggregate === null) {
            throw new \RuntimeException(sprintf(
                'No tethered child "%s" found under node "%s". Make sure the parent node is inserted before this row.',
                $childName->value,
                $parentId->value
            ));
        }
        return $childAggregate->nodeAggregateId;
    }
}
