<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\FlightException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The user's global settings, read from ~/.config/flight/config.yaml.
 *
 * A ProjectConfig will sit beside this once flight.yml lands, which is why
 * this is not simply called "Configuration".
 */
class GlobalConfig
{
    /** @var array<string, mixed>|null */
    protected ?array $settings = null;

    /**
     * Paths and defaults are read from the config repository on demand rather
     * than captured in the constructor: commands are built during boot, so a
     * constructor-injected directory would freeze before anything could
     * override FLIGHT_CONFIG_DIR.
     */
    public function directory(): string
    {
        return rtrim((string) config('flight.config_dir'), DIRECTORY_SEPARATOR);
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return (array) config('flight.defaults');
    }

    public function certsDirectory(): string
    {
        return $this->directory().'/certs';
    }

    public function traefikDirectory(): string
    {
        return $this->directory().'/traefik';
    }

    public function file(): string
    {
        return $this->directory().'/config.yaml';
    }

    public function composeFile(): string
    {
        return $this->directory().'/compose.yaml';
    }

    public function overrideFile(): string
    {
        return $this->directory().'/compose.override.yaml';
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

    public function httpPort(): int
    {
        return (int) $this->get('http_port');
    }

    public function httpsPort(): int
    {
        return (int) $this->get('https_port');
    }

    public function dockerSocket(): string
    {
        return (string) $this->get('docker_socket');
    }

    protected function get(string $key): mixed
    {
        return $this->load()[$key] ?? null;
    }

    /**
     * Create the directory layout and seed config.yaml when it is missing.
     * Never touches an existing config.yaml.
     */
    public function scaffold(): void
    {
        foreach ([$this->directory(), $this->certsDirectory(), $this->traefikDirectory()] as $directory) {
            if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
                throw FlightException::make(
                    "Could not create {$directory}.",
                    'Check that you have permission to write there.',
                );
            }
        }

        if (! is_file($this->file())) {
            file_put_contents($this->file(), $this->stub());
        }
    }

    /**
     * Read config.yaml over the defaults and validate the result. Parsed once
     * per run so every service sees the same values.
     *
     * @return array<string, mixed>
     */
    public function load(): array
    {
        if ($this->settings !== null) {
            return $this->settings;
        }

        $settings = $this->defaults();

        if (is_file($this->file())) {
            try {
                $parsed = Yaml::parseFile($this->file());
            } catch (ParseException $e) {
                throw FlightException::make(
                    'Could not parse '.$this->file().'.',
                    $e->getMessage(),
                );
            }

            if ($parsed !== null && ! is_array($parsed)) {
                throw FlightException::make(
                    'Expected '.$this->file().' to contain a mapping of settings.',
                    'Delete the file to have Flight write a fresh one.',
                );
            }

            // A key the user removed falls back to its default rather than null.
            $settings = array_replace($settings, Arr::whereNotNull((array) $parsed));
        }

        return $this->settings = $this->validate($settings);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    protected function validate(array $settings): array
    {
        // Name attributes after the literal YAML keys, so a message points at
        // the line the user has to edit rather than "http port".
        $keys = array_keys($this->defaults());

        $validator = Validator::make($settings, [
            'domain' => ['required', 'string', 'regex:/^[a-zA-Z0-9]([a-zA-Z0-9.-]*[a-zA-Z0-9])?$/'],
            'network' => ['required', 'string', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/'],
            'http_port' => ['required', 'integer', 'between:1,65535'],
            'https_port' => ['required', 'integer', 'between:1,65535', 'different:http_port'],
            'docker_socket' => ['required', 'string'],
        ], [
            'required' => 'Expected :attribute to be set.',
            'string' => 'Expected :attribute to be text.',
            'integer' => 'Expected :attribute to be a whole number.',
            'between' => 'Expected :attribute to be between :min and :max.',
            'domain.regex' => 'Expected a hostname such as "flght.dev".',
            'network.regex' => 'Expected a Docker network name such as "flight".',
            'https_port.different' => 'http_port and https_port must differ.',
        ], attributes: array_combine($keys, $keys));

        if ($validator->fails()) {
            // Report one problem at a time, naming the key so the user knows
            // which line of their config.yaml to go and look at.
            $key = array_key_first($validator->errors()->messages());

            throw FlightException::make(
                "Invalid \"{$key}\" in ".$this->file().'.',
                (string) $validator->errors()->first($key),
            );
        }

        return $settings;
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

        # Host ports bound to the proxy.
        http_port: {$d['http_port']}
        https_port: {$d['https_port']}

        # Docker socket mounted into Traefik.
        docker_socket: {$d['docker_socket']}

        YAML;
    }
}
