# ✈️ Flight

Flight is a local development environment for your web projects, built on
Docker. Describe what a project needs in a small `flight.yaml` file, run
`flight up`, and Flight starts your app and its services at a trusted HTTPS
address such as `https://myapp.flght.dev`, ready to work on.

- **Trusted HTTPS, zero setup.** Every project gets its own address with a
  locally trusted certificate. No ports to remember, no hosts file to edit and
  no browser warnings.
- **Recipes for popular apps.** A recipe is a ready-made setup for a kind of
  project, such as Laravel or WordPress. One line in `flight.yaml` gives you
  PHP, a database and background workers, configured to work together.
- **Ready on the first start.** Provisioning steps install dependencies and
  set your project up on `flight up`, even a complete WordPress site, so a
  fresh checkout is ready to work on.
- **Bring your own Docker Compose.** Run an existing compose project as it is,
  or extend Flight's services with compose files of your own.
- **Share in seconds.** `flight share` gives anyone a public URL to your local
  project, for a quick preview or to receive webhooks. No account needed.
- **Plain Docker underneath.** Flight writes normal Compose files, so you can
  always see what runs, and every project stays isolated in its own
  containers.

## Contents

- [Installation](#installation)
    - [Requirements](#requirements)
    - [Installing Flight](#installing-flight)
    - [Your First Project](#your-first-project)
- [How Flight Works](#how-flight-works)
- [Managing Projects](#managing-projects)
    - [Starting and Stopping Projects](#starting-and-stopping-projects)
    - [Running Commands](#running-commands)
    - [Viewing Logs](#viewing-logs)
    - [Sharing Projects](#sharing-projects)
- [Configuring Projects](#configuring-projects)
    - [The App](#the-app)
    - [Recipes](#recipes)
    - [Services](#services)
    - [Workers](#workers)
    - [Hostnames](#hostnames)
    - [Provisioning](#provisioning)
    - [Secrets](#secrets)
    - [Compose Files](#compose-files)
    - [The .flight Directory](#the-flight-directory)
- [Guides](#guides)
    - [Laravel](#laravel)
    - [WordPress](#wordpress)
    - [WordPress Themes and Plugins](#wordpress-themes-and-plugins)
    - [Docker Compose Projects](#docker-compose-projects)
- [Available Services](#available-services)
    - [PHP](#php)
    - [MariaDB](#mariadb)
    - [Valkey](#valkey)
    - [Compose](#compose)
    - [Traefik](#traefik)
- [Global Configuration](#global-configuration)
    - [Adding Services to the Flight Stack](#adding-services-to-the-flight-stack)
    - [Custom Traefik Configuration](#custom-traefik-configuration)
- [Troubleshooting](#troubleshooting)
- [Contributing](#contributing)
- [License](#license)

<a name="installation"></a>
## Installation

<a name="requirements"></a>
### Requirements

- Docker with the Compose plugin
- [mkcert](https://github.com/FiloSottile/mkcert), to create the certificate
  (`mkcert.exe` on Windows when you use WSL)
- PHP 8.4.1 or newer

<a name="installing-flight"></a>
### Installing Flight

Download Flight into a directory on your `PATH`:

```shell
mkdir -p ~/.local/bin
curl -fsSL https://github.com/sitepilot/flight/releases/latest/download/flight -o ~/.local/bin/flight
chmod +x ~/.local/bin/flight
```

Then check that it works:

```shell
flight --version
```

To update Flight to the latest release later on, run:

```shell
flight self-update
```

<a name="your-first-project"></a>
### Your First Project

To get started, create a project directory with a page to serve:

```shell
mkdir -p hello/public
echo '<?php echo "Hello from Flight";' > hello/public/index.php
cd hello
```

Next, add a `flight.yaml` file that runs the project on PHP:

```yaml
app: php:8.4
```

Finally, start the project:

```shell
flight up
```

Your project is now available at `https://hello.flght.dev`. The address comes
from the directory name, and PHP serves the `public` directory by default. For
Laravel and WordPress, a [recipe](#recipes) sets up the app and its services
for you.

> [!NOTE]
> The first time, Flight creates a certificate and asks mkcert to trust it.
> Restart your browser afterwards, so it picks up the new certificate
> authority.

<a name="how-flight-works"></a>
## How Flight Works

Flight runs two kinds of Docker stacks:

- **The Flight stack** runs once for your whole machine. It contains
  [Traefik](https://traefik.io), a proxy that listens on ports 80 and 443 and
  sends each `https://*.flght.dev` request to the right project.
- **A project stack** runs the app and services of one project, such as PHP
  and MariaDB. Its app and other web services join the Flight stack's network,
  so Traefik can reach them, while databases stay private to the project.

The `flight up` command starts the Flight stack when it isn't running yet, and
then the project. Stopping a project leaves the Flight stack running for your
other projects.

Flight writes a normal Docker Compose file for every stack, so you may always
look at what runs and why.

<a name="managing-projects"></a>
## Managing Projects

Project commands may be run from anywhere inside a project. Add `-v` to any
command to see Docker's full output instead of a spinner, which helps when
something fails to start.

<a name="starting-and-stopping-projects"></a>
### Starting and Stopping Projects

The `up` command starts the Flight stack when needed, then the project, and
then runs its [provisioning](#provisioning) steps:

```shell
flight up
```

To stop the project, use the `down` command. The Flight stack keeps running for
your other projects:

```shell
flight down
```

The `restart` command recreates the project's containers, for example after
changing `flight.yaml`:

```shell
flight restart
```

To start over, the `destroy` command removes the project's containers, volumes
and the `.flight` directory, after asking:

```shell
flight destroy
```

<a name="running-commands"></a>
### Running Commands

The `exec` command runs a command in your app's container. Put the command
after `--`, so its options aren't read as Flight's:

```shell
flight exec -- php artisan migrate
flight exec -- composer install --no-dev
```

To run a command in another service, pass the `--service` option:

```shell
flight exec --service=db -- mariadb --version
```

The `exec` command passes on the command's output and exit code, so you may
use it in scripts and pipes.

To open a shell in your app's container, or in the service you name, use the
`shell` command:

```shell
flight shell
flight shell db
```

<a name="viewing-logs"></a>
### Viewing Logs

The `logs` command shows the logs of your app, or of the service you name. Add
`-f` to keep following them, and `--tail` to show only the latest lines:

```shell
flight logs
flight logs -f --tail=100 queue
```

<a name="sharing-projects"></a>
### Sharing Projects

To show a running project to someone else, or to receive webhooks, use the
`share` command:

```shell
flight share
```

Flight opens a [Cloudflare quick tunnel](https://try.cloudflare.com/) and shows
its URL, such as `https://calm-river-lake.trycloudflare.com`. You don't need a
Cloudflare account or anything besides Docker. The URL works until you press
Ctrl+C, and you get a new one each time. To share another service with an
address, name it: `flight share mailpit`.

Your app keeps receiving requests for its own hostname, such as
`myapp.flght.dev`. Flight replaces that hostname with the public one in
redirects, cookies and responses, so apps that only know their own URL, such as
WordPress, work unchanged. Add `--direct` to send the public hostname to your
app and leave responses alone.

> [!WARNING]
> Anyone with the URL can open the project. Quick tunnels are meant for
> testing: Cloudflare limits them to 200 concurrent requests, and server-sent
> events don't work.

<a name="configuring-projects"></a>
## Configuring Projects

Every project has a `flight.yaml` file in its root directory. A `flight.yml`
file works too; when both exist, Flight uses `flight.yaml`.

```yaml
name: shop            # optional, defaults to the directory name

app: php:8.3          # what runs your app
recipe: laravel       # optional, sets up the app and services for Laravel

services:             # what your app uses, such as a database
  db: mariadb:11.8

provision:            # optional, commands to run on `flight up`
  - name: Install dependencies
    run: composer install
```

| Key         | Description |
| ----------- | ----------- |
| `name`      | The project name, and its address: `https://<name>.flght.dev`. Defaults to the directory name. |
| `app`       | What runs your app, see [The App](#the-app) |
| `recipe`    | A preset app and services, see [Recipes](#recipes) |
| `services`  | What your app uses, see [Services](#services) |
| `provision` | Commands to run on every `flight up`, see [Provisioning](#provisioning) |
| `compose`   | Your own compose files, run after Flight's, see [Compose Files](#compose-files) |

A project needs an app, services or a recipe. Flight checks `flight.yaml`
before starting anything, and names the exact setting when something is wrong.

<a name="the-app"></a>
### The App

The `app` key says what runs your app: its type, with a version after the
colon:

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
`https://<project>.flght.dev`. The `exec`, `shell` and `logs` commands use it
unless you name another service. See [PHP](#php) for its options. A project
that already runs with Docker Compose may keep its compose files, see
[Docker Compose Projects](#docker-compose-projects).

<a name="recipes"></a>
### Recipes

A recipe is a ready-made app and services for a kind of project, so you don't
have to list them yourself:

| Recipe      | Description |
| ----------- | ----------- |
| `laravel`   | PHP serving the `public` directory, with an optional queue worker and scheduler, see [Laravel](#laravel) |
| `wordpress` | PHP with WP-CLI and MariaDB, with WordPress installed for you, see [WordPress](#wordpress) |

You may change a recipe's app and services in `flight.yaml`. List only what
you want to be different; everything else stays as the recipe set it:

```yaml
app: php:8.3         # the recipe still serves public/
recipe: laravel

services:
  db: mariadb        # adds a database next to the recipe's app
```

A few rules apply:

- Options are changed one by one. Lists, such as `hostnames`, are replaced as a
  whole.
- You may change and add services, but not remove the recipe's services.
- Options neither the recipe nor you set use their
  [defaults](#available-services).
- To change a recipe's app or service, you don't repeat its `type`. To change
  its version, write the same type with another version, such as
  `app: php:8.3`. Another type, such as `mariadb` for Laravel's app, is an
  error, because the recipe's options wouldn't fit it.

Some recipes have options of their own, which go under the recipe's name:

```yaml
recipe:
  wordpress:
    admin_user: nick
```

<a name="services"></a>
### Services

Each entry under `services` is one container your app uses, such as a database
or a cache. You choose its name, and its type says what it runs, with a version
after the colon:

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

Services reach each other by name, so from your app, the service above is at
the host `db`. The name `app` belongs to your app.

A service may also be an extra PHP container next to your app, served at its
own address, or a service from your own compose files, see
[Docker Compose Projects](#docker-compose-projects):

```yaml
services:
  legacy: php:8.1      # https://<project>-legacy.flght.dev
```

See [Available Services](#available-services) for every service and its
options.

<a name="workers"></a>
### Workers

Workers are background processes of your app, such as a queue worker. Each
runs in a container of its own, on the app's image, with the same files and
settings, so it always matches your app:

```yaml
app:
  type: php:8.4
  workers:
    queue: php artisan queue:work
    scheduler: php artisan schedule:work
```

A worker goes by its own name, such as `queue`, so each name may be used once
in a project, by a service or a worker. It starts and stops with the project,
restarts when it stops, and works with `flight logs queue` and
`flight exec --service=queue`.

> [!NOTE]
> Give a worker a command that keeps running, such as `schedule:work` rather
> than `schedule:run`.

<a name="hostnames"></a>
### Hostnames

Flight gives your app and every other web service an address under
`flght.dev`. Your app gets `https://<project>.flght.dev`, and any other web
service `https://<project>-<service>.flght.dev`. For a project called `shop`
with an extra PHP service `legacy`, that's `shop.flght.dev` and
`shop-legacy.flght.dev`.

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

<a name="provisioning"></a>
### Provisioning

Provisioning steps are commands that set your project up, such as installing
dependencies. They run inside the project's containers on every `flight up`,
right after the project has started. Steps from a recipe run first, then
yours:

```yaml
provision:
  - name: Install dependencies
    run: composer install
    unless: test -d vendor
```

| Key       | Description |
| --------- | ----------- |
| `name`    | A short description, shown while the step runs |
| `service` | Optional. The service to run the command in; defaults to your app |
| `run`     | The shell command to run |
| `unless`  | Optional. A check command; when it succeeds, the step is skipped |
| `dir`     | Optional. The directory to run in, see below |
| `env`     | Optional. Secret variables the step needs, see [Secrets](#secrets) |

Because steps run on every `flight up`, each one should be safe to repeat. Add
an `unless` check to skip a step once its work is done, or use a command that's
harmless to run again. When a step fails, `flight up` stops and shows what went
wrong.

A step runs in the service's working directory, which for `app` is your app.
Use `dir` to run it somewhere else:

```yaml
provision:
  - name: Install tool dependencies
    dir: tools
    run: composer install
```

<a name="secrets"></a>
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

Flight looks for each variable in three places, and uses the first it finds:

1. Your shell, e.g. `export COMPOSER_AUTH=...`
2. The project's `.env` file, which Docker Compose reads too
3. The `~/.config/flight/.env` file, for all your projects

```ini
# ~/.config/flight/.env
COMPOSER_AUTH='{"github-oauth": {"github.com": "your-token"}}'
```

If a variable can't be found, `flight up` stops before starting anything and
tells you where to set it. Inside the step, use the variable as `${NAME}`, or
let a tool read it, as Composer does here. Only the variables a step lists are
read.

> [!WARNING]
> Keep the project's `.env` file out of Git. `flight up` warns when a step
> reads a secret from a `.env` file that Git doesn't ignore.

<a name="compose-files"></a>
### Compose Files

The `compose` key lists compose files of your own. Flight runs its generated
`.flight/compose.yaml` first, and your files after it in this order, as with
`docker compose -f`. So your files may add services and override anything
Flight generates. For example, to mount an extra directory:

```yaml
app: php:8.4

compose:
  - path: compose.override.yml
    required: false
```

```yaml
# compose.override.yml
services:
  app:
    volumes:
      - ./packages/my-package:/var/www/html/vendor/acme/my-package
```

A file with `required: false` is skipped when it doesn't exist, so it may be a
personal, git-ignored override. As with `docker compose -f`, relative paths and
`.env` resolve from the directory of the first file. The header of
`.flight/compose.yaml` lists the files in the order Flight runs them.

Your compose files may also define a whole project, see
[Docker Compose Projects](#docker-compose-projects).

<a name="the-flight-directory"></a>
### The .flight Directory

Flight keeps the files it generates for a project in a `.flight` directory,
which it hides from Git for you:

| Path               | Description |
| ------------------ | ----------- |
| `compose.yaml`     | The generated Docker Compose file; don't edit it, add [compose files](#compose-files) instead |
| `<service>/build/` | Files a service's image is built from |
| `<service>/data/`  | What a service keeps, such as WordPress when you [develop a theme](#wordpress-themes-and-plugins) |

> [!NOTE]
> Tools that scan your whole repository, such as linters, may need `.flight`
> added to their ignore list.

<a name="guides"></a>
## Guides

<a name="laravel"></a>
### Laravel

To run a Laravel app with a database, add a `flight.yaml` file to the project:

```yaml
recipe: laravel

services:
  db: mariadb
```

Then point Laravel's `.env` file at the database:

```ini
DB_CONNECTION=mariadb
DB_HOST=db
DB_DATABASE=flight
DB_USERNAME=flight
DB_PASSWORD=flight
```

Run `flight up` and open `https://<project>.flght.dev`. Artisan and Composer
run in the container:

```shell
flight exec -- php artisan migrate
flight exec -- composer test
```

For queued jobs and scheduled tasks, turn on the recipe's queue worker and
scheduler. They run as [workers](#workers) of your app, start and stop with it,
and pick up code changes by themselves:

```yaml
recipe:
  laravel:
    queue: true
    scheduler: true
```

You may follow their output with `flight logs -f queue` or
`flight logs -f scheduler`.

| Recipe Option | Default | Description |
| ------------- | ------- | ----------- |
| `queue`       | `false` | Adds a `queue` worker running `php artisan queue:listen` |
| `scheduler`   | `false` | Adds a `scheduler` worker running `php artisan schedule:work` |

> [!NOTE]
> Run Vite on your machine with `npm run dev`, where it watches files fastest.
> Set `APP_URL=https://<project>.flght.dev` in `.env`, so Vite lets the site
> load its scripts.

<a name="wordpress"></a>
### WordPress

To create a WordPress site, add a `flight.yaml` file to an empty directory:

```yaml
recipe: wordpress
```

Then run `flight up`. The first time, Flight downloads WordPress into the
directory, creates `wp-config.php` and installs the site. Log in at
`https://<project>.flght.dev/wp-admin` with `admin` / `admin`. Later runs skip
these steps, so your site is left as it is.

| Recipe Option    | Default           | Description |
| ---------------- | ----------------- | ----------- |
| `title`          | the project name  | The site title |
| `admin_user`     | `admin`           | The administrator's username |
| `admin_password` | `admin`           | The administrator's password |
| `admin_email`    | `admin@flght.dev` | The administrator's email address |

WP-CLI is installed in your app's container, together with the MariaDB client
for its database commands:

```shell
flight exec -- wp plugin list
flight exec -- wp db export backup.sql
```

<a name="wordpress-themes-and-plugins"></a>
### WordPress Themes and Plugins

When your repository is a theme or a plugin, WordPress itself should stay out
of it. Tell Flight where your project belongs inside WordPress with
`project_path`. Flight then keeps WordPress in `.flight/app/data`, and mounts
your repository into it:

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
site. You may browse the WordPress files in `.flight/app/data`.

<a name="docker-compose-projects"></a>
### Docker Compose Projects

To run a project that already has compose files, list them under `compose`,
and give each service that should get an address the `compose` type and its
`origin`:

```yaml
app:
  type: compose
  origin: https://app:8443

services:
  mailpit:
    type: compose
    origin: http://mailpit:8025

compose:
  - compose.yml
  - path: compose.override.yml
    required: false
```

Stop the project if it runs with plain Docker Compose, then start it with
Flight:

```shell
docker compose down
flight up
```

Your app is now served at `https://<project>.flght.dev`, and Mailpit at
`https://<project>-mailpit.flght.dev`. Your files run after Flight's generated
file, as described in [Compose Files](#compose-files), also when they're in a
directory such as `.docker`.

The `exec`, `shell` and `logs` commands use the app unless you name another
service from your files:

```shell
flight logs -f db
```

The project runs as `flight-<project>`, apart from plain Docker Compose, so its
named volumes start empty. Run your migrations, or copy a volume once:

```shell
docker run --rm -v myapp_mssql_data:/from -v flight-myapp_mssql_data:/to alpine cp -a /from/. /to/
```

> [!WARNING]
> Use either Flight or plain Docker Compose for a project: both would publish
> the same ports. `flight up` warns when your files also run under another
> project name. `flight destroy` removes your files' volumes too, as
> `docker compose down --volumes` does.

<a name="available-services"></a>
## Available Services

<a name="php"></a>
### PHP

Runs PHP with a web server, based on
[serversideup/php](https://serversideup.net/open-source/docker-php/), usually
as your [app](#the-app). Your project is available in the container at
`/var/www/html`.

```yaml
app:
  type: php:8.3
  extensions: [intl]
```

| Option         | Default     | Description |
| -------------- | ----------- | ----------- |
| `type`         | `php`       | With a version after the colon: `php:8.1` to `php:8.5`. The default is `8.4`. |
| `server`       | `fpm-nginx` | `fpm-nginx`, `fpm-apache` or `frankenphp` |
| `webroot`      | `public`    | The directory the web server serves; `.` for the root |
| `extensions`   | none        | Extra PHP extensions, such as `[mysqli, gd]` |
| `packages`     | none        | Extra Debian packages, such as `[git]` |
| `wp_cli`       | `false`     | Installs [WP-CLI](https://wp-cli.org) as `wp`, with `less` for its help pages |
| `node`         | none        | Installs [Node.js](https://nodejs.org) and npm of this version, such as `"22"`, next to PHP |
| `workers`      | none        | Background processes on the same image, see [Workers](#workers) |
| `access_log`   | `false`     | Logs every request; errors are always logged |
| `project_path` | `.`         | Where your project goes inside the app, such as `modules/my-module`. The app itself is then kept in `.flight/app/data`. |
| `hostnames`    | none        | Extra addresses, see [Hostnames](#hostnames) |

The container serves HTTPS itself, behind Flight's proxy, so apps such as
Laravel and WordPress see an HTTPS request and create `https://` links without
any configuration. The image is built with your user and group ID, so files the
container creates in your project belong to you.

> [!NOTE]
> Quote the `node` version, so `"20.10"` isn't read as the number `20.1`.

<a name="mariadb"></a>
### MariaDB

Runs a [MariaDB](https://mariadb.org) database. Other services connect to it at
its name, such as `db`, on port `3306`. Its data is kept in a Docker volume, so
it survives `flight down`.

```yaml
services:
  db: mariadb:11.8
```

| Option     | Default   | Description |
| ---------- | --------- | ----------- |
| `type`     | `mariadb` | With a version after the colon: `10.6`, `10.11`, `11.4` or `11.8`. The default is `11.8`. |
| `database` | `flight`  | The database created on the first start |
| `user`     | `flight`  | A user with access to that database |
| `password` | `flight`  | The password for that user and for `root` |

The database, user and password are only set on the very first start. To start
over with an empty database, run `flight destroy` and then `flight up`.

<a name="valkey"></a>
### Valkey

Runs [Valkey](https://valkey.io), a Redis-compatible store for caches, queues
and sessions. Other services connect to it at its name, such as `cache`, on
port `6379`. Its data is kept in a Docker volume, so it survives `flight down`.

```yaml
services:
  cache: valkey:9.1
```

| Option | Default  | Description |
| ------ | -------- | ----------- |
| `type` | `valkey` | With a version after the colon: `7.2`, `8.0`, `8.1`, `9.0` or `9.1`. The default is `9.1`. |

Apps that talk to Redis work unchanged. In Laravel, for example, set
`REDIS_HOST=cache`.

<a name="compose"></a>
### Compose

Serves a service from your own compose files, listed under `compose`. Flight
adds the `flight` network and Traefik labels to the service, and leaves the
rest to your files. See [Docker Compose Projects](#docker-compose-projects).

```yaml
app:
  type: compose
  origin: https://app:8443
```

| Option      | Default | Description |
| ----------- | ------- | ----------- |
| `origin`    |         | The URL the proxy connects to: the service name and container port. Use `https://` when the container serves HTTPS itself; its self-signed certificate is accepted. |
| `hostnames` | none    | Extra addresses, see [Hostnames](#hostnames) |

<a name="traefik"></a>
### Traefik

The proxy in the Flight stack. You don't add it to a project; it's configured
in the [global configuration](#global-configuration). Its dashboard is at
`https://traefik.flght.dev`.

| Option          | Default                | Description |
| --------------- | ---------------------- | ----------- |
| `http_port`     | `80`                   | The port on your machine for HTTP |
| `https_port`    | `443`                  | The port on your machine for HTTPS |
| `docker_socket` | `/var/run/docker.sock` | The Docker socket Traefik watches |

<a name="global-configuration"></a>
## Global Configuration

Settings that apply to all projects live in `~/.config/flight/config.yaml`. A
`config.yml` file works too; when both exist, Flight uses `config.yaml`. To
open it in your editor, run:

```shell
flight stack:config
```

```yaml
domain: flght.dev
network: flight

services:
  traefik:
    http_port: 8080
```

| Key        | Default     | Description |
| ---------- | ----------- | ----------- |
| `domain`   | `flght.dev` | The domain your projects are served under |
| `network`  | `flight`    | The Docker network projects join |
| `services` |             | Options for the Flight stack's services, such as [Traefik](#traefik) |
| `compose`  |             | Your own compose files, run after Flight's, see [Adding Services to the Flight Stack](#adding-services-to-the-flight-stack) |

After changing it, restart the Flight stack:

```shell
flight stack:restart
```

Every `*.<domain>` address must point to `127.0.0.1`. After changing `domain`,
create a matching certificate:

```shell
flight stack:secure
```

The Flight stack is also started, stopped and recreated by the `stack:up`,
`stack:down` and `stack:restart` commands. The directory holds your own files,
and a `.flight` directory with what Flight generates, just like a project:

| Path                   | Description |
| ---------------------- | ----------- |
| `config.yaml`          | The settings above |
| `.env`                 | [Secrets](#secrets) for all your projects |
| `traefik/`             | Your own Traefik configuration files, loaded automatically |
| `.flight/compose.yaml` | The generated Compose file; don't edit it |
| `.flight/certs/`       | The certificate, managed by Flight |
| `.flight/share/`       | The files the `flight share` image is built from |

<a name="adding-services-to-the-flight-stack"></a>
### Adding Services to the Flight Stack

Services in your own compose files start and stop with the Flight stack, as
[compose files](#compose-files) do for a project. List them under `compose` in
`config.yaml`, with paths relative to `~/.config/flight`. For example, to add
[Mailpit](https://mailpit.axllent.org) at `https://mail.flght.dev`:

```yaml
# ~/.config/flight/config.yaml
services:
  mail:
    type: compose
    origin: http://mailpit:8025

compose:
  - mailpit.yaml
```

```yaml
# ~/.config/flight/mailpit.yaml
services:
  mailpit:
    image: axllent/mailpit
```

In these files, you may use the `FLIGHT_DOMAIN`, `FLIGHT_NETWORK`,
`FLIGHT_HTTP_PORT`, `FLIGHT_HTTPS_PORT` and `FLIGHT_DOCKER_SOCK` variables.

<a name="custom-traefik-configuration"></a>
### Custom Traefik Configuration

Any `.yaml` or `.yml` file in `~/.config/flight/traefik` is loaded by Traefik
right away, without a restart. You may use it for middlewares, or to route to
something outside Docker.

<a name="troubleshooting"></a>
## Troubleshooting

**Something doesn't start.** Run the command again with `-v` to see Docker's
full output.

**The browser warns about the certificate.** Restart your browser after the
first start, so it picks up mkcert's certificate authority. If that doesn't
help, run `flight stack:secure`.

**Port 80 or 443 is already in use.** Another program is using it. Stop that
program, or pick other ports in the
[global configuration](#global-configuration):

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
another configuration directory:

```shell
FLIGHT_CONFIG_DIR=/tmp/flight-test flight stack:up
```

<a name="contributing"></a>
## Contributing

To work on Flight, clone the repository and install its dependencies. The
`./flight` script runs straight from the checkout:

```shell
git clone git@github.com:sitepilot/flight.git
cd flight
composer install
./flight stack:up
```

Run the tests with Pest, and format the code with Pint:

```shell
composer test
composer lint
```

To build a binary, run:

```shell
php flight app:build flight --build-version=1.0.0
```

The `self-update` command only works for a downloaded release. In a checkout,
pull the repository instead.

To release a new version, publish a release on GitHub with a tag such as
`v1.0.0`. The `Release` workflow builds the binary and attaches it to the
release.

<a name="license"></a>
## License

Flight is open-source software licensed under the [MIT license](LICENSE.md).
