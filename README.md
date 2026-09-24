# ✈️ Flight

Flight gives every project on your machine its own trusted HTTPS address, such
as `https://myapp.flght.dev`. There are no ports to remember, no certificate
warnings and no hosts file to edit.

You describe what a project needs in a small `flight.yaml` file, run
`flight up`, and Flight starts it in Docker.

- **Trusted HTTPS** for every project, with one local wildcard certificate.
- **Recipes** for common projects, such as Laravel and WordPress.
- **Provisioning** steps that set a project up on its first start.

## Contents

- [Getting started](#getting-started)
- [How Flight works](#how-flight-works)
- [Commands](#commands)
- [The flight.yaml file](#the-flightyaml-file)
- [Guides](#guides)
- [Service reference](#service-reference)
- [Global configuration](#global-configuration)
- [Troubleshooting](#troubleshooting)
- [Development](#development)

## Getting started

### Requirements

- Docker with the Compose plugin
- [mkcert](https://github.com/FiloSottile/mkcert), to create the certificate
  (`mkcert.exe` on Windows when you use WSL)
- PHP 8.4.1 or newer

### Install

Download Flight into a folder on your `PATH`:

```bash
mkdir -p ~/.local/bin
curl -fsSL https://github.com/sitepilot/flight/releases/latest/download/flight -o ~/.local/bin/flight
chmod +x ~/.local/bin/flight
```

Check that it works:

```bash
flight --version
```

To update later, run `flight self-update`.

### Your first project

1. Create a project folder with a page to serve:

   ```bash
   mkdir -p hello/public
   echo '<?php echo "Hello from Flight";' > hello/public/index.php
   cd hello
   ```

2. Add a `flight.yaml` that runs it on PHP:

   ```yaml
   app: php:8.4
   ```

3. Start it:

   ```bash
   flight up
   ```

4. Open `https://hello.flght.dev` in your browser.

The address comes from the folder name, and PHP serves the `public/` folder by
default. For Laravel and WordPress, a [recipe](#recipes) sets up the app and
services for you.

The first time, Flight creates a certificate and asks mkcert to trust it.
Restart your browser afterwards so it picks up the new certificate authority.

## How Flight works

Flight runs two kinds of Docker stacks:

- **The Flight stack** runs once for your whole machine. It contains
  [Traefik](https://traefik.io), a proxy that listens on ports 80 and 443 and
  sends each `https://*.flght.dev` request to the right project.
- **A project stack** runs the app and services one project needs, such as
  PHP and MariaDB. Its app and other web services join the Flight stack's
  network, so Traefik can reach them; databases stay private to the project.

`flight up` starts the Flight stack when it isn't running yet, then the
project. Stopping a project leaves the Flight stack running for your other
projects.

Flight writes a normal Docker Compose file for every stack, so you can always
look at what runs and why.

## Commands

Run these from anywhere inside a project:

| Command         | What it does                                                   |
| --------------- | -------------------------------------------------------------- |
| `flight up`     | Starts the Flight stack when needed, then the project, then runs its [provisioning](#provisioning) steps |
| `flight down`   | Stops the project; the Flight stack keeps running              |
| `flight restart`| Recreates the project's containers                             |
| `flight destroy`| Removes the project's containers, volumes and data in `.flight`, after asking. Keeps your `compose.override.yaml` and `.env`. |
| `flight shell [service]` | Opens a shell in a container, by default your app's |
| `flight exec -- <command>` | Runs a command in your app's container, e.g. `flight exec -- php artisan migrate`. Add `--service=<name>` for another service. |
| `flight logs [service]` | Shows a container's logs, by default your app's. Add `-f` to keep following them and `--tail=100` for only the latest lines. |
| `flight share [service]` | Shares your app, or another service with a URL, at a temporary public URL until you press Ctrl+C. See [Sharing a project](#sharing-a-project). |

These manage the Flight stack itself:

| Command               | What it does                                             |
| --------------------- | -------------------------------------------------------- |
| `flight stack:up`     | Starts the Flight stack, creating a certificate when needed |
| `flight stack:down`   | Stops it                                                 |
| `flight stack:restart`| Recreates its containers, e.g. after changing settings   |
| `flight stack:secure` | Creates a new certificate and restarts the stack         |
| `flight stack:config` | Opens the [global configuration](#global-configuration) in your editor |
| `flight self-update`  | Updates Flight to the latest release                     |

Add `-v` to any command to see Docker's full output instead of a spinner. This
helps when something fails to start.

`flight exec` passes on the command's output and exit code, so you can use it
in scripts and pipes. Put the command after `--`, so its options aren't read
as Flight's: `flight exec -- composer install --no-dev`.

## The flight.yaml file

Every project has a `flight.yaml` in its root folder. `flight.yml` works too;
when both exist, Flight uses `flight.yaml`.

```yaml
name: shop            # optional, defaults to the folder name

app: php:8.3          # what runs your app
recipe: laravel       # optional, sets up the app and services for Laravel

services:             # what your app uses, such as a database
  db: mariadb:11.8

provision:            # optional, commands to run on `flight up`
  - name: Install dependencies
    run: composer install
```

| Key         | What it is                                                     |
| ----------- | -------------------------------------------------------------- |
| `name`      | The project name, and its address: `https://<name>.flght.dev`. Defaults to the folder name. |
| `app`       | What runs your app, see [App](#app)                            |
| `recipe`    | A preset app and services, see [Recipes](#recipes)             |
| `services`  | What your app uses, see [Services](#services)                  |
| `provision` | Commands to run on every `flight up`, see [Provisioning](#provisioning) |

A project needs an app, services or a recipe.

### App

`app` says what runs your app: its type, with a version after the colon.

```yaml
app: php:8.4          # or just `php` for the default version
```

To set options, write it as a mapping with a `type`:

```yaml
app:
  type: php:8.4
  node: "22"          # Node next to PHP, e.g. to build assets
  hostnames: [admin]
```

Your app runs as the `app` service and is served at
`https://<project>.flght.dev`. `flight exec`, `flight shell` and `flight logs`
use it unless you name another service. See [PHP](#php) for its options.

### Recipes

A recipe is a ready-made app and services for a kind of project, so you
don't have to list them yourself.

| Recipe      | What you get                                                  |
| ----------- | ------------------------------------------------------------- |
| `laravel`   | PHP, serving the `public/` folder, and optionally a queue worker and scheduler, see the [Laravel guide](#a-laravel-app) |
| `wordpress` | PHP with WP-CLI and MariaDB, and WordPress installed for you, see the [WordPress guide](#a-wordpress-site) |

You can change a recipe's app and services in `flight.yaml`. List only what
you want to be different; everything else stays as the recipe set it:

```yaml
app: php:8.3         # the recipe still serves public/
recipe: laravel

services:
  db: mariadb        # adds a database next to the recipe's app
```

A few rules:

- Options are changed one by one. Lists, such as `hostnames`, are replaced as
  a whole.
- You can change and add services, but not remove the recipe's services.
- Options neither the recipe nor you set use their
  [defaults](#service-reference).
- To change a recipe's app or service, you don't repeat its `type`. To change
  its version, write the same type with another version, such as
  `app: php:8.3`. Another type, such as `mariadb` for Laravel's app, is an
  error, because the recipe's options wouldn't fit it.

Some recipes have options of their own. Put them under the recipe's name:

```yaml
recipe:
  wordpress:
    admin_user: nick
```

### Services

Each entry under `services` is one container your app uses, such as a database
or a cache. You choose its name, and its type says what it runs, with a
version after the colon:

```yaml
services:
  db: mariadb:11.8
  cache: valkey        # the default version
```

To set options, write the service as a mapping with a `type`:

```yaml
services:
  db:
    type: mariadb:11.8
    database: shop
```

Services reach each other by name: from your app, the service above is at the
host `db`. The name `app` belongs to your app.

A service can also be an extra PHP container next to your app, served at
its own address:

```yaml
services:
  legacy: php:8.1      # https://<project>-legacy.flght.dev
```

See the [service reference](#service-reference) for every service and its options.

### Workers

Workers are background processes of your app, such as a queue worker. Each
runs in a container of its own, on the app's image, with the same
files and settings, so it always matches your app:

```yaml
app:
  type: php:8.4
  workers:
    queue: php artisan queue:work
    scheduler: php artisan schedule:work
```

A worker goes by its own name, such as `queue`, so each name can be used once
in a project, by a service or a worker. It starts and stops with the project,
restarts when it stops, and works with `flight logs queue` and
`flight exec --service=queue`. Give it a command that keeps running:
`schedule:work`, not `schedule:run`.

### Hostnames

Flight gives your app and every other web service an address under
`flght.dev`:

- Your app gets `https://<project>.flght.dev`.
- Any other web service gets `https://<project>-<service>.flght.dev`.

For a project called `shop` with an extra PHP service `legacy`, that is
`shop.flght.dev` and `shop-legacy.flght.dev`.

To answer on more addresses, for example for a multisite or an admin panel,
add `hostnames`:

```yaml
app:
  type: php:8.4
  hostnames: [admin, api]   # also admin.flght.dev and api.flght.dev
```

Each hostname is one subdomain, such as `admin` or `my-shop`, because the
certificate covers one level under `flght.dev`. Two services can't share a
hostname.

### Provisioning

Provisioning steps are commands that set your project up, such as installing
dependencies. They run inside the project's containers on every `flight up`,
right after the project has started. Steps from a recipe run first, then yours.

```yaml
provision:
  - name: Install dependencies
    run: composer install
    unless: test -d vendor
```

| Key       | What it is                                                     |
| --------- | -------------------------------------------------------------- |
| `name`    | A short description, shown while the step runs                 |
| `service` | Optional. The service to run the command in; defaults to your app |
| `run`     | The shell command to run                                       |
| `unless`  | Optional. A check command; when it succeeds, the step is skipped |
| `dir`     | Optional. The folder to run in, see below                      |
| `env`     | Optional. Secret variables the step needs, see [Secrets](#secrets) |

Because steps run on every `flight up`, each one should be safe to repeat. Add
an `unless` check to skip a step once its work is done, or use a command that
is harmless to run again. When a step fails, `flight up` stops and shows what
went wrong.

A step runs in the service's working folder, which for `app` is your app. Use
`dir` to run it somewhere else:

```yaml
provision:
  - name: Install tool dependencies
    dir: tools
    run: composer install
```

### Secrets

License keys and tokens don't belong in `flight.yaml`, because you commit that
file. Instead, list the variables a step needs under `env`, and keep their
values somewhere private:

```yaml
provision:
  - name: Install dependencies
    env: [COMPOSER_AUTH]   # Composer reads this for private packages
    run: composer install
```

Flight looks for each variable in three places and uses the first it finds:

1. Your shell, e.g. `export COMPOSER_AUTH=...`
2. The project's `.flight/.env`, for this project only
3. `~/.config/flight/.env`, for all your projects

```bash
# ~/.config/flight/.env
COMPOSER_AUTH='{"github-oauth": {"github.com": "your-token"}}'
```

Neither file is committed. If a variable can't be found, `flight up` stops
before starting anything and tells you where to set it. Inside the step, use
the variable as `${NAME}`, or let a tool read it, as Composer does here.

Your project's own `.env` is left alone; that one belongs to your app.

### The .flight folder

Flight keeps its files for a project in a `.flight` folder, which it hides from
Git for you.

| Path                            | What it is                                    |
| ------------------------------- | --------------------------------------------- |
| `compose.yaml`                  | The generated Docker Compose file; don't edit it |
| `compose.override.yaml`         | Your own additions, see below. This one can be committed. |
| `.env`                          | Your project's [secrets](#secrets)            |
| `<service>/build/`              | Files a service's image is built from         |
| `<service>/data/`               | What a service keeps, such as WordPress when you [develop a theme](#a-wordpress-theme-or-plugin) |

For anything Flight has no option for, add a `compose.override.yaml`. Docker
Compose merges it into the generated file. For example, to mount an extra
folder:

```yaml
# .flight/compose.override.yaml
services:
  app:
    volumes:
      - ./packages/my-package:/var/www/html/vendor/acme/my-package
```

Tools that scan your whole repository, such as linters, may need `.flight`
added to their ignore list.

## Guides

### A Laravel app

```yaml
recipe: laravel

services:
  db: mariadb
```

Point Laravel's `.env` at the database:

```dotenv
DB_CONNECTION=mariadb
DB_HOST=db
DB_DATABASE=flight
DB_USERNAME=flight
DB_PASSWORD=flight
```

Then run `flight up` and open `https://<project>.flght.dev`. To run Artisan:

```bash
flight exec -- php artisan migrate
```

For queued jobs and scheduled tasks, turn on the recipe's queue worker and
scheduler. They run as [workers](#workers) of your app, on the same image
as your app, start and stop with it, and pick up code changes by
themselves. See their output with `flight logs -f queue` or
`flight logs -f scheduler`.

```yaml
recipe:
  laravel:
    queue: true
    scheduler: true
```

Run Vite on your machine with `npm run dev`, where it watches files fastest.
Set `APP_URL=https://<project>.flght.dev` in `.env`, so Vite lets the site load
its scripts. Composer scripts and Artisan run in the container:

```bash
flight exec -- composer test
```

| Recipe option | Default | What it is                                                     |
| ------------- | ------- | -------------------------------------------------------------- |
| `queue`       | `false` | Adds a `queue` worker running `php artisan queue:listen`       |
| `scheduler`   | `false` | Adds a `scheduler` worker running `php artisan schedule:work`  |

### A WordPress site

Create an empty folder with this `flight.yaml`:

```yaml
recipe: wordpress
```

Run `flight up`. The first time, Flight downloads WordPress into the folder,
creates `wp-config.php` and installs the site. Log in at
`https://<project>.flght.dev/wp-admin` with `admin` / `admin`.

Later runs skip these steps, so your site is left as it is.

| Recipe option    | Default          | What it is              |
| ---------------- | ---------------- | ----------------------- |
| `title`          | the project name | Site title              |
| `admin_user`     | `admin`          | Administrator username  |
| `admin_password` | `admin`          | Administrator password  |
| `admin_email`    | `admin@flght.dev`| Administrator email     |

WP-CLI is installed in your app's container, together with the MariaDB client for its
database commands:

```bash
flight exec -- wp plugin list
flight exec -- wp db export backup.sql
```

### A WordPress theme or plugin

When your repository is a theme or a plugin, WordPress itself should stay out
of it. Tell Flight where your project belongs inside WordPress with
`project_path`. Flight then keeps WordPress in `.flight/app/data` and mounts your
repository into it:

```yaml
app:
  project_path: wp-content/themes/my-theme   # or wp-content/plugins/my-plugin

recipe: wordpress

provision:
  - name: Activate theme
    run: wp theme activate my-theme
    unless: wp theme is-active my-theme
```

Run `flight up`, and your theme is installed and active in a fresh WordPress
site. You can browse the WordPress files in `.flight/app/data`.

### Sharing a project

Run `flight share` in a running project to show it to someone else, or to
receive webhooks:

```sh
flight share
```

Flight opens a [Cloudflare quick tunnel](https://try.cloudflare.com/) and
shows its URL, such as `https://calm-river-lake.trycloudflare.com`. You don't
need a Cloudflare account or anything installed besides Docker. The URL works
until you press Ctrl+C, and you get a new one each time. Anyone with the URL
can open the project.

Your app still gets requests for its own hostname, such as `myapp.flght.dev`.
Flight replaces that hostname with the public one in redirects, cookies and
the text it sends back, so apps that only know their own URL, such as
WordPress, work without changes. Add `--direct` to send the public hostname to
your app instead, and leave the responses alone.

Quick tunnels are meant for testing: Cloudflare limits them to 200 requests
at a time, and they don't support server-sent events.

## Service reference

### PHP

Runs PHP with a web server, based on
[serversideup/php](https://serversideup.net/open-source/docker-php/), usually
as your [app](#app). Your project is available in the container at
`/var/www/html`.

```yaml
app:
  type: php:8.3
  extensions: [intl]
```

| Option         | Default     | What it is                                         |
| -------------- | ----------- | -------------------------------------------------- |
| `type`         | `php`       | With a version after the colon: `php:8.1` to `php:8.5`. The default is `8.4`. |
| `server`       | `fpm-nginx` | `fpm-nginx`, `fpm-apache` or `frankenphp`          |
| `webroot`      | `public`    | The folder the web server serves; `.` for the root |
| `extensions`   | none        | Extra PHP extensions, such as `[mysqli, gd]`       |
| `packages`     | none        | Extra Debian packages, such as `[git]`             |
| `wp_cli`       | `false`     | Installs [WP-CLI](https://wp-cli.org) as `wp`, with `less` for its help pages |
| `node`         | none        | Installs [Node.js](https://nodejs.org) and npm of this version, such as `"22"`, next to PHP. Quote it, so `"20.10"` isn't read as `20.1`. |
| `workers`      | none        | Background processes on the same image, see [Workers](#workers) |
| `access_log`   | `false`     | Log every request. Off by default, so the logs show what matters; errors are always logged. |
| `project_path` | `.`         | Where your project goes inside the app, such as `modules/my-module`. The app itself is then kept in `.flight/app/data`. |
| `hostnames`    | none        | Extra addresses, see [Hostnames](#hostnames)       |

The container serves HTTPS itself, behind Flight's proxy, so apps such as
Laravel and WordPress see an HTTPS request and create `https://` links without
any configuration.

The image is built with your user and group ID, so files the container creates
in your project belong to you.

### MariaDB

Runs a [MariaDB](https://mariadb.org) database. Other services connect to it
at its name, such as `db`, port `3306`. Its data is kept in a Docker volume, so
it survives `flight down`.

```yaml
services:
  db: mariadb:11.8
```

| Option     | Default  | What it is                               |
| ---------- | -------- | ---------------------------------------- |
| `type`     | `mariadb` | With a version after the colon: `10.6`, `10.11`, `11.4` or `11.8`. The default is `11.8`. |
| `database` | `flight` | The database created on the first start  |
| `user`     | `flight` | A user with access to that database      |
| `password` | `flight` | The password for that user and for `root` |

The database, user and password are only set on the very first start. To start
over with an empty database, run `flight destroy` and then `flight up`.

### Valkey

Runs [Valkey](https://valkey.io), a Redis-compatible store for caches, queues
and sessions. Other services connect to it at its name, such as `cache`, port
`6379`. Its data is kept in a Docker volume, so it survives `flight down`.

```yaml
services:
  cache: valkey:9.1
```

| Option    | Default | What it is                              |
| --------- | ------- | --------------------------------------- |
| `type`    | `valkey` | With a version after the colon: `7.2`, `8.0`, `8.1`, `9.0` or `9.1`. The default is `9.1`. |

Apps that talk to Redis work unchanged. In Laravel, for example, set
`REDIS_HOST=cache`.

### Traefik

The proxy in the Flight stack. You don't add it to a project; it is configured
in the [global configuration](#global-configuration). Its dashboard is at
`https://traefik.flght.dev`.

| Option          | Default                | What it is                         |
| --------------- | ---------------------- | ---------------------------------- |
| `http_port`     | `80`                   | The port on your machine for HTTP  |
| `https_port`    | `443`                  | The port on your machine for HTTPS |
| `docker_socket` | `/var/run/docker.sock` | The Docker socket Traefik watches  |

## Global configuration

Settings that apply to all projects live in `~/.config/flight/config.yaml`.
Open it with `flight stack:config`, and run `flight stack:restart` after
changing it.

```yaml
domain: flght.dev
network: flight

services:
  traefik:
    http_port: 8080
```

| Key        | Default     | What it is                                      |
| ---------- | ----------- | ----------------------------------------------- |
| `domain`   | `flght.dev` | The domain your projects are served under       |
| `network`  | `flight`    | The Docker network projects join                |
| `services` |             | Options for the Flight stack's services, such as [Traefik](#traefik) |

Every `*.<domain>` address must point to `127.0.0.1`. After changing `domain`,
run `flight stack:secure` to create a matching certificate.

The folder holds a few more files:

| Path                    | What it is                                         |
| ----------------------- | -------------------------------------------------- |
| `config.yaml`           | The settings above                                 |
| `.env`                  | [Secrets](#secrets) for all your projects          |
| `compose.override.yaml` | Extra services for the Flight stack, see below     |
| `traefik/`              | Your own Traefik configuration files, loaded automatically |
| `certs/`                | The certificate; managed by Flight                 |
| `compose.yaml`          | The generated Compose file; don't edit it          |

### Adding services to the Flight stack

Services in `~/.config/flight/compose.override.yaml` start and stop with the
Flight stack. This adds [Mailpit](https://mailpit.axllent.org) at
`https://mail.flght.dev`:

```yaml
services:
  mailpit:
    image: axllent/mailpit
    labels:
      traefik.enable: true
      traefik.http.routers.mailpit.rule: "Host(`mail.${FLIGHT_DOMAIN}`)"
      traefik.http.services.mailpit.loadbalancer.server.port: 8025
```

In this file you can use `FLIGHT_DOMAIN`, `FLIGHT_NETWORK`, `FLIGHT_HTTP_PORT`,
`FLIGHT_HTTPS_PORT` and `FLIGHT_DOCKER_SOCK`.

### Custom Traefik configuration

Any `.yaml` or `.yml` file in `~/.config/flight/traefik` is loaded by Traefik
right away, without a restart. Use it for middlewares, or to route to
something outside Docker.

### Projects without a flight.yaml

Any Compose project can use Flight's HTTPS. Join the `flight` network and add
Traefik labels:

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

## Troubleshooting

**Something doesn't start.** Run the command again with `-v` to see Docker's
full output.

**The browser warns about the certificate.** Restart your browser after the
first start, so it picks up mkcert's certificate authority. If that doesn't
help, run `flight stack:secure`.

**Port 80 or 443 is already in use.** Another program is using it. Stop that
program, or pick other ports in the [global configuration](#global-configuration):

```yaml
services:
  traefik:
    http_port: 8080
    https_port: 8443
```

**Your settings are rejected.** Flight checks `flight.yaml` and `config.yaml`
before starting anything. The error names the exact setting, such as
`app.type`, and what it expects.

**You want to try something without touching your setup.** Point Flight at
another configuration folder: `FLIGHT_CONFIG_DIR=/tmp/flight-test flight stack:up`.

## Development

```bash
git clone git@github.com:sitepilot/flight.git
cd flight
composer install
./flight stack:up
```

`./flight` runs straight from the checkout.

```bash
composer test   # run the tests with Pest
composer lint   # format the code with Pint
```

To build a binary:

```bash
php flight app:build flight --build-version=1.0.0
```

`flight self-update` only works for a downloaded release. In a checkout, pull
the repository instead.

### Releasing

Publish a release on GitHub with a tag such as `v1.0.0`. The `Release`
workflow builds the binary and attaches it to the release.

## License

Flight is open-source software licensed under the [MIT license](LICENSE.md).
