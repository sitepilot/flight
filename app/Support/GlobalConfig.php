<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Arr;

class GlobalConfig extends StackConfig
{
    /**
     * The names the config file can have; config.yaml wins when both exist.
     */
    public const array FILES = ['config.yaml', 'config.yml'];

    /**
     * Read on demand, so a changed FLIGHT_CONFIG_DIR is always picked up.
     */
    public function directory(): string
    {
        return rtrim((string) config('flight.config_dir'), DIRECTORY_SEPARATOR);
    }

    public function file(): string
    {
        foreach (self::FILES as $name) {
            if (is_file($this->directory().'/'.$name)) {
                return $this->directory().'/'.$name;
            }
        }

        return $this->directory().'/'.self::FILES[0];
    }

    public function stackName(): string
    {
        return 'flight';
    }

    public function certsDirectory(): string
    {
        return $this->filesDirectory().'/certs';
    }

    public function traefikDirectory(): string
    {
        return $this->directory().'/traefik';
    }

    public function shareDirectory(): string
    {
        return $this->filesDirectory().'/share';
    }

    public function certificateFile(): string
    {
        return $this->certsDirectory().'/ssl.crt';
    }

    public function keyFile(): string
    {
        return $this->certsDirectory().'/ssl.key';
    }

    public function domain(): string
    {
        return (string) $this->get('domain');
    }

    public function network(): string
    {
        return (string) $this->get('network');
    }

    public function environment(): array
    {
        return [
            'FLIGHT_DOMAIN' => $this->domain(),
            'FLIGHT_NETWORK' => $this->network(),
        ];
    }

    public function ownsNetwork(): bool
    {
        return true;
    }

    public function label(string $service): string
    {
        return $service;
    }

    public function prepare(): void
    {
        $this->scaffold();

        parent::prepare();
    }

    public function scaffold(): void
    {
        Files::ensureDirectory($this->directory());

        if (! is_file($this->file())) {
            Files::put($this->file(), $this->stub());
        }
    }

    /**
     * The Flight stack has no app, so `x-flight` never makes one.
     */
    protected function hasApp(): bool
    {
        return false;
    }

    protected function recipeSetting(array $settings): mixed
    {
        return config('flight.recipe');
    }

    protected function defaults(): array
    {
        return (array) config('flight.defaults');
    }

    protected function withDefaults(array $settings): array
    {
        return array_replace($this->defaults(), Arr::whereNotNull($settings));
    }

    protected function rules(): array
    {
        return [
            'domain' => ['required', 'string', 'regex:/^[a-zA-Z0-9]([a-zA-Z0-9.-]*[a-zA-Z0-9])?$/'],
            'network' => ['required', 'string', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/'],
        ];
    }

    protected function messages(): array
    {
        return [
            'domain.regex' => 'Expected a hostname such as "flght.dev".',
            'network.regex' => 'Expected a Docker network name such as "flight".',
        ];
    }

    protected function stub(): string
    {
        $d = $this->defaults();

        return <<<YAML
        # Flight configuration. Run `flight stack:restart` after changing these.

        # Wildcard domain served by the proxy. Every *.<domain> hostname must
        # resolve to 127.0.0.1. Run `flight stack:secure` after changing it.
        domain: {$d['domain']}

        # Shared Docker network that project containers join to be routed.
        network: {$d['network']}

        # Options for the built-in services, merged over their defaults.
        # services:
        #   traefik:
        #     http_port: 80
        #     https_port: 443
        #     docker_socket: /var/run/docker.sock

        # Your own compose files, run after Flight's, such as extra services
        # for every project.
        # compose:
        #   - my-services.yaml

        YAML;
    }
}
