<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\GlobalConfig;
use App\Support\YamlFile;

/**
 * The reverse proxy: a single Traefik container terminating TLS for
 * *.<domain> and routing to any container on the shared network.
 */
class Traefik extends Service
{
    public function __construct(protected GlobalConfig $config) {}

    public function name(): string
    {
        return 'traefik';
    }

    public function definition(): array
    {
        $network = $this->config->network();
        $domain = $this->config->domain();

        return [
            'image' => 'traefik:v3.7',
            'restart' => 'unless-stopped',
            'networks' => ['default'],
            'command' => [
                '--api.insecure=true',
                '--providers.docker',
                '--providers.docker.exposedByDefault=false',
                "--providers.docker.network={$network}",
                '--entrypoints.web.address=:80',
                '--entrypoints.web.http.redirections.entrypoint.to=websecure',
                '--entrypoints.web.http.redirections.entrypoint.scheme=https',
                '--entrypoints.websecure.address=:443',
                '--entrypoints.websecure.asDefault=true',
                '--entrypoints.websecure.http.tls=true',
                '--serversTransport.insecureSkipVerify=true',
                '--providers.file.watch=true',
                '--providers.file.directory=/opt/flight/config',
            ],
            'ports' => [
                $this->config->httpPort().':80',
                $this->config->httpsPort().':443',
            ],
            'volumes' => [
                './traefik:/opt/flight/config:ro',
                './certs:/opt/flight/certs:ro',
                $this->config->dockerSocket().':/var/run/docker.sock',
            ],
            'labels' => [
                // Required: the Docker provider runs with exposedByDefault=false,
                // so without this the dashboard router is never registered.
                'traefik.enable' => 'true',
                'traefik.http.services.traefik.loadbalancer.server.port' => '8080',
                'traefik.http.routers.traefik.rule' => "Host(`traefik.{$domain}`)",
            ],
        ];
    }

    /**
     * The shared network is the project's default network, renamed. Declaring
     * it as a second network instead would leave every Flight service on two
     * networks for no benefit, and would not match the labels Docker has
     * already written for an existing `flight` network.
     */
    public function networks(): array
    {
        return [
            'default' => ['name' => $this->config->network()],
        ];
    }

    public function hostnames(): array
    {
        return ['traefik.'.$this->config->domain()];
    }

    /**
     * Point Traefik's default TLS store at the wildcard certificate. Issuing
     * that certificate is a stack-level step and lives in the commands.
     */
    public function prepare(): void
    {
        YamlFile::write($this->config->traefikDirectory().'/tls.yml', [
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
