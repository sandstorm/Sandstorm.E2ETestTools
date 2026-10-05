<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Behat;

use Neos\Flow\Annotations as Flow;
use PHPUnit\TextUI\CliArguments\Builder;
use PHPUnit\TextUI\Configuration\Registry;
use PHPUnit\TextUI\XmlConfiguration\DefaultConfiguration;

/**
 * The steps assert with PHPUnit's Assert outside a PHPUnit run. Since PHPUnit 10 most failure messages read PHPUnit's
 * configuration - without one, a failing assertNull(), assertTrue(), assertStringContainsString() ... ends in
 * "assert(self::$instance instanceof Configuration)" instead of its message. This sets up PHPUnit's default
 * configuration once, unless PHPUnit has one already.
 *
 * @Flow\Proxy(false)
 */
final class PhpUnitAssertions
{
    public static function enableFailureMessages(): void
    {
        if (!class_exists(Registry::class) || !class_exists(Builder::class) || !class_exists(DefaultConfiguration::class)) {
            return;
        }
        try {
            Registry::get();
        } catch (\Throwable) {
            Registry::init((new Builder())->fromParameters([]), DefaultConfiguration::create());
        }
    }
}
