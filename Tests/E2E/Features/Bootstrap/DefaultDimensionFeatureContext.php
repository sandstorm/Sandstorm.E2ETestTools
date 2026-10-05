<?php

declare(strict_types=1);

/**
 * The suite's FeatureContext plus a default dimension space point set in the constructor, as a project would do it -
 * for the features tagged @defaultDimensionSetter (own Behat suite, see behat.yml.dist).
 */
class DefaultDimensionFeatureContext extends FeatureContext
{
    public function __construct()
    {
        parent::__construct();
        $this->setDefaultDimensionSpacePoint(['language' => 'de']);
    }
}
