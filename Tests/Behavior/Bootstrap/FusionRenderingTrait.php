<?php

namespace Sandstorm\E2ETestTools\Tests\Behavior\Bootstrap;

use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Gherkin\Node\PyStringNode;
use Behat\Gherkin\Node\TableNode;
use Behat\Testwork\Hook\Scope\AfterSuiteScope;
use Behat\Testwork\Hook\Scope\BeforeSuiteScope;
use GuzzleHttp\Psr7\ServerRequest;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
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
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Utility\Files;
use PHPUnit\Framework\Assert;
use Sandstorm\E2ETestTools\FusionServiceForTesting;
use Sandstorm\E2ETestTools\FusionRenderingResult;
use Symfony\Component\DomCrawler\Crawler;

/**
 * This trait is only useful in NEOS applications; not in Symfony projects.
 */
trait FusionRenderingTrait
{
    abstract public function getObjectManager(): ObjectManagerInterface;

    private string $sitePackageKey;

    public function setupFusionRendering(string $sitePackageKey)
    {
        if (!property_exists($this, 'securityInitialized') || $this->securityInitialized !== true) {
            throw new \RuntimeException('You need to run setupSecurity() from SecurityOperationsTrait before calling this method.');
        }

        $this->sitePackageKey = $sitePackageKey;
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
        $this->createAndPersistSite($siteNodeName, function ($site) use ($siteName) {
            $site->setName($siteName);
            return $site;
        });
    }

    /**
     * @param string $siteNodeName
     * @param $mapper
     * @throws \Neos\Flow\Persistence\Exception\IllegalObjectTypeException
     */
    protected function createAndPersistSite($siteNodeName, $mapper = null)
    {
        $site = new Site($siteNodeName);
        $site->setState(Site::STATE_ONLINE);
        $site->setSiteResourcesPackageKey($this->sitePackageKey);
        if ($mapper !== null) {
            $site = $mapper($site);
        }
        /** @var SiteRepository $siteRepository */
        $siteRepository = $this->objectManager->get(SiteRepository::class);
        $siteRepository->add($site);

        $this->persistAll();
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
        Assert::assertEquals(1, count($this->currentNodes));

        $fusionRenderingResult = new FusionRenderingResult();
        $this->internalRender('e2eTestRoot', $additionalFusion->getRaw(), [
            'node' => $this->currentNodes[0],
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
     * This is an EXTENDED version of the one in NodeTrait;
     * so we can support "HiddenInIndex".
     *
     * @Given /^I have the following nodes:$/
     * @When /^I create the following nodes:$/
     */
    public function iHaveTheFollowingNodes($table)
    {
        if ($this->isolated === true) {
            $this->callStepInSubProcess(__METHOD__, sprintf(' %s %s', escapeshellarg(\Neos\Flow\Tests\Functional\Command\TableNode::class), escapeshellarg(json_encode($table->getHash()))), true);
        } else {
            /** @var \Neos\ContentRepository\Domain\Service\NodeTypeManager $nodeTypeManager */
            $nodeTypeManager = $this->getObjectManager()->get(NodeTypeManager::class);
            $rows = $table->getHash();
            foreach ($rows as $row) {
                $path = $row['Path'];
                $name = implode('', array_slice(explode('/', $path), -1, 1));
                $parentPath = implode('/', array_slice(explode('/', $path), 0, -1)) ?: '/';

                $context = $this->getContextForProperties($row, true);

                if (isset($row['Node Type']) && $row['Node Type'] !== '') {
                    $nodeType = $nodeTypeManager->getNodeType($row['Node Type']);
                } else {
                    $nodeType = null;
                }

                if (isset($row['Identifier'])) {
                    $identifier = $row['Identifier'];
                } else {
                    $identifier = null;
                }

                if (isset($row['Hidden']) && $row['Hidden'] === 'true') {
                    $hidden = true;
                } else {
                    $hidden = false;
                }

                $parentNode = $context->getNode($parentPath);
                if ($parentNode === null) {
                    throw new \Exception(sprintf('Could not get parent node with path %s to create node %s', $parentPath, $path));
                }

                $node = $parentNode->createNode($name, $nodeType, $identifier);

                if (isset($row['Properties']) && $row['Properties'] !== '') {
                    $properties = json_decode($row['Properties'], true);
                    if ($properties === null) {
                        throw new \Exception(sprintf('Error decoding json value "%s": %d', $row['Properties'], json_last_error()));
                    }
                    foreach ($properties as $propertyName => $propertyValue) {
                        $node->setProperty($propertyName, $propertyValue);
                    }
                }

                $node->setHidden($hidden);

                if (isset($row['HiddenInIndex']) && $row['HiddenInIndex'] === 'true') {
                    $node->setHiddenInIndex(true);
                }
            }

            // Make sure we do not use cached instances
            $this->objectManager->get(PersistenceManagerInterface::class)->persistAll();
            $this->resetNodeInstances();
        }
    }
}
