<?php

namespace Tests;

use LaravelZero\Framework\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * The throwaway configuration directory created by a test, if any.
     */
    public ?string $flightDirectory = null;

    protected function tearDown(): void
    {
        if ($this->flightDirectory !== null) {
            removeDirectory($this->flightDirectory);
            $this->flightDirectory = null;
        }

        parent::tearDown();
    }
}
