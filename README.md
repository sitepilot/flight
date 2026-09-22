# Flight

A fast, effortless HTTPS development environment for your Docker projects.

Every project gets its own trusted `https://` domain. No ports to remember, no
certificate warnings, no hosts file to edit. Start it once and forget it is
running.

Flight runs your shared development services as a single stack, routed by
[Traefik](https://traefik.io) on `*.flght.dev`.

## Requirements

- Docker with the Compose plugin
- [mkcert](https://github.com/FiloSottile/mkcert) (`mkcert.exe` when running under WSL)
- PHP 8.4.1 or newer (the binary is a PHAR, so PHP runs it)

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

From there, `flight self-update` keeps it current.

### From source

```bash
git clone git@github.com:sitepilot/flight.git
cd flight
composer install
./flight stack:up
```

Running `./flight` from the checkout is the development workflow. Building a
binary is only needed to exercise the packaged behaviour, such as
`self-update`:

```bash
php flight app:build flight --build-version=1.0.0
```

Note that `app:build` writes the version it is given back into
`config/app.php`, so pass one deliberately rather than leaving it to the
prompt.

## Usage

```bash
flight stack:up        # start the services, issuing a certificate when needed
flight stack:down      # stop them
flight stack:restart   # recreate the containers
flight stack:secure    # regenerate the wildcard certificate and restart
flight stack:config    # edit the configuration in $EDITOR
```

`stack:config` opens `config.yaml` in `$VISUAL`, `$EDITOR`, or the first of
`nano`, `vim` and `vi` it finds, then validates what you saved. It works while
Docker is down and while the file is invalid, so it can always be used to fix a
broken configuration.

Pass `-v` to any of them to stream the raw `docker compose` output instead of a
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

## Exposing a project

Attach your service to the `flight` network and label it:

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

> **Upgrading from the bash version:** the shared network is now called
> `flight` rather than `traefik`. A project still declaring
> `networks: { traefik: { external: true } }` fails to start with
> `network traefik not found`, which does not mention Flight at all. Rename the
> key to `flight`, or set `network: traefik` in `config.yaml` to keep the old
> name.

## Updating

```bash
flight self-update
```

Checks Packagist for a newer `sitepilot/flight` release and replaces the
binary in place, so the file needs to be writable — fine under
`~/.local/bin`, `sudo` under `/usr/local/bin`. The command only exists in the
built binary; from a source checkout, pull the repository instead.

## Releasing

Releases are cut by pushing an unprefixed semver tag:

```bash
git tag 1.0.1
git push origin 1.0.1
```

The `Release` workflow builds the binary with that version baked in, checks
the two match, and publishes it as a release asset named `flight`. Three
things have to stay in step for `self-update` to work, and the workflow
enforces the first two:

| Thing | Must be |
| ----- | ------- |
| Git tag | `1.0.1` — unprefixed, so it matches Packagist's version string |
| Built binary | reports the same version (`flight --version`) |
| Release asset | named `flight` |

Version discovery goes through Packagist, so the package must be published
there as `sitepilot/flight` with the GitHub repository as its source. Until
it is, `self-update` reports that it could not check for a new version.

## Development

```bash
composer test   # pest
composer lint   # pint
```
