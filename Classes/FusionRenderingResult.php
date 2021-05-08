<?php

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

    private $renderedPage;

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

    /**
     * @return mixed
     */
    public function getRenderedPage()
    {
        return $this->renderedPage;
    }

    /**
     * @param mixed $renderedPage
     */
    public function setAndReturnRenderedPage($renderedPage)
    {
        $this->renderedPage = $renderedPage;
        return $renderedPage;
    }


    public function allowsCallOfMethod($methodName)
    {
        return true;
    }
}
