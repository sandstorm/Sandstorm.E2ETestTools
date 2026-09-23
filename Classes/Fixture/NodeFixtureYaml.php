<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Fixture;

use Neos\Flow\Annotations as Flow;
use Symfony\Component\Yaml\Yaml;

/**
 * Writes the YAML node fixture format read by "I have the following nodes from file ..."
 * (see {@see \Sandstorm\E2ETestTools\Service\NodeImportService}).
 *
 * @Flow\Proxy(false)
 */
final class NodeFixtureYaml
{
    /**
     * @param list<NodeFixtureRow> $nodes
     * @param list<ReferenceFixtureRow> $references
     */
    public static function dump(array $nodes, array $references = []): string
    {
        $yaml = ['nodes' => array_map(fn (NodeFixtureRow $row) => $row->toYamlArray(), $nodes)];
        if ($references !== []) {
            $yaml['references'] = array_map(fn (ReferenceFixtureRow $row) => $row->toYamlArray(), $references);
        }
        return Yaml::dump($yaml, 99, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
    }
}
