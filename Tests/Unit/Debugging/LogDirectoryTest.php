<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Tests\Unit\Debugging;

use Neos\Flow\Tests\UnitTestCase;
use Neos\Utility\Files;
use PHPUnit\Framework\Attributes\Test;
use Sandstorm\E2ETestTools\Debugging\LogDirectory;

class LogDirectoryTest extends UnitTestCase
{
    private string $logs;

    private string $target;

    protected function setUp(): void
    {
        parent::setUp();
        $base = sys_get_temp_dir() . '/log-directory-' . uniqid();
        $this->logs = $base . '/Logs';
        $this->target = $base . '/results';
        mkdir($this->logs . '/Exceptions', 0777, true);
    }

    protected function tearDown(): void
    {
        Files::removeDirectoryRecursively(dirname($this->logs));
        parent::tearDown();
    }

    #[Test]
    public function clearingEmptiesLogFilesAndRemovesExceptionFiles(): void
    {
        file_put_contents($this->logs . '/System_Development.log', "old line\n");
        file_put_contents($this->logs . '/Exceptions/202610051200abc.txt', 'old stack trace');

        (new LogDirectory($this->logs))->clear();

        self::assertSame('', file_get_contents($this->logs . '/System_Development.log'));
        self::assertFileDoesNotExist($this->logs . '/Exceptions/202610051200abc.txt');
    }

    #[Test]
    public function onlyFilesWithContentAreCopied(): void
    {
        $logDirectory = new LogDirectory($this->logs);
        file_put_contents($this->logs . '/System_Development.log', "old line\n");
        $logDirectory->clear();
        file_put_contents($this->logs . '/System_Development.log', "new line\n", FILE_APPEND);
        file_put_contents($this->logs . '/Exceptions/202610051200abc.txt', 'stack trace');
        touch($this->logs . '/Security.log');

        $copied = $logDirectory->copyTo($this->target);

        self::assertSame(['Exceptions/202610051200abc.txt', 'System_Development.log'], $copied);
        self::assertSame("new line\n", file_get_contents($this->target . '/System_Development.log'));
        self::assertSame('stack trace', file_get_contents($this->target . '/Exceptions/202610051200abc.txt'));
    }

    #[Test]
    public function nothingIsWrittenWhenNothingWasLogged(): void
    {
        touch($this->logs . '/System_Development.log');

        self::assertSame([], (new LogDirectory($this->logs))->copyTo($this->target));
        self::assertDirectoryDoesNotExist($this->target);
    }

    #[Test]
    public function aMissingLogDirectoryIsNoError(): void
    {
        $logDirectory = new LogDirectory($this->logs . '/does-not-exist');

        $logDirectory->clear();
        self::assertSame([], $logDirectory->copyTo($this->target));
    }
}
