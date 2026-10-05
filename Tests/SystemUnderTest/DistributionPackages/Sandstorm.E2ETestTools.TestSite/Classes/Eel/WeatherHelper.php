<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\TestSite\Eel;

use Neos\Eel\ProtectedContextAwareInterface;

/**
 * A third-party API call made by the system under test (not the browser) - the E2E suite answers it with WireMock.
 * Base URL from WEATHER_API_URL, in the development distribution the wiremock service.
 */
final class WeatherHelper implements ProtectedContextAwareInterface
{
    public function forecast(): string
    {
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 5]]);
        $body = @file_get_contents(rtrim((string)getenv('WEATHER_API_URL'), '/') . '/forecast', false, $context);
        $status = preg_match('#^HTTP/\S+ (\d{3})#', (http_get_last_response_headers() ?? [])[0] ?? '', $matches) === 1 ? (int)$matches[1] : 0;
        if ($body === false || $status !== 200) {
            return 'Weather unavailable';
        }
        $data = json_decode($body, true);
        return is_array($data) && is_string($data['forecast'] ?? null) ? $data['forecast'] : 'Weather unavailable';
    }

    public function allowsCallOfMethod($methodName): bool
    {
        return true;
    }
}
