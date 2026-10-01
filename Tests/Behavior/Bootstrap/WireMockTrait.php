<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Tests\Behavior\Bootstrap;

use Behat\Hook\BeforeScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use PHPUnit\Framework\Assert;
use Sandstorm\E2ETestTools\WireMock\WireMockAdmin;

/**
 * Mocked third-party APIs with WireMock (https://wiremock.org): the system under test talks to the WireMock server
 * instead of the real API (configure its base URL in the E2E contexts), scenarios decide what it answers.
 *
 * Setup in the FeatureContext constructor:
 *
 *   $this->setupWireMock(getenv('WIREMOCK_ADMIN_URL') ?: 'http://wiremock:8080', [
 *       'shop' => ['fixtures' => __DIR__ . '/../WireMock/shop', 'pathPrefix' => '/shop-api'],
 *   ]);
 *
 * Fixture directory per API: `_base/*.json` - WireMock mapping files loaded before every scenario; response bodies
 * (`order_success.json`) for "serves response"; one directory per scenario ("tape") with mapping files for
 * "I load the stubs".
 *
 * Tag features that use the mock with the tag "wireMock" - the server is reset before each of their scenarios.
 */
trait WireMockTrait
{
    private ?WireMockAdmin $wireMock_admin = null;

    /**
     * @var array<string, array{fixtures: string, pathPrefix?: string, proxyBaseUrl?: string}>
     */
    private array $wireMock_apis = [];

    /**
     * @param array<string, array{fixtures: string, pathPrefix?: string, proxyBaseUrl?: string}> $apis name => config;
     *     proxyBaseUrl forwards unmatched requests to the real API (only while writing a test, never in CI)
     */
    public function setupWireMock(string $adminUrl, array $apis): void
    {
        $this->wireMock_admin = new WireMockAdmin($adminUrl);
        $this->wireMock_apis = $apis;
    }

    #[BeforeScenario('@wireMock')]
    public function wireMockBeforeScenario(): void
    {
        $this->wireMock_resetToBaseStubs();
    }

    /**
     * @param string $file response body, relative to the API's fixture directory (".json" may be omitted)
     */
    #[Given('the API :api path :path on :method serves response :file')]
    public function theApiPathServesResponse(string $api, string $path, string $method, string $file): void
    {
        $this->theApiPathServesResponseWithStatus($api, $path, $method, $file, 200);
    }

    #[Given('the API :api path :path on :method serves response :file with status :status')]
    public function theApiPathServesResponseWithStatus(string $api, string $path, string $method, string $file, int $status): void
    {
        $bodyFile = $this->wireMock_fixturePath($api, str_ends_with($file, '.json') ? $file : $file . '.json');
        if (!is_file($bodyFile)) {
            throw new \RuntimeException(sprintf('Response file not found: %s', $bodyFile));
        }
        $this->wireMock_requireAdmin()->stubResponse($method, $this->wireMock_url($api, $path), $status, (string)file_get_contents($bodyFile));
    }

    /**
     * Loads all mapping files of `<fixtures>/<tape>/` - one directory per scenario keeps scenarios independent.
     */
    #[Given('I load the stubs :tape of the API :api')]
    public function iLoadTheStubsOfTheApi(string $tape, string $api): void
    {
        $files = glob($this->wireMock_fixturePath($api, $tape) . '/*.json') ?: [];
        if ($files === []) {
            throw new \RuntimeException(sprintf('No mapping files in %s', $this->wireMock_fixturePath($api, $tape)));
        }
        array_map($this->wireMock_requireAdmin()->importMappingFile(...), $files);
    }

    /**
     * When the API's answer changes after an action: back to the base stubs, then stub the new answers.
     */
    #[Given('I clear all API stubs')]
    public function iClearAllApiStubs(): void
    {
        $this->wireMock_resetToBaseStubs();
    }

    #[Then('the API :api should have received :method :path')]
    public function theApiShouldHaveReceived(string $api, string $method, string $path): void
    {
        $count = $this->wireMock_requireAdmin()->countRequests($method, $this->wireMock_url($api, $path));
        Assert::assertGreaterThan(0, $count, sprintf('The API "%s" received no %s %s.', $api, strtoupper($method), $path));
    }

    #[Then('the API :api should have received :method :path :count times')]
    public function theApiShouldHaveReceivedTimes(string $api, string $method, string $path, int $count): void
    {
        $actual = $this->wireMock_requireAdmin()->countRequests($method, $this->wireMock_url($api, $path));
        Assert::assertSame($count, $actual, sprintf('Unexpected number of %s %s requests to the API "%s".', strtoupper($method), $path, $api));
    }

    private function wireMock_resetToBaseStubs(): void
    {
        $admin = $this->wireMock_requireAdmin();
        $admin->reset();
        foreach ($this->wireMock_apis as $name => $config) {
            array_map($admin->importMappingFile(...), glob($this->wireMock_fixturePath($name, '_base') . '/*.json') ?: []);
            if (($config['proxyBaseUrl'] ?? '') !== '') {
                $admin->proxyUnmatched($config['pathPrefix'] ?? '/', $config['proxyBaseUrl']);
            }
        }
    }

    private function wireMock_url(string $api, string $path): string
    {
        $prefix = rtrim($this->wireMock_api($api)['pathPrefix'] ?? '', '/');
        return $prefix . '/' . ltrim($path, '/');
    }

    private function wireMock_fixturePath(string $api, string $relativePath): string
    {
        return rtrim($this->wireMock_api($api)['fixtures'], '/') . '/' . $relativePath;
    }

    /**
     * @return array{fixtures: string, pathPrefix?: string, proxyBaseUrl?: string}
     */
    private function wireMock_api(string $api): array
    {
        return $this->wireMock_apis[$api]
            ?? throw new \RuntimeException(sprintf('Unknown API "%s" - configured: %s.', $api, implode(', ', array_keys($this->wireMock_apis))));
    }

    private function wireMock_requireAdmin(): WireMockAdmin
    {
        return $this->wireMock_admin ?? throw new \RuntimeException('WireMock is not set up - call setupWireMock() in the FeatureContext constructor.');
    }
}
