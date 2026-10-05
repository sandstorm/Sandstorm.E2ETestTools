<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Debugging;

use Neos\Flow\Annotations as Flow;

/**
 * The Flow log directory, cleared before each scenario - so after a failure it holds exactly what the scenario
 * logged, and the logs don't grow with every run.
 *
 * @Flow\Proxy(false)
 */
final class LogDirectory
{
    public function __construct(
        private readonly string $path,
    ) {
    }

    /**
     * Empties the log files (a running process keeps writing into the same file) and removes the other files, like
     * the exception files in Exceptions/.
     */
    public function clear(): void
    {
        foreach ($this->files() as $relativePath) {
            $file = $this->path . '/' . $relativePath;
            // a file of another user that can't be cleared mustn't stop the test run
            str_ends_with($relativePath, '.log') ? @file_put_contents($file, '') : @unlink($file);
        }
    }

    /**
     * @return list<string> the copied files, relative to the log directory - empty when nothing was logged
     */
    public function copyTo(string $targetDirectory): array
    {
        $copied = [];
        foreach ($this->files() as $relativePath) {
            $source = $this->path . '/' . $relativePath;
            if (filesize($source) === 0) {
                continue;
            }
            $target = $targetDirectory . '/' . $relativePath;
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0777, true);
            }
            copy($source, $target);
            $copied[] = $relativePath;
        }
        return $copied;
    }

    /**
     * @return list<string> relative paths, sorted
     */
    private function files(): array
    {
        if (!is_dir($this->path)) {
            return [];
        }
        clearstatcache();
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->path, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile()) {
                $files[] = substr($file->getPathname(), strlen($this->path) + 1);
            }
        }
        sort($files);
        return $files;
    }
}
