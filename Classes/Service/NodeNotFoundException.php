<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Service;

/**
 * The node to export doesn't exist (anymore) in the given workspace and dimension.
 */
final class NodeNotFoundException extends \InvalidArgumentException
{
}
