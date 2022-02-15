<?php

namespace Sandstorm\E2ETestTools\Tests\Behavior\Bootstrap;

use GuzzleHttp\Psr7\Message;
use Neos\Utility\Files;

/**
 * This is the connector between the {@see PlaywrightTrait} and the Playwright server (located in e2e-testrunner/index.js).
 *
 * For full documentation, {@see PlaywrightTrait}.
 */
class PlaywrightConnector
{

    private string $playwrightApiUrl;
    private string $systemUnderTestUrl;

    /**
     * @param string $playwrightApiUrl Playwright API URL, as seen from the perspective of the Behat test runner (inside the Docker container)
     * @param string $systemUnderTestUrl System under Test URL, as seen from Playwright
     */
    public function __construct(string $playwrightApiUrl, string $systemUnderTestUrl)
    {
        $this->playwrightApiUrl = $playwrightApiUrl;
        $this->systemUnderTestUrl = $systemUnderTestUrl;
    }

    public function stopContext(string $contextName)
    {
        $response = $this->sendRequest('POST', $this->playwrightApiUrl . '/stop/' . $contextName);
        $statusCode = $response->getStatusCode();
        if ($statusCode !== 404 && $statusCode !== 200) {
            // an error occurred

            throw new \RuntimeException('Unable to stop playwright. Status code was: ' . $statusCode . ' - body contents: ' . $response->getBody()->getContents());
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
                const fs = require("fs");
                return await fs.readFileSync(`%s`, `base64`);
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
        $response = $this->sendRequest('POST', $this->playwrightApiUrl . '/exec/' . $contextName, $playwrightJsCode);
        $statusCode = $response->getStatusCode();
        if ($statusCode === 500) {
            $bodyContents = $response->getBody()->getContents();
            $errorResponse = json_decode($bodyContents, true);
            if ($errorResponse === false) {
                throw new \RuntimeException('Error executing playwright. Status code was: ' . $statusCode . ' - body contents: ' . $bodyContents);
            }

            throw new \RuntimeException(sprintf("Error executing playwright script - error was: %s. \n\n Full script: \n %s", $errorResponse['error'], $errorResponse['js']));
        } elseif ($statusCode === 200) {
            $bodyContents = $response->getBody()->getContents();
            $successResponse = json_decode($bodyContents, true);
            if ($successResponse === false) {
                throw new \RuntimeException('Could not deserialize Playwright response, despite 200 status code. - body contents: ' . $bodyContents);
            }
            return $successResponse;
        } else {
            $bodyContents = $response->getBody()->getContents();
            throw new \RuntimeException('Error executing playwright. Status code was: ' . $statusCode . ' - body contents: ' . $bodyContents);
        }
    }
    private function sendRequest(string $method, string $requestUri, string $content = '') {
        if (!extension_loaded('curl')) {
            throw new Http\Exception('CurlEngine requires the PHP CURL extension to be installed and loaded.', 1346319808);
        }

        $curlHandle = curl_init((string)$requestUri);

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_FRESH_CONNECT => true,
            CURLOPT_FORBID_REUSE => true,
            CURLOPT_TIMEOUT => 30,
        ];
        curl_setopt_array($curlHandle, $options);

        // Send an empty Expect header in order to avoid chunked data transfer (which we can't handle yet).
        // If we don't set this, cURL will set "Expect: 100-continue" for requests larger than 1024 bytes.
        curl_setopt($curlHandle, CURLOPT_HTTPHEADER, ['Expect:']);

        switch ($method) {
            case 'GET':
                if ($content) {
                    // workaround because else the request would implicitly fall into POST:
                    curl_setopt($curlHandle, CURLOPT_CUSTOMREQUEST, 'GET');
                    curl_setopt($curlHandle, CURLOPT_POSTFIELDS, $content);
                }
                break;
            case 'POST':
                curl_setopt($curlHandle, CURLOPT_POST, true);
                curl_setopt($curlHandle, CURLOPT_POSTFIELDS, $content);
                break;
            default:
                throw new \RuntimeException('Not supported');
        }

        $curlResult = curl_exec($curlHandle);
        if ($curlResult === false) {
            throw new \RuntimeException(sprintf('cURL reported error code %s with message "%s". Last requested URL was "%s" (%s).', curl_errno($curlHandle), curl_error($curlHandle), curl_getinfo($curlHandle, CURLINFO_EFFECTIVE_URL), $method), 1338906040);
        }

        curl_close($curlHandle);

        $response = \GuzzleHttp\Psr7\Message::parseResponse($curlResult);

        try {
            $responseBody = $response->getBody()->getContents();
            while (strpos($responseBody, 'HTTP/') === 0 || $response->getStatusCode() === 100) {
                $response = Message::parseResponse($responseBody);
                $responseBody = $response->getBody()->getContents();
            }
        } catch (\InvalidArgumentException $e) {
        } finally {
            $response->getBody()->rewind();
        }

        return $response;
    }
}
