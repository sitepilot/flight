<?php

declare(strict_types=1);

namespace App\Services;

use App\Stacks\Stack;
use App\Support\GlobalConfig;
use App\Support\HasOptions;

/**
 * One service in a stack's compose file, built from its options in
 * config.yaml or flight.yaml. The options are validated when the service is
 * built, so mistakes are reported before anything is written or started.
 *
 * A service that routes() is served over HTTPS at the label the stack gives
 * it, plus any extra `hostnames` the user lists.
 */
abstract class Service
{
    use HasOptions;

    /**
     * A single DNS label, since the wildcard certificate covers only one
     * level under the domain.
     */
    public const string LABEL = '/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/';

    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        protected Stack $stack,
        protected GlobalConfig $global,
        protected string $name,
        array $options = [],
        protected ?string $label = null,
    ) {
        unset($options['type']);

        // Allow `hostnames: shop` as well as a list.
        if (static::routes() && is_string($options['hostnames'] ?? null)) {
            $options['hostnames'] = [$options['hostnames']];
        }

        $this->configure($stack->config(), "services.{$name}", $options);
    }

    /**
     * The services.<name> fragment of the compose file. The stack adds its
     * networks unless the fragment sets its own.
     *
     * @return array<string, mixed>
     */
    abstract public function definition(): array;

    /**
     * Whether the proxy serves this service over HTTPS. Static, because the
     * stack needs to know before it builds the service.
     */
    public static function routes(): bool
    {
        return false;
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * The hostnames this service is served at.
     *
     * @return array<int, string>
     */
    public function hostnames(): array
    {
        if (! static::routes() || $this->label === null) {
            return [];
        }

        return array_map(
            fn (string $label): string => $label.'.'.$this->global->domain(),
            array_values(array_unique([$this->label, ...$this->options['hostnames']])),
        );
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
     * Variables passed to compose, so override files can use them.
     *
     * @return array<string, string>
     */
    public function environment(): array
    {
        return [];
    }

    /**
     * Rows for the summary shown once the stack runs.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    public function summary(): array
    {
        return [];
    }

    /**
     * Write any files this service needs before compose runs.
     */
    public function prepare(): void {}

    /**
     * Traefik labels that route hostnames() to a port in this container.
     *
     * @return array<string, string>
     */
    protected function route(int $port): array
    {
        // Router names are global in Traefik, so prefix them with the stack.
        $router = $this->stack->name().'-'.$this->name;

        $rule = implode(' || ', array_map(
            fn (string $hostname): string => "Host(`{$hostname}`)",
            $this->hostnames(),
        ));

        return [
            'traefik.enable' => 'true',
            "traefik.http.routers.{$router}.rule" => $rule,
            "traefik.http.services.{$router}.loadbalancer.server.port" => (string) $port,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function allDefaults(): array
    {
        return static::routes()
            ? [...$this->defaults(), 'hostnames' => []]
            : $this->defaults();
    }

    /**
     * @return array<string, mixed>
     */
    protected function allRules(): array
    {
        return static::routes()
            ? [...$this->rules(), 'hostnames' => ['list'], 'hostnames.*' => ['string', 'regex:'.self::LABEL]]
            : $this->rules();
    }

    /**
     * @return array<string, string>
     */
    protected function allMessages(): array
    {
        return [
            'hostnames.*.regex' => 'Expected a lowercase subdomain such as "admin", which becomes admin.'.$this->global->domain().'.',
            ...$this->messages(),
        ];
    }
}
