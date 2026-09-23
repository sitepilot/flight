# ✈️ Flight

A fast, effortless HTTPS development environment for your Docker projects.

Every project gets its own trusted `https://` domain. No ports to remember, no
certificate warnings, no hosts file to edit. Start it once and forget it is
running.

Flight runs your shared development services as a single stack, routed by
[Traefik](https://traefik.io) on `*.flght.dev`.

## Requirements

- Docker with the Compose plugin
- [mkcert](https://github.com/FiloSottile/mkcert) (`mkcert.exe` when running under WSL)
- PHP 8.4.1 or newer

## Installation

Download the latest release into a directory on your `PATH`:

```bash
mkdir -p ~/.local/bin
curl -fsSL https://github.com/sitepilot/flight/releases/latest/download/flight -o ~/.local/bin/flight
chmod +x ~/.local/bin/flight
```

Make sure `~/.local/bin` is in your `PATH`, then check it is working:

```bash
flight --version
```

## Updating

```bash
flight self-update
```

The binary is replaced in place, so it needs to be writable — fine under
`~/.local/bin`, `sudo` under `/usr/local/bin`. Only the released binary can
update itself; from a source checkout, pull the repository instead.

## Usage

```bash
flight stack:up        # start the services, issuing a certificate when needed
flight stack:down      # stop them
flight stack:restart   # recreate the containers
flight stack:secure    # regenerate the wildcard certificate and restart
flight stack:config    # edit the configuration in $EDITOR
```

Pass `-v` to any command to stream the raw `docker compose` output instead of a
spinner, which is what you want when a start fails.

The Traefik dashboard is available at `https://traefik.flght.dev`.

## Configuration

Everything lives in `~/.config/flight`, which is created on first run:

| Path                    | Owner  | Description                          |
| ----------------------- | ------ | ------------------------------------ |
| `config.yaml`           | you    | Settings, see below                  |
| `compose.override.yaml` | you    | Extra services, loaded when present  |
| `traefik/`              | you    | Traefik dynamic configuration, watched |
| `certs/`                | flight | Wildcard certificate                 |
| `compose.yaml`          | flight | Generated, overwritten on every run  |
| `traefik/tls.yml`       | flight | Generated, overwritten on every run  |

### Settings

```yaml
domain: flght.dev
network: flight
http_port: 80
https_port: 443
docker_socket: /var/run/docker.sock
```

| Key             | Default                | Description                             |
| --------------- | ---------------------- | --------------------------------------- |
| `domain`        | `flght.dev`            | Wildcard domain the stack serves        |
| `network`       | `flight`               | Shared Docker network projects join     |
| `http_port`     | `80`                   | Host port bound to HTTP                 |
| `https_port`    | `443`                  | Host port bound to HTTPS                |
| `docker_socket` | `/var/run/docker.sock` | Docker socket mounted into Traefik      |

Every `*.<domain>` hostname needs to resolve to `127.0.0.1`. Changing `domain`
issues a matching certificate on the next `flight stack:up`.

Set `FLIGHT_CONFIG_DIR` to run against a different configuration directory,
which is useful for trying things out without touching your real setup.

### Extra services

Services in `~/.config/flight/compose.override.yaml` are merged into the
project, so they start and stop with the stack:

```yaml
services:
  mailpit:
    image: axllent/mailpit
    labels:
      traefik.enable: true
      traefik.http.routers.mailpit.rule: "Host(`mail.${FLIGHT_DOMAIN}`)"
      traefik.http.services.mailpit.loadbalancer.server.port: 8025
```

`FLIGHT_DOMAIN`, `FLIGHT_NETWORK`, `FLIGHT_HTTP_PORT`, `FLIGHT_HTTPS_PORT` and
`FLIGHT_DOCKER_SOCK` are exported to Compose, so override files can interpolate
them.

### Traefik dynamic configuration

Any `.yml` file you drop in `~/.config/flight/traefik` is picked up without a
restart, for middlewares, routers or services pointing outside Docker.

## Projects

Describe the services a project needs in a `flight.yml` in its root:

```yaml
services:
  php:
    version: "8.4"
```

Then, from anywhere inside the project:

```bash
flight up        # start the Flight stack when needed, then the project
flight down      # stop the project; the Flight stack keeps running
flight restart   # recreate the project's containers
```

The project is served at `https://<project>.flght.dev`, where `<project>` is
the project name: `name` from `flight.yml`, or the directory name when unset.

### Settings

| Key        | Default             | Description                                  |
| ---------- | ------------------- | -------------------------------------------- |
| `name`     | the directory name  | Project name, and the subdomain it is served on |
| `services` |                     | The services to run, keyed by service name   |

A service's type is its service name, unless it sets `type`, so a project can run two
of the same kind:

```yaml
services:
  php: {}
  legacy:
    type: php
    version: "8.1"
```

### Hostnames

The first web service in `flight.yml` is served at `https://<project>.flght.dev`,
every other one at `https://<project>-<service>.flght.dev`, where `<service>` is
its key under `services`. For a project named `myapp`, in the example above
`php` gets `myapp.flght.dev` and `legacy` gets `myapp-legacy.flght.dev`.

A web service can answer on more hostnames too, for a multisite, tenants or a
separate admin domain:

```yaml
services:
  php:
    hostnames: [shop, api]   # also shop.flght.dev and api.flght.dev
```

Each one is a single subdomain, since that is what the wildcard certificate
covers. Two services serving the same hostname is an error.

### PHP

Runs [serversideup/php](https://serversideup.net/open-source/docker-php/)
with the project mounted at `/var/www/html`.

| Option    | Default     | Description                                          |
| --------- | ----------- | ---------------------------------------------------- |
| `version` | `8.4`       | `8.1`, `8.2`, `8.3`, `8.4` or `8.5`                  |
| `server`  | `fpm-nginx` | `fpm-nginx`, `fpm-apache` or `frankenphp`            |
| `webroot` | `public`    | Document root relative to the project; `.` for the root |
| `hostnames` | none      | Extra subdomains to serve, see [Hostnames](#hostnames) |

The image is built with your user and group id, so files the container writes
stay yours.

### Generated files

Flight writes the project's compose file to `.flight/`, together with a
`.gitignore` that keeps it out of your repository. Services in
`.flight/compose.override.yaml` are merged in and can be committed.

## Exposing a project manually

For projects without a `flight.yml`, attach your service to the `flight` network and label it:

```yaml
services:
  app:
    networks:
      - default
      - flight
    labels:
      traefik.enable: true
      traefik.http.routers.myapp.rule: "Host(`myapp.flght.dev`)"
      traefik.http.services.myapp.loadbalancer.server.port: 80

networks:
  flight:
    external: true
```

## Development

```bash
git clone git@github.com:sitepilot/flight.git
cd flight
composer install
./flight stack:up
```

`./flight` runs straight from the checkout.

```bash
composer test   # pest
composer lint   # pint
```

To build a binary:

```bash
php flight app:build flight --build-version=1.0.0
```

### Releasing

Publish a release from the GitHub releases page with a tag in the form
`v1.0.0`. The `Release` workflow builds the binary and attaches it to the
release.

## License

Flight is open-sourced software licensed under the [MIT license](LICENSE.md).
