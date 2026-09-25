---
name: flight
description: Run, configure and debug local development environments with Flight, a Docker-based CLI configured by a flight.yaml file. Use when a project has a flight.yaml or flight.yml file, or when asked to start, stop, debug or configure a local environment with Flight, or to run PHP, Composer, Artisan or WP-CLI for such a project.
---

# Flight

Flight runs a project's app and services in Docker, described by a
`flight.yaml` file in the project root, and serves them over trusted HTTPS at
`https://<project>.flght.dev`. The project name defaults to the directory
name. The full documentation is at https://github.com/sitepilot/flight#readme.

## Check how the project runs first

A project with a `flight.yaml` may also have compose files that someone
started with plain `docker compose`. Check before running anything:

1. Run `flight list`. When it lists the project's directory, the project runs
   with Flight: use the `flight` commands below.
2. Otherwise, run `docker compose ls`. When it lists the project's compose
   files without `.flight/compose.yaml`, the project was started with plain
   Docker Compose: keep using `docker compose` commands, such as
   `docker compose exec`, and ask the user before switching it to
   `flight up`, which recreates its containers.
3. When neither lists it, the project isn't running. Start it with
   `flight up` when the task needs it.

## Rules

- Run PHP, Composer, Artisan and WP-CLI in the app's container with
  `flight exec -- <command>`, not on the host. The host may have another PHP
  version, and service hosts such as `db` only resolve inside the project.
- Put the command after `--`, so its options aren't read as Flight's.
  `flight exec` passes on the command's output and exit code.
- Don't run commands that wait for input or never exit: `flight shell`,
  `flight share`, and `flight logs -f`. Use `flight exec` and
  `flight logs --tail=100` instead.
- Only run `flight destroy` when the user asks for it. It deletes the
  project's database and the `.flight` directory.
- Don't edit files in `.flight`; Flight regenerates them. To change what
  Docker runs, edit `flight.yaml`, or a compose file listed under `compose`.
- Never put secrets in `flight.yaml`, which is committed. List them under a
  provisioning step's `env` instead.

## Commands

Run project commands anywhere inside the project, or pass `-p <name>` for a
running project elsewhere. Add `-v` to see Docker's full output when
something fails to start.

| Command | Purpose |
| ------- | ------- |
| `flight up` | Start the project, apply changes to `flight.yaml` and run the provisioning steps |
| `flight down` | Stop the project |
| `flight restart` | Recreate the project's containers |
| `flight list` | List running projects and their directories |
| `flight exec [--service=<name>] -- <command>` | Run a command in the app, or another service |
| `flight logs [<service>] [--tail=<n>]` | Show the logs of the app, or another service |
| `flight open [<service>]` | Open the app, or another service, in the browser |
| `flight destroy --force` | Remove the containers, volumes and `.flight` |

`flight up` validates `flight.yaml` before starting anything, and names the
exact setting when something is wrong. Run it after changing `flight.yaml`.

## flight.yaml

```yaml
name: shop              # optional, defaults to the directory name

app:                    # or just `app: php:8.4`
  type: php:8.4         # php:8.1 to php:8.5, default 8.4
  webroot: public       # `.` for the project root
  extensions: [intl]    # extra PHP extensions
  packages: [git]       # extra Debian packages
  node: "22"            # Node.js and npm next to PHP; quote the version
  wp_cli: false         # installs WP-CLI as `wp`
  server: fpm-nginx     # fpm-nginx, fpm-apache or frankenphp
  hostnames: [admin]    # also https://admin.flght.dev
  workers:              # background processes on the app's image
    queue: php artisan queue:work

recipe: laravel         # optional: laravel or wordpress

services:               # the name is the host other services use
  db: mariadb:11.8      # 10.6, 10.11, 11.4 or 11.8
  cache: valkey:9.1     # 7.2, 8.0, 8.1, 9.0 or 9.1

provision:              # runs on every `flight up`, so keep steps repeatable
  - name: Install dependencies
    run: composer install
    unless: test -d vendor   # skips the step when this succeeds
    env: [COMPOSER_AUTH]     # secrets from the shell, .env or ~/.config/flight/.env

compose:                # own compose files, applied after Flight's
  - path: compose.override.yml
    required: false
```

- The project is mounted at `/var/www/html` in the app's container.
- MariaDB listens on port 3306 with database, user and password `flight`,
  unless the service sets `database`, `user` or `password`. These only apply
  on the first start.
- Valkey is Redis-compatible and listens on port 6379.
- A web service other than the app is served at
  `https://<project>-<service>.flght.dev`.
- A recipe's app and services may be changed in `flight.yaml`. List only what
  differs, and don't repeat the `type` unless the version changes.

## Recipes

**Laravel** serves `public`. Options, under `recipe: laravel:`, are
`queue: true` and `scheduler: true`, which add `queue` and `scheduler`
workers. Point `.env` at the services, e.g. `DB_HOST=db`, `DB_DATABASE=flight`,
`DB_USERNAME=flight`, `DB_PASSWORD=flight`, `REDIS_HOST=cache`, and
`APP_URL=https://<project>.flght.dev`. Run Vite on the host with
`npm run dev`.

**WordPress** downloads and installs WordPress on the first `flight up`, with
MariaDB and WP-CLI. Log in at `/wp-admin` with `admin` / `admin`. Options,
under `recipe: wordpress:`, are `title`, `admin_user`, `admin_password` and
`admin_email`. For a theme or plugin repository, set `app.project_path`, such
as `wp-content/themes/my-theme`; WordPress itself is then kept in
`.flight/app/data`.

## Docker Compose projects

A project with its own compose files lists them under `compose`. A service
gets an address with an `x-flight` block, such as
`x-flight: { origin: http://web:80 }`. The service named `app`, or the one
with `app: true`, is the app.

## Troubleshooting

- A command fails to start: run it again with `-v`.
- The browser warns about the certificate: run `flight stack:secure`, then
  restart the browser.
- Ports 80 or 443 are in use: another program holds them; ask the user
  before changing the ports in `~/.config/flight/config.yaml`.
- The global configuration and its services are managed with `flight
  stack:up`, `stack:down`, `stack:restart` and `stack:config`.
