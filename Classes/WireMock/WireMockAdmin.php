<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\WireMock;

use Neos\Flow\Annotations as Flow;

/**
 * Talks to the admin API of a WireMock server (https://wiremock.org/docs/standalone/admin-api-reference/) - no client
 * library needed, it's plain JSON over HTTP.
 *
 * @Flow\Proxy(false)
 */
final class WireMockAdmin
{
    /**
     * Stubs added by steps win over base stubs (WireMock: lower number = higher priority, default 5).
     */
    public const STEP_PRIORITY = 1;

    /**
     * Proxy stubs only answer what nothing else matched.
     */
    public const PROXY_PRIORITY = 100;

    /**
     * @var \Closure(string $method, string $url, array<mixed>|null $body): array<mixed>
     */
    private \Closure $transport;

    /**
     * @param \Closure(string $method, string $url, array<mixed>|null $body): array<mixed>|null $transport for tests;
     *     default: curl
     */
    public function __construct(
        private readonly string $adminUrl,
        ?\Closure $transport = null,
    ) {
        $this->transport = $transport ?? self::curlTransport(...);
    }

    /**
     * Removes all stubs and the request journal.
     */
    public function reset(): void
    {
        $this->request('POST', '/__admin/reset');
    }

    /**
     * @param array<mixed> $mapping WireMock stub mapping (request + response)
     */
    public function addMapping(array $mapping): void
    {
        $this->request('POST', '/__admin/mappings', $mapping);
    }

    /**
     * A file holds one mapping, or several as `{"mappings": [...]}` - WireMock's own export format.
     */
    public function importMappingFile(string $path): void
    {
        try {
            $content = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(sprintf('WireMock mapping file %s is not valid JSON: %s', $path, $e->getMessage()), 1727900003, $e);
        }
        if (!is_array($content)) {
            throw new \RuntimeException(sprintf('WireMock mapping file %s must contain a JSON object.', $path), 1727900001);
        }
        $this->request('POST', '/__admin/mappings/import', ['mappings' => $content['mappings'] ?? [$content]]);
    }

    /**
     * Stub for one call - a path with query string must match exactly, without one the query is ignored.
     */
    public function stubResponse(string $method, string $path, int $status, string $body): void
    {
        $this->addMapping([
            'priority' => self::STEP_PRIORITY,
            'request' => ['method' => strtoupper($method), ...(str_contains($path, '?') ? ['url' => $path] : ['urlPath' => $path])],
            'response' => ['status' => $status, 'body' => $body, 'headers' => ['Content-Type' => 'application/json']],
        ]);
    }

    /**
     * Forwards every request below $pathPrefix that no other stub matches to the real API - to record calls while
     * writing a test.
     */
    public function proxyUnmatched(string $pathPrefix, string $baseUrl): void
    {
        $this->addMapping([
            'priority' => self::PROXY_PRIORITY,
            // WireMock matches with Java regular expressions - \Q...\E quotes the prefix literally
            'request' => ['urlPattern' => '\\Q' . $pathPrefix . '\\E.*'],
            'response' => ['proxyBaseUrl' => $baseUrl],
        ]);
    }

    public function countRequests(string $method, string $path): int
    {
        $result = $this->request('POST', '/__admin/requests/count', [
            'method' => strtoupper($method),
            ...(str_contains($path, '?') ? ['url' => $path] : ['urlPath' => $path]),
        ]);
        return (int)($result['count'] ?? 0);
    }

    /**
     * @param array<mixed>|null $body
     * @return array<mixed>
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        return ($this->transport)($method, rtrim($this->adminUrl, '/') . $path, $body);
    }

    /**
     * @param array<mixed>|null $body
     * @return array<mixed>
     */
    private static function curlTransport(string $method, string $url, ?array $body): array
    {
        if ($method === '' || $url === '') {
            throw new \InvalidArgumentException('WireMock admin request needs a method and a URL.', 1727900004);
        }
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_POSTFIELDS => $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR),
            CURLOPT_TIMEOUT => 10,
        ]);
        $response = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if (!is_string($response) || $status >= 300) {
            throw new \RuntimeException(sprintf('WireMock admin request %s %s failed (status %d): %s', $method, $url, $status, is_string($response) && $response !== '' ? $response : $error), 1727900002);
        }
        $decoded = $response === '' ? [] : json_decode($response, true);
        return is_array($decoded) ? $decoded : [];
    }
}
