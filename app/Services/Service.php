<?php

declare(strict_types=1);

namespace App\Services;

/**
 * A Flight-managed Docker service: one entry in a stack's compose file.
 *
 * Everything past name() and definition() has a no-op default, so a new
 * service implements only what it actually needs. There is deliberately no
 * constructor here, which lets each service type-hint the config it wants
 * (GlobalConfig today, ProjectConfig once flight.yml lands) and have the
 * container resolve it.
 */
abstract class Service
{
    /**
     * The compose service key, e.g. "traefik".
     */
    abstract public function name(): string;

    /**
     * The services.<name> fragment of the compose file.
     *
     * @return array<string, mixed>
     */
    abstract public function definition(): array;

    /**
     * Whether this service is included in the generated compose file.
     */
    public function isEnabled(): bool
    {
        return true;
    }

    /**
     * Extra top-level networks: entries this service needs.
     *
     * @return array<string, mixed>
     */
    public function networks(): array
    {
        return [];
    }

    /**
     * Extra top-level volumes: entries this service needs.
     *
     * @return array<string, mixed>
     */
    public function volumes(): array
    {
        return [];
    }

    /**
     * Hostnames this service routes, shown in the summary panel.
     *
     * @return array<int, string>
     */
    public function hostnames(): array
    {
        return [];
    }

    /**
     * Write any files this service needs before compose runs.
     */
    public function prepare(): void {}
}
