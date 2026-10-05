<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools;

use Neos\Eel\ProtectedContextAwareInterface;
use Sandstorm\E2ETestTools\Tests\Behavior\Bootstrap\FusionRenderingTrait;

/**
 * Implementation detail of {@see FusionRenderingTrait} collecting the result
 * @internal
 */
class FusionRenderingResult implements ProtectedContextAwareInterface
{
    private $renderedElement;

    /**
     * @return mixed
     */
    public function getRenderedElement()
    {
        return $this->renderedElement;
    }

    /**
     * @param mixed $renderedElement
     */
    public function setAndReturnRenderedElement($renderedElement)
    {
        $this->renderedElement = $renderedElement;
        return $renderedElement;
    }

    public function allowsCallOfMethod($methodName)
    {
        return true;
    }
}
