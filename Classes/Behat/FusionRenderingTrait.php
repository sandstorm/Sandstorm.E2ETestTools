<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Behat;

use Behat\Gherkin\Node\PyStringNode;
use Behat\Hook\BeforeScenario;
use Behat\Gherkin\Node\TableNode;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use GuzzleHttp\Psr7\ServerRequest;
use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\Factory\ContentRepositoryServiceFactoryDependencies;
use Neos\ContentRepository\Core\Factory\ContentRepositoryServiceFactoryInterface;
use Neos\ContentRepository\Core\Factory\ContentRepositoryServiceInterface;
use Neos\ContentRepository\Core\Feature\RootNodeCreation\Command\CreateRootNodeAggregateWithNode;
use Neos\ContentRepository\Core\Infrastructure\Property\PropertyConverter;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindClosestNodeFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\Service\ContentRepositoryMaintainerFactory;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Cache\CacheManager;
use Neos\Flow\Http\ServerRequestAttributes;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Mvc\Routing\Dto\RouteParameters;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Fusion\Core\FusionGlobals;
use Neos\Fusion\Core\RuntimeFactory;
use Neos\Neos\Domain\Model\RenderingMode;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Model\SiteNodeName;
use Neos\Neos\Domain\Model\WorkspaceDescription;
use Neos\Neos\Domain\Model\WorkspaceRoleAssignments;
use Neos\Neos\Domain\Model\WorkspaceTitle;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Repository\WorkspaceMetadataAndRoleRepository;
use Neos\Neos\Domain\Service\WorkspaceService;
use Neos\Neos\Domain\SubtreeTagging\NeosVisibilityConstraints;
use Neos\Neos\FrontendRouting\SiteDetection\SiteDetectionResult;
use PHPUnit\Framework\Assert;
use Sandstorm\E2ETestTools\Fixture\NodeFixture;
use Sandstorm\E2ETestTools\Fixture\NodeFixtureGherkin;
use Sandstorm\E2ETestTools\Fixture\NodeFixtureImporter;
use Sandstorm\E2ETestTools\FusionRenderingResult;
use Sandstorm\E2ETestTools\FusionServiceForTesting;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Content repository fixtures (sites, nodes, references, default dimension space point) and Fusion rendering with
 * assertions on the HTML.
 */
trait FusionRenderingTrait
{
    use PersistentResourceTrait;

    abstract public function getObjectManager(): ObjectManagerInterface;

    private string $sitePackageKey;

    /**
     * @var array<string,string> for rows without a DimensionSpacePoint - see theDefaultDimensionSpacePointIs()
     */
    private array $defaultDimensionSpacePoint = [];

    /**
     * Fixtures, rendering and the CR reset all use this content repository.
     */
    private const CONTENT_REPOSITORY_ID = 'default';

    private ContentRepository $contentRepository;

    private NodeFixtureImporter $nodeFixtureImporter;

    public function setupFusionRendering(string $sitePackageKey): void
    {
        PhpUnitAssertions::enableFailureMessages();
        $this->sitePackageKey = $sitePackageKey;
        $this->PersistentResourceTrait_setupServices($this->getObjectManager());
    }

    /**
     * Every scenario starts without a default dimension space point.
     */
    #[BeforeScenario]
    public function resetDefaultDimensionSpacePoint(): void
    {
        $this->defaultDimensionSpacePoint = [];
    }

    /**
     * Fixture rows without a DimensionSpacePoint (node and reference tables, YAML files) get this one from now on, and
     * so does "I get the node" without "in dimension"; rows with one keep theirs - write the dimension only on the rows
     * of another variant. Until the end of the scenario - put it into the Background, so every feature shows its
     * dimension.
     *
     * @param string $dimensionSpacePoint JSON object, e.g. '{"language":"de"}'
     */
    #[Given('the default dimension space point is :dimensionSpacePoint')]
    public function theDefaultDimensionSpacePointIs(string $dimensionSpacePoint): void
    {
        $this->defaultDimensionSpacePoint = NodeFixtureGherkin::dimensionSpacePoint($dimensionSpacePoint, 'the default dimension space point');
    }

    #[Given('I have a site for Site Node :siteNodeName')]
    public function iHaveASite(string $siteNodeName): void
    {
        $this->createAndPersistSite($siteNodeName);
    }

    #[Given('I have a site for Site Node :siteNodeName with name :siteName')]
    public function iHaveASiteWithName(string $siteNodeName, string $siteName): void
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
     * Starts the scenario with an empty content repository: only the live workspace and the Neos.Neos:Sites root.
     * Call it before creating nodes, e.g. from a BeforeScenario hook for the flowEntities tag (see
     * FeatureContext.php.default).
     */
    public function setupContentRepository(): void
    {
        /** @var ContentRepositoryRegistry $registry */
        $registry = $this->getObjectManager()->get(ContentRepositoryRegistry::class);
        $crId = ContentRepositoryId::fromString(self::CONTENT_REPOSITORY_ID);

        $maintainer = $registry->buildService($crId, new ContentRepositoryMaintainerFactory());

        // setUp() first: on a fresh database the event store and projection tables don't exist yet, prune() needs them
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

        // the PropertyConverter is internal to the CR - the only way to get the configured instance is through
        // the dependencies a ContentRepositoryServiceFactory receives
        $propertyConverter = $registry->buildService(
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

        $sitesNodeAggregateId = NodeAggregateId::fromString('sites');
        $this->contentRepository->handle(CreateRootNodeAggregateWithNode::create(
            WorkspaceName::forLive(),
            $sitesNodeAggregateId,
            NodeTypeName::fromString('Neos.Neos:Sites')
        ));
        $this->nodeFixtureImporter = new NodeFixtureImporter($this->contentRepository, $propertyConverter, $sitesNodeAggregateId);

        // routes and rendered content of the previous scenario's nodes must not leak into this one
        $cacheManager = $this->getObjectManager()->get(CacheManager::class);
        foreach (['Flow_Mvc_Routing_Route', 'Flow_Mvc_Routing_Resolve', 'Neos_Fusion_Content'] as $cacheIdentifier) {
            $cacheManager->getCache($cacheIdentifier)->flush();
        }
    }

    protected ?FusionRenderingResult $lastFusionRenderingResult = null;

    #[When('I render the Fusion object :fusionPath:')]
    public function iRenderTheFusionObject(string $fusionPath, PyStringNode $additionalFusion): void
    {
        $fusionRenderingResult = new FusionRenderingResult();
        $this->internalRender('e2eTestRoot', $additionalFusion->getRaw(), [
            // both used in Root.fusion
            'fusionRenderingResult' => $fusionRenderingResult,
            'renderPath' => $fusionPath
        ]);
        $this->lastFusionRenderingResult = $fusionRenderingResult;
    }

    /**
     * Node used as context by "... with the current context node" and "I render the page".
     */
    private ?Node $currentNode = null;

    /**
     * Picks a node (created before, e.g. via "I have the following nodes in site") from the live workspace as
     * context node for the rendering steps below.
     */
    #[Given("I get the node :nodeAggregateId")]
    #[Given("I get the node :nodeAggregateId in dimension :dimensionSpacePoint")]
    public function iGetTheNode(string $nodeAggregateId, ?string $dimensionSpacePoint = null): void
    {
        // without "in dimension": the default dimension space point
        $dimensionSpacePoint ??= json_encode((object)$this->defaultDimensionSpacePoint, JSON_THROW_ON_ERROR);
        $this->requireContentRepository();
        $node = $this->contentRepository
            ->getContentGraph(WorkspaceName::forLive())
            ->getSubgraph(
                DimensionSpacePoint::fromJsonString($dimensionSpacePoint),
                NeosVisibilityConstraints::excludeRemoved()
            )
            ->findNodeById(NodeAggregateId::fromString($nodeAggregateId));
        if ($node === null) {
            throw new \RuntimeException(sprintf('Node "%s" not found in live workspace, dimension %s', $nodeAggregateId, $dimensionSpacePoint));
        }
        $this->currentNode = $node;
    }

    #[When('I render the Fusion object :fusionPath with the current context node:')]
    public function iRenderTheFusionObjectWithNode(string $fusionPath, PyStringNode $additionalFusion): void
    {
        $fusionRenderingResult = new FusionRenderingResult();
        $this->internalRender('e2eTestRoot', $additionalFusion->getRaw(), [
            ...$this->currentNodeFusionContext(),
            // both used in Root.fusion
            'fusionRenderingResult' => $fusionRenderingResult,
            'renderPath' => $fusionPath
        ]);

        $this->lastFusionRenderingResult = $fusionRenderingResult;
    }

    /**
     * Renders the whole page (Fusion path "root") of the current context node, which must be a document.
     */
    #[When("I render the page")]
    public function iRenderThePage(): void
    {
        $fusionRenderingResult = new FusionRenderingResult();
        $additionalFusion = "
            prototype(Neos.Neos:Page) {
                @class = 'Neos\\\\Fusion\\\\FusionObjects\\\\JoinImplementation'
                httpResponseHead >
            }
        ";
        $result = $this->internalRender('root', $additionalFusion, $this->currentNodeFusionContext());

        $fusionRenderingResult->setAndReturnRenderedElement($result);

        $this->lastFusionRenderingResult = $fusionRenderingResult;
    }

    /**
     * node / documentNode / site context variables for the current context node, like Neos sets them for a request.
     */
    private function currentNodeFusionContext(): array
    {
        if ($this->currentNode === null) {
            throw new \RuntimeException('No context node selected - use "Given I get the node ..." first.');
        }
        $subgraph = $this->contentRepository->getContentSubgraph(
            $this->currentNode->workspaceName,
            $this->currentNode->dimensionSpacePoint
        );
        return [
            'node' => $this->currentNode,
            'documentNode' => $subgraph->findClosestNode($this->currentNode->aggregateId, FindClosestNodeFilter::create('Neos.Neos:Document')),
            'site' => $subgraph->findClosestNode($this->currentNode->aggregateId, FindClosestNodeFilter::create('Neos.Neos:Site')),
        ];
    }

    private function internalRender(string $fusionPath, string $additionalFusion, $fusionContext = [])
    {
        $fusionService = $this->getObjectManager()->get(FusionServiceForTesting::class);
        $fusionConfiguration = $fusionService->getMergedFusionObjectTreeForPackage($this->sitePackageKey, $additionalFusion, ContentRepositoryId::fromString(self::CONTENT_REPOSITORY_ID));

        // to generate links without /index.php/
        putenv('FLOW_REWRITEURLS=1');
        $httpRequest = new ServerRequest('GET', 'http://neos.test/');
        $routeParameters = RouteParameters::createEmpty()->withParameter('requestUriHost', 'neos.test');
        $siteNode = $fusionContext['site'] ?? null;
        if ($siteNode instanceof Node && $siteNode->name !== null) {
            // node URIs need the site + content repository, which Neos' SiteDetectionMiddleware normally stores in
            // the request; we render without a real request, so store it ourselves
            $siteDetectionResult = SiteDetectionResult::create(SiteNodeName::fromNodeName($siteNode->name), $siteNode->contentRepositoryId);
            $httpRequest = $siteDetectionResult->storeInRequest($httpRequest);
            $routeParameters = $siteDetectionResult->storeInRouteParameters($routeParameters);
        }
        $httpRequest = $httpRequest->withAttribute(ServerRequestAttributes::ROUTING_PARAMETERS, $routeParameters);
        $actionRequest = ActionRequest::fromHttpRequest($httpRequest);
        // needed to generate links
        $actionRequest->setFormat('html');
        // RuntimeFactory adds the default Eel helpers (String, Array, ...) as Fusion globals
        $runtime = $this->getObjectManager()->get(RuntimeFactory::class)->createFromConfiguration(
            $fusionConfiguration,
            // same globals Neos' FusionView sets for a frontend request
            FusionGlobals::fromArray(['request' => $actionRequest, 'renderingMode' => RenderingMode::createFrontend()])
        );

        $runtime->pushContextArray($fusionContext);
        // as a side effect of rendering, $fusionContext['fusionRenderingResult'] gets filled.
        $result = $runtime->evaluate($fusionPath);
        $runtime->popContext();

        return $result;
    }


    /**
     * Compares the whole HTML - prefer the CSS selector steps.
     */
    #[Then('the Fusion output should equal to :expected')]
    public function theFusionOutputShouldEqualTo(string $expected): void
    {
        Assert::assertEquals($expected, $this->lastRenderedHtml());
    }

    /**
     * Compares the inner HTML of the first match for equality (no regular expression).
     */
    #[Then('in the fusion output, the inner HTML of CSS selector :selector matches :expected')]
    public function inTheFusionOutputTheInnerHtmlOfCssSelectorMatches(string $selector, string $expected): void
    {
        $crawler = (new Crawler($this->lastRenderedHtml()))->filter($selector);
        Assert::assertGreaterThan(0, $crawler->count(), sprintf('No element matches the CSS selector "%s".', $selector));
        Assert::assertEquals($expected, $crawler->html());
    }

    /**
     * Columns: Key, Value - the attributes of the first match.
     */
    #[Then('in the fusion output, the attributes of CSS selector :selector are:')]
    public function inTheFusionOutputTheAttributesOfSelectorAre(string $selector, TableNode $attributes): void
    {
        $crawler = (new Crawler($this->lastRenderedHtml()))->filter($selector);
        Assert::assertGreaterThan(0, $crawler->count(), sprintf('No element matches the CSS selector "%s".', $selector));

        foreach ($attributes->getHash() as $row) {
            Assert::assertArrayHasKey('Key', $row, 'The attribute table needs the columns Key and Value.');
            Assert::assertArrayHasKey('Value', $row, 'The attribute table needs the columns Key and Value.');
            $key = $row['Key'];
            $actual = $crawler->attr($key);
            Assert::assertNotNull($actual, sprintf('The attribute "%s" is missing.', $key));
            Assert::assertEquals($row['Value'], trim($actual), 'The attribute values for ' . $key . ' do not match.');
        }
    }

    private function lastRenderedHtml(): string
    {
        if ($this->lastFusionRenderingResult === null) {
            throw new \RuntimeException('Nothing rendered yet - use "When I render the Fusion object ..." or "When I render the page" first.');
        }
        return (string)$this->lastFusionRenderingResult->getRenderedElement();
    }

    /**
     * The content repository is set up by setupContentRepository(), which the FeatureContext calls before every scenario tagged flowEntities.
     */
    private function requireContentRepository(): void
    {
        if (!isset($this->contentRepository, $this->nodeFixtureImporter)) {
            throw new \RuntimeException('The content repository isn\'t set up. Did you forget the @flowEntities tag on this scenario?');
        }
    }

    /**
     * Columns: NodeAggregateId, Parent, NodeType, Properties (JSON), DimensionSpacePoint (JSON), optional Hidden -
     * see {@see NodeFixtureGherkin::nodesFromTable()}. The /sites root node is created by setupContentRepository().
     */
    #[Given("I have the following nodes in site :siteName:")]
    #[Given("I create the following nodes in site :siteName:")]
    public function iHaveTheFollowingNodesInSite(string $siteName, TableNode $table): void
    {
        $this->importNodeFixture(new NodeFixture(NodeFixtureGherkin::nodesFromTable($table)), $siteName);
    }

    /**
     * Columns: NodeAggregateId, ReferenceName, Targets (comma-separated), DimensionSpacePoint, optional Properties -
     * see {@see NodeFixtureGherkin::referencesFromTable()}. Use it after the nodes are created.
     */
    #[Given("the following node references:")]
    public function theFollowingNodeReferences(TableNode $table): void
    {
        $this->requireContentRepository();
        foreach (NodeFixtureGherkin::referencesFromTable($table) as $reference) {
            $this->nodeFixtureImporter->setReferences($reference->dimensionSpacePoint === [] ? $reference->withDimensionSpacePoint($this->defaultDimensionSpacePoint) : $reference);
        }
    }

    /**
     * @param string $siteName node name of the site node (the node with empty parent)
     */
    protected function importNodeFixture(NodeFixture $fixture, string $siteName): void
    {
        $this->requireContentRepository();
        $this->nodeFixtureImporter->import($fixture->withDefaultDimensionSpacePoint($this->defaultDimensionSpacePoint), $siteName);
    }
}
