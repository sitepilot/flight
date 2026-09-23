<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\YamlFile;

/**
 * The reverse proxy. Terminates TLS for *.<domain> and routes to containers
 * on the shared network. Its dashboard is served at traefik.<domain>.
 */
class Traefik extends Service
{
    protected function defaults(): array
    {
        return [
            'http_port' => 80,
            'https_port' => 443,
            'docker_socket' => '/var/run/docker.sock',
        ];
    }

    protected function rules(): array
    {
        return [
            'http_port' => ['required', 'integer', 'between:1,65535'],
            'https_port' => ['required', 'integer', 'between:1,65535', 'different:http_port'],
            'docker_socket' => ['required', 'string'],
        ];
    }

    public static function routes(): bool
    {
        return true;
    }

    public function definition(): array
    {
        $network = $this->global->network();

        return [
            'image' => 'traefik:v3.7',
            'restart' => 'unless-stopped',
            'command' => [
                '--api.insecure=true',
                '--providers.docker',
                '--providers.docker.exposedByDefault=false',
                "--providers.docker.network={$network}",
                '--entrypoints.web.address=:80',
                '--entrypoints.web.http.redirections.entrypoint.to=:'.$this->option('https_port'),
                '--entrypoints.web.http.redirections.entrypoint.scheme=https',
                '--entrypoints.websecure.address=:443',
                '--entrypoints.websecure.asDefault=true',
                '--entrypoints.websecure.http.tls=true',
                '--serversTransport.insecureSkipVerify=true',
                '--providers.file.watch=true',
                '--providers.file.directory=/opt/flight/config',
            ],
            'ports' => [
                $this->option('http_port').':80',
                $this->option('https_port').':443',
            ],
            'volumes' => [
                $this->global->traefikDirectory().':/opt/flight/config:ro',
                $this->global->certsDirectory().':/opt/flight/certs:ro',
                $this->option('docker_socket').':/var/run/docker.sock',
            ],
            // The dashboard listens on 8080.
            'labels' => $this->route(8080),
        ];
    }

    public function environment(): array
    {
        return [
            'FLIGHT_HTTP_PORT' => (string) $this->option('http_port'),
            'FLIGHT_HTTPS_PORT' => (string) $this->option('https_port'),
            'FLIGHT_DOCKER_SOCK' => (string) $this->option('docker_socket'),
        ];
    }

    public function summary(): array
    {
        return [
            ['HTTP', ':'.$this->option('http_port').'  → redirects to HTTPS'],
            ['HTTPS', ':'.$this->option('https_port')],
        ];
    }

    /**
     * Use the wildcard certificate as Traefik's default certificate.
     */
    public function prepare(): void
    {
        YamlFile::write($this->global->traefikDirectory().'/tls.yaml', [
            'tls' => [
                'stores' => [
                    'default' => [
                        'defaultCertificate' => [
                            'certFile' => '/opt/flight/certs/ssl.crt',
                            'keyFile' => '/opt/flight/certs/ssl.key',
                        ],
                    ],
                ],
            ],
        ], 'add your own dynamic configuration to this directory instead.');
    }
}
