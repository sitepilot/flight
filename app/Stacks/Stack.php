<?php

declare(strict_types=1);

namespace App\Stacks;

use App\Services\Service;

/**
 * A named compose project in a directory, made up of services.
 *
 * Compose and Scaffold only ever talk to this, which is what will let a
 * ProjectStack (driven by a project's flight.yml) reuse both unchanged.
 */
abstract class Stack
{
    /**
     * The compose project name, e.g. "flight".
     */
    abstract public function name(): string;

    /**
     * Passed to compose as --project-directory. Relative volume paths in the
     * generated compose file resolve against it.
     */
    abstract public function directory(): string;

    /**
     * The enabled services, in the order they are merged.
     *
     * @return array<int, Service>
     */
    abstract public function services(): array;

    /**
     * The compose file this stack generates.
     */
    abstract public function composeFile(): string;

    /**
     * An optional user-owned file merged on top of the generated one.
     */
    public function overrideFile(): ?string
    {
        return null;
    }

    /**
     * Generated file first, then the override when it exists on disk.
     *
     * @return array<int, string>
     */
    public function composeFiles(): array
    {
        $files = [$this->composeFile()];

        $override = $this->overrideFile();

        if ($override !== null && is_file($override)) {
            $files[] = $override;
        }

        return $files;
    }

    /**
     * Variables exported to the compose process, so that a user's override
     * file can still interpolate ${FLIGHT_DOMAIN} and friends.
     *
     * @return array<string, string>
     */
    public function environment(): array
    {
        return [];
    }
}
