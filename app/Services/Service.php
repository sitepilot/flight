<?php

declare(strict_types=1);

namespace App\Services;

/**
 * One service in a stack's compose file. Only name() and definition() are
 * required. There is no constructor, so each kind of service can inject
 * what it needs.
 */
abstract class Service
{
    /**
     * The compose service name, e.g. "traefik".
     */
    abstract public function name(): string;

    /**
     * The services.<name> fragment of the compose file.
     *
     * @return array<string, mixed>
     */
    abstract public function definition(): array;

    public function isEnabled(): bool
    {
        return true;
    }

    /**
     * Top-level networks this service needs.
     *
     * @return array<string, mixed>
     */
    public function networks(): array
    {
        return [];
    }

    /**
     * Top-level volumes this service needs.
     *
     * @return array<string, mixed>
     */
    public function volumes(): array
    {
        return [];
    }

    /**
     * The hostnames this service is served at.
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
