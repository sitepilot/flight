<?php

declare(strict_types=1);

namespace App\Stacks;

use App\Services\Service;

/**
 * A compose project made up of services. Compose and Scaffold work with any
 * stack, so the global stack and project stacks are handled the same way.
 */
abstract class Stack
{
    /**
     * The compose project name, e.g. "flight".
     */
    abstract public function name(): string;

    /**
     * The compose --project-directory. Relative paths in the compose file
     * resolve against it.
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
     * What the reader of the generated compose file should edit instead.
     */
    public function composeNote(): string
    {
        return 'add your own services to compose.override.yaml instead.';
    }

    /**
     * Create what the stack needs on disk before its compose file is
     * written.
     */
    public function prepare(): void {}

    /**
     * The generated file, then the override file when it exists.
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
     * Variables passed to compose, so override files can use ${FLIGHT_DOMAIN}
     * and the like.
     *
     * @return array<string, string>
     */
    public function environment(): array
    {
        return [];
    }
}
