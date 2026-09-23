<?php

namespace Tests;

use LaravelZero\Framework\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * The temporary configuration directory created by a test, if any.
     */
    public ?string $flightDirectory = null;

    /**
     * The temporary project created by a test, if any, and the directory to
     * return to afterward.
     */
    public ?string $projectDirectory = null;

    public ?string $originalDirectory = null;

    protected function tearDown(): void
    {
        if ($this->originalDirectory !== null) {
            chdir($this->originalDirectory);
            $this->originalDirectory = null;
        }

        if ($this->projectDirectory !== null) {
            removeDirectory($this->projectDirectory);
            $this->projectDirectory = null;
        }

        if ($this->flightDirectory !== null) {
            removeDirectory($this->flightDirectory);
            $this->flightDirectory = null;
        }

        parent::tearDown();
    }
}
