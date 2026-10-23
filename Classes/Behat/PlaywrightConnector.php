<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Behat;

use Closure;
use GuzzleHttp\Psr7\Message;
use Neos\Utility\Files;
use Sandstorm\E2ETestTools\Playwright\JsValue;

/**
 * This is the connector between the {@see PlaywrightTrait} and the Playwright server (located in playwright-bridge/index.js).
 *
 * For full documentation, {@see PlaywrightTrait}.
 */
class PlaywrightConnector
{

    /**
     * Seconds a request to the bridge may take - {@see execute()} with 0 waits without limit.
     */
    public const DEFAULT_TIMEOUT_SECONDS = 30;

    private string $playwrightApiUrl;
    private string $systemUnderTestUrl;
    private string $resultsDir;
    private ?Closure $systemUnderTestUrlModifier = null;

    /**
     * Bridge requests and the time spent in them since the last {@see takeRequestStatistics()} - for {@see StepTimingTrait}.
     */
    private static int $requestCount = 0;

    private static float $requestMs = 0.0;

    /**
     * @param string $playwrightApiUrl Playwright API URL, as seen from the perspective of the Behat test runner (inside the Docker container)
     * @param string $systemUnderTestUrl System under Test URL, as seen from Playwright
     * @param string $resultsDir Where trace zips get written (relative to CWD)
     */
    public function __construct(string $playwrightApiUrl, string $systemUnderTestUrl, string $resultsDir = 'e2e-results')
    {
        $this->playwrightApiUrl = $playwrightApiUrl;
        $this->systemUnderTestUrl = $systemUnderTestUrl;
        $this->resultsDir = $resultsDir;
    }

    /**
     * @param ?Closure $urlModifier
     */
    public function setSystemUnderTestUrlModifier(?Closure $urlModifier): void
    {
        $this->systemUnderTestUrlModifier = $urlModifier;
    }

    /**
     * The bridge requests since the last call, and resets the count.
     *
     * @return array{calls: int, ms: float}
     */
    public static function takeRequestStatistics(): array
    {
        $statistics = ['calls' => self::$requestCount, 'ms' => self::$requestMs];
        self::$requestCount = 0;
        self::$requestMs = 0.0;
        return $statistics;
    }

    public function stopContext(string $contextName)
    {
        $response = $this->sendRequest('POST', $this->playwrightApiUrl . '/stop/' . $contextName);
        $statusCode = $response->getStatusCode();
        if ($statusCode !== 404 && $statusCode !== 200) {
            // an error occurred

            throw new \RuntimeException(
                'Unable to stop playwright. Status code was: ' . $statusCode . ' - body contents: ' . $response->getBody(
                )->getContents()
            );
        }
    }

    /**
     * Main API method to execute a playwright script
     *
     * @param string $contextName
     * @param string $playwrightJsCode
     * @param int $timeoutSeconds 0 waits without limit, e.g. for page.pause()
     * @return mixed
     */
    public function execute(string $contextName, string $playwrightJsCode, int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS)
    {
        $successResponse = $this->executeInternal($contextName, $playwrightJsCode, $timeoutSeconds);
        return $successResponse['returnValue'] ?? null;
    }

    /**
     * Whether the bridge runs its browser headless - null for an older copy of the bridge without GET /status.
     */
    public function isBridgeHeadless(): ?bool
    {
        $response = $this->sendRequest('GET', $this->playwrightApiUrl . '/status');
        if ($response->getStatusCode() !== 200) {
            return null;
        }
        $status = json_decode($response->getBody()->getContents(), true);
        return is_array($status) && is_bool($status['headless'] ?? null) ? $status['headless'] : null;
    }

    public function getCurrentJsCode(string $contextName)
    {
        $successResponse = $this->executeInternal($contextName, '');
        return $successResponse['js'] ?? '';
    }

    public function setStepForDebugging(string $contextName, string $stepText)
    {
        $this->executeInternal($contextName, '// ' . $stepText);
    }

    /**
     * Groups the actions of a Behat step in the trace - the Trace Viewer shows them under the step text.
     */
    public function startStepGroup(string $contextName, string $stepText): void
    {
        $this->execute($contextName, sprintf("// %s\nawait context.tracing.group(%s);", $stepText, JsValue::of($stepText)));
    }

    public function endStepGroup(string $contextName): void
    {
        $this->execute($contextName, 'await context.tracing.groupEnd();');
    }

    public function startTracing(
        string $contextName,
        string $featureFile,
        string $scenarioName,
        int $featureFileLine
    ) {
        $this->execute(
            $contextName,
            sprintf(
            // language=JavaScript
                '
            // Finish tracing after scenario
            //   - Feature: %s (line: %d)
            //   - Scenario: %s
            await context.tracing.start({ screenshots: true, snapshots: true });
            '// language=PHP
                ,
                $featureFile,
                $featureFileLine,
                $scenarioName
            )
        );
    }

    public function finishTracing(
        string $contextName,
        string $featureFile,
        string $scenarioName,
        int $featureFileLine,
        bool $keepTrace
    ) {
        $traceReportZipFileName = 'report_' . preg_replace(
                '/[^a-zA-Z0-9_]/',
                '',
                basename($featureFile) . '_' . $scenarioName
            ) . '.zip';
        $header = sprintf(
            "// Finish tracing after scenario\n//   - Feature: %s (line: %d)\n//   - Scenario: %s\n",
            $featureFile,
            $featureFileLine,
            $scenarioName
        );
        if (!$keepTrace) {
            $this->execute($contextName, $header . 'await context.tracing.stop();');
            return;
        }
        $traceReportZipBase64 = $this->execute(
            $contextName,
            $header . sprintf(
            // language=JavaScript
                '
            // Playwright can only save a trace to a file (unlike screenshots, which can be returned as a
            // buffer). So write a temporary file in the bridge, hand its content back to PHP (which writes
            // it to $resultsDir), and delete it again - otherwise a stray second copy stays in playwright-bridge/.
            // Not named "fs": some bridge copies hand the script an "fs" parameter, and redeclaring it is a SyntaxError.
            const traceFile = %s;
            await context.tracing.stop({ path: traceFile });
            const nodeFs = require("fs");
            const traceBase64 = nodeFs.readFileSync(traceFile, "base64");
            nodeFs.unlinkSync(traceFile);
            return traceBase64;
            '// language=PHP
                ,
                JsValue::of($traceReportZipFileName)
            )
        );
        if (is_string($traceReportZipBase64) && $traceReportZipBase64 !== '') {
            $traceReportZip = base64_decode($traceReportZipBase64, true);
            Files::createDirectoryRecursively($this->resultsDir);
            file_put_contents(sprintf('%s/%s', $this->resultsDir, $traceReportZipFileName), $traceReportZip);
            echo sprintf(
                "You can find the report trace file %s in %s\n",
                $traceReportZipFileName,
                $this->resultsDir
            );
        }
    }

    private function executeInternal(string $contextName, string $playwrightJsCode, int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS)
    {
        $systemUnderTestUrl = $this->systemUnderTestUrl;
        $modifier = $this->systemUnderTestUrlModifier;
        if ($modifier !== null) {
            $systemUnderTestUrl = $modifier($systemUnderTestUrl);
        }

        $playwrightJsCode = str_replace('BASEURL', $systemUnderTestUrl, $playwrightJsCode);
        $response = $this->sendRequest('POST', $this->playwrightApiUrl . '/exec/' . $contextName, $playwrightJsCode, $timeoutSeconds);
        $statusCode = $response->getStatusCode();
        if ($statusCode === 500) {
            $bodyContents = $response->getBody()->getContents();
            $errorResponse = json_decode($bodyContents, true);
            // a script error comes with "error" and "js"; anything else (e.g. the bridge's browser crashed) is a
            // generic error of the HTTP server - show its body instead of failing on the missing keys
            if (!is_array($errorResponse) || !array_key_exists('js', $errorResponse)) {
                throw new \RuntimeException(
                    'Error executing playwright (is the playwright-bridge healthy? restart it if every script fails). Status code was: ' . $statusCode . ' - body contents: ' . $bodyContents
                );
            }

            throw new \RuntimeException(
                sprintf(
                    "Error executing playwright script - error was: %s. \n\n Full script: \n %s",
                    $errorResponse['error'] ?? '(none)',
                    $errorResponse['js']
                )
            );
        } elseif ($statusCode === 200) {
            $bodyContents = $response->getBody()->getContents();
            $successResponse = json_decode($bodyContents, true);
            if (!is_array($successResponse)) {
                throw new \RuntimeException(
                    'Could not deserialize Playwright response, despite 200 status code. - body contents: ' . $bodyContents
                );
            }
            return $successResponse;
        } else {
            $bodyContents = $response->getBody()->getContents();
            throw new \RuntimeException(
                'Error executing playwright. Status code was: ' . $statusCode . ' - body contents: ' . $bodyContents
            );
        }
    }

    private function sendRequest(string $method, string $requestUri, string $content = '', int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS)
    {
        if (!extension_loaded('curl')) {
            throw new \RuntimeException(
                'CurlEngine requires the PHP CURL extension to be installed and loaded.',
                1346319808
            );
        }

        $curlHandle = curl_init($requestUri);

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_FRESH_CONNECT => true,
            CURLOPT_FORBID_REUSE => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
        ];
        curl_setopt_array($curlHandle, $options);

        // Send an empty Expect header in order to avoid chunked data transfer (which we can't handle yet).
        // If we don't set this, cURL will set "Expect: 100-continue" for requests larger than 1024 bytes.
        curl_setopt($curlHandle, CURLOPT_HTTPHEADER, ['Expect:']);

        if ($method === 'POST') {
            curl_setopt($curlHandle, CURLOPT_POST, true);
            curl_setopt($curlHandle, CURLOPT_POSTFIELDS, $content);
        } elseif ($method !== 'GET') {
            throw new \RuntimeException('Not supported');
        }

        $requestStart = hrtime(true);
        $curlResult = curl_exec($curlHandle);
        self::$requestCount++;
        self::$requestMs += (hrtime(true) - $requestStart) / 1e6;
        if ($curlResult === false) {
            $errorCode = curl_errno($curlHandle);
            $error = curl_error($curlHandle);
            curl_close($curlHandle);
            if ($errorCode === CURLE_OPERATION_TIMEDOUT && $timeoutSeconds > 0) {
                throw new \RuntimeException(
                    sprintf(
                        'The playwright-bridge didn\'t answer within %d seconds - a wait in the script took longer: a timeout of %d seconds or more (Playwright\'s default is 30 s - a bridge copy older than 10.2 keeps it for contexts your own steps open), or several waits that add up. Give the wait a shorter timeout, e.g. {timeout: 10000}, to get Playwright\'s message and call log. Last requested URL was "%s" (%s).',
                        $timeoutSeconds,
                        $timeoutSeconds,
                        $requestUri,
                        $method
                    ), 1761200001
                );
            }
            throw new \RuntimeException(
                sprintf(
                    'cURL reported error code %s with message "%s". Last requested URL was "%s" (%s).',
                    $errorCode,
                    $error,
                    $requestUri,
                    $method
                ), 1338906040
            );
        }

        curl_close($curlHandle);

        $response = Message::parseResponse($curlResult);

        try {
            $responseBody = $response->getBody()->getContents();
            while (strpos($responseBody, 'HTTP/') === 0 || $response->getStatusCode() === 100) {
                $response = Message::parseResponse($responseBody);
                $responseBody = $response->getBody()->getContents();
            }
        } catch (\InvalidArgumentException) {
            // the body is no further HTTP message - $response is the final one
        } finally {
            $response->getBody()->rewind();
        }

        return $response;
    }
}
