<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Services\Service;

/**
 * A second global service, to test the Service contract with something
 * other than Traefik.
 */
class StubService extends Service
{
    public static bool $prepared = false;

    public function definition(): array
    {
        return ['image' => 'stub:latest'];
    }

    public function volumes(): array
    {
        return ['stub_data' => null];
    }

    public function prepare(): void
    {
        static::$prepared = true;
    }
}
