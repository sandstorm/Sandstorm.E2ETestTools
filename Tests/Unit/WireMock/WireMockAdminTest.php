<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Tests\Unit\WireMock;

use Neos\Flow\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Test;
use Sandstorm\E2ETestTools\WireMock\WireMockAdmin;

/**
 * The admin API requests WireMockAdmin sends - checked against a recording transport instead of a real server.
 */
class WireMockAdminTest extends UnitTestCase
{
    /**
     * @var list<array{method: string, url: string, body: array<mixed>|null}>
     */
    private array $requests = [];

    /**
     * @var array<mixed> what the fake server answers
     */
    private array $response = [];

    /**
     * @var list<string>
     */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        array_map(unlink(...), array_filter($this->temporaryFiles, file_exists(...)));
        parent::tearDown();
    }

    #[Test]
    public function resetPostsToTheResetEndpoint(): void
    {
        $this->admin('http://wiremock:8080/')->reset();

        self::assertSame([['method' => 'POST', 'url' => 'http://wiremock:8080/__admin/reset', 'body' => null]], $this->requests);
    }

    #[Test]
    public function stubbedResponseWinsOverBaseStubsAndIgnoresTheQueryOfAPlainPath(): void
    {
        $this->admin()->stubResponse('get', '/shop-api/order/1', 200, '{"id":1}');

        self::assertSame('http://wiremock:8080/__admin/mappings', $this->requests[0]['url']);
        self::assertSame([
            'priority' => WireMockAdmin::STEP_PRIORITY,
            'request' => ['method' => 'GET', 'urlPath' => '/shop-api/order/1'],
            'response' => ['status' => 200, 'body' => '{"id":1}', 'headers' => ['Content-Type' => 'application/json']],
        ], $this->requests[0]['body']);
    }

    #[Test]
    public function pathWithQueryStringMustMatchExactly(): void
    {
        $this->admin()->stubResponse('GET', '/api/items?id=5', 404, '{}');

        self::assertSame(['method' => 'GET', 'url' => '/api/items?id=5'], $this->requests[0]['body']['request'] ?? null);
        self::assertSame(404, $this->requests[0]['body']['response']['status'] ?? null);
    }

    #[Test]
    public function singleMappingFileIsImportedAsListOfOne(): void
    {
        $mapping = ['request' => ['urlPath' => '/auth'], 'response' => ['status' => 200]];

        $this->admin()->importMappingFile($this->temporaryJsonFile($mapping));

        self::assertSame('http://wiremock:8080/__admin/mappings/import', $this->requests[0]['url']);
        self::assertSame(['mappings' => [$mapping]], $this->requests[0]['body']);
    }

    #[Test]
    public function exportedMappingsFileIsImportedAsIs(): void
    {
        $mappings = [['request' => ['urlPath' => '/a']], ['request' => ['urlPath' => '/b']]];

        $this->admin()->importMappingFile($this->temporaryJsonFile(['mappings' => $mappings]));

        self::assertSame(['mappings' => $mappings], $this->requests[0]['body']);
    }

    #[Test]
    public function invalidMappingFileFailsNamingTheFile(): void
    {
        $file = $this->temporaryFile('{"unclosed": ');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($file);

        $this->admin()->importMappingFile($file);
    }

    #[Test]
    public function proxyForwardsOnlyUnmatchedRequestsBelowThePrefix(): void
    {
        $this->admin()->proxyUnmatched('/shop-api', 'https://shop.example.com');

        self::assertSame([
            'priority' => WireMockAdmin::PROXY_PRIORITY,
            'request' => ['urlPattern' => '\\Q/shop-api\\E.*'],
            'response' => ['proxyBaseUrl' => 'https://shop.example.com'],
        ], $this->requests[0]['body']);
    }

    #[Test]
    public function requestCountIsReadFromTheJournal(): void
    {
        $this->response = ['count' => 3];

        $count = $this->admin()->countRequests('post', '/shop-api/order');

        self::assertSame(3, $count);
        self::assertSame('http://wiremock:8080/__admin/requests/count', $this->requests[0]['url']);
        self::assertSame(['method' => 'POST', 'urlPath' => '/shop-api/order'], $this->requests[0]['body']);
    }

    private function admin(string $adminUrl = 'http://wiremock:8080'): WireMockAdmin
    {
        return new WireMockAdmin($adminUrl, function (string $method, string $url, ?array $body): array {
            $this->requests[] = ['method' => $method, 'url' => $url, 'body' => $body];
            return $this->response;
        });
    }

    /**
     * @param array<mixed> $content
     */
    private function temporaryJsonFile(array $content): string
    {
        return $this->temporaryFile(json_encode($content, JSON_THROW_ON_ERROR));
    }

    private function temporaryFile(string $content): string
    {
        $file = tempnam(sys_get_temp_dir(), 'wiremock-');
        file_put_contents($file, $content);
        $this->temporaryFiles[] = $file;
        return $file;
    }
}
