<?php

namespace Sandstorm\E2ETestTools\Tests\Behavior\Bootstrap;

use Neos\Utility\Files;
use PHPUnit\Framework\Assert;

/**
 * This is the connector between the {@see PlaywrightTrait} and the Playwright server (located in e2e-testrunner/index.js).
 *
 * For full documentation, {@see PlaywrightTrait}.
 */
class PlaywrightConnector
{
    /**
     * @var \Neos\Flow\Http\Client\CurlEngine
     */
    protected $curlEngine;

    private string $playwrightApiUrl;
    private string $systemUnderTestUrl;

    /**
     * @param string $playwrightApiUrl Playwright API URL, as seen from the perspective of the Behat test runner (inside the Docker container)
     * @param string $systemUnderTestUrl System under Test URL, as seen from Playwright
     */
    public function __construct(string $playwrightApiUrl, string $systemUnderTestUrl)
    {
        $this->curlEngine = new \Neos\Flow\Http\Client\CurlEngine();

        $this->playwrightApiUrl = $playwrightApiUrl;
        $this->systemUnderTestUrl = $systemUnderTestUrl;
    }

    public function stopContext(string $contextName)
    {
        $response = $this->curlEngine->sendRequest(new \GuzzleHttp\Psr7\ServerRequest('POST', $this->playwrightApiUrl . '/stop/' . $contextName));
        $statusCode = $response->getStatusCode();
        if ($statusCode !== 404 && $statusCode !== 200) {
            // an error occurred

            Assert::fail('Unable to stop playwright. Status code was: ' . $statusCode . ' - body contents: ' . $response->getBody()->getContents());
        }
    }

    /**
     * Main API method to execute a playwright script
     *
     * @param string $contextName
     * @param string $playwrightJsCode
     * @return mixed
     */
    public function execute(string $contextName, string $playwrightJsCode)
    {
        $successResponse = $this->executeInternal($contextName, $playwrightJsCode);
        return isset($successResponse['returnValue']) ? $successResponse['returnValue'] : null;
    }

    public function getCurrentJsCode(string $contextName)
    {
        $successResponse = $this->executeInternal($contextName, '');
        return $successResponse['js'];
    }

    public function setStepForDebugging(string $contextName, string $stepText)
    {
        $this->executeInternal($contextName, '// ' . $stepText);
    }

    public function startTracing(string $contextName, string $featureFile, string $scenarioName, string $featureFileLine) {
        $this->execute($contextName, sprintf(
        // language=JavaScript
            '
            // Finish tracing after scenario
            //   - Feature: %s (line: %d)
            //   - Scenario: %s
            await context.tracing.start({ screenshots: true, snapshots: true });
            '// language=PHP
            , $featureFile, $featureFileLine, $scenarioName));
    }

    public function finishTracing(string $contextName, string $featureFile, string $scenarioName, string $featureFileLine, bool $keepTrace)
    {
        $traceReportZipFileName = 'report_' . preg_replace('/[^a-zA-Z_]/', '', basename($featureFile) . '_' . $scenarioName) . '.zip';
        $traceReportZipBase64 = $this->execute($contextName, sprintf(
        // language=JavaScript
            '
            // Finish tracing after scenario
            //   - Feature: %s (line: %d)
            //   - Scenario: %s
            if ("%s" === "false") {
                await context.tracing.stop();
                return "";
            } else {
                await context.tracing.stop({ path: `%s` });
                return await fs.readFile(`%s`, {encoding: `base64`});
            }
            '// language=PHP
            , $featureFile, $featureFileLine, $scenarioName, $keepTrace ? 'true' : 'false', $traceReportZipFileName, $traceReportZipFileName));
        if (strlen($traceReportZipBase64)) {
            $traceReportZip = base64_decode($traceReportZipBase64);
            Files::createDirectoryRecursively('e2e-results');
            file_put_contents(sprintf('e2e-results/%s', $traceReportZipFileName), $traceReportZip);
            echo sprintf("You can find the report trace file %s BOTH in the current PHP execution directory (where you started the tests from),\n", $traceReportZipFileName);
            echo "and as well in the e2e-testrunner/ folder.";
        }
    }

    private function executeInternal(string $contextName, string $playwrightJsCode)
    {
        $playwrightJsCode = str_replace('BASEURL', $this->systemUnderTestUrl, $playwrightJsCode);
        $response = $this->curlEngine->sendRequest(new \GuzzleHttp\Psr7\ServerRequest('POST', $this->playwrightApiUrl . '/exec/' . $contextName, [], $playwrightJsCode));
        $statusCode = $response->getStatusCode();
        if ($statusCode === 500) {
            $bodyContents = $response->getBody()->getContents();
            $errorResponse = json_decode($bodyContents, true);
            if ($errorResponse === false) {
                Assert::fail('Error executing playwright. Status code was: ' . $statusCode . ' - body contents: ' . $bodyContents);
            }

            Assert::fail(sprintf("Error executing playwright script - error was: %s. \n\n Full script: \n %s", $errorResponse['error'], $errorResponse['js']));
        } elseif ($statusCode === 200) {
            $bodyContents = $response->getBody()->getContents();
            $successResponse = json_decode($bodyContents, true);
            if ($successResponse === false) {
                Assert::fail('Could not deserialize Playwright response, despite 200 status code. - body contents: ' . $bodyContents);
            }
            return $successResponse;
        } else {
            $bodyContents = $response->getBody()->getContents();
            Assert::fail('Error executing playwright. Status code was: ' . $statusCode . ' - body contents: ' . $bodyContents);
        }
    }
}
