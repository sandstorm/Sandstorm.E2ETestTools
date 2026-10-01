<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Tests\Behavior\Bootstrap;

use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Gherkin\Node\PyStringNode;
use Behat\Gherkin\Node\TableNode;
use Behat\Step\Given;
use Behat\Step\When;
use Behat\Testwork\Hook\Scope\AfterSuiteScope;
use Behat\Testwork\Hook\Scope\BeforeSuiteScope;
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
use Neos\Utility\Files;
use PHPUnit\Framework\Assert;
use Sandstorm\E2ETestTools\Fixture\NodeFixture;
use Sandstorm\E2ETestTools\Fixture\NodeFixtureGherkin;
use Sandstorm\E2ETestTools\Fixture\NodeFixtureImporter;
use Sandstorm\E2ETestTools\FusionRenderingResult;
use Sandstorm\E2ETestTools\FusionServiceForTesting;
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

    /**
     * Fixtures, rendering and the CR reset all use this content repository.
     */
    private const CONTENT_REPOSITORY_ID = 'default';

    private ContentRepository $contentRepository;

    private NodeFixtureImporter $nodeFixtureImporter;

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
     * Starts the scenario with an empty content repository: only the live workspace and the Neos.Neos:Sites root.
     * Call it before creating nodes, e.g. from a "@BeforeScenario @flowEntities" hook.
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
    public function iGetTheNode(string $nodeAggregateId, string $dimensionSpacePoint = '[]'): void
    {
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

    /**
     * @When I render the Fusion object :fusionPath with the current context node:
     */
    public function iRenderTheFusionObjectWithNode($fusionPath, PyStringNode $additionalFusion)
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
    public function iRenderThePage()
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
        $fusionRenderingResult->setAndReturnRenderedPage($result);

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

            // same base URL Playwright uses to screenshot the styleguide pages (see PlaywrightTrait)
            $baseUrl = getenv('SYSTEM_UNDER_TEST_URL_FOR_PLAYWRIGHT') ?: '';
            echo 'The STYLEGUIDE can be found at ' . rtrim($baseUrl, '/') . '/styleguide/';
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
        foreach (NodeFixtureGherkin::referencesFromTable($table) as $reference) {
            $this->nodeFixtureImporter->setReferences($reference);
        }
    }

    /**
     * @param string $siteName node name of the site node (the node with empty parent)
     */
    protected function importNodeFixture(NodeFixture $fixture, string $siteName): void
    {
        $this->nodeFixtureImporter->import($fixture, $siteName);
    }
}
