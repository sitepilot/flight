<?php

use App\Exceptions\FlightException;
use App\Provisioning\Provisioner;
use App\Stacks\ProjectStack;

beforeEach(function () {
    flightDirectory();
});

/**
 * The commands of the wordpress recipe's steps, keyed by step name.
 */
function wordpressSteps(array $options = []): array
{
    flightProject(['recipe' => $options === [] ? 'wordpress' : ['wordpress' => $options]]);

    $steps = [];

    foreach (app(Provisioner::class)->steps(app(ProjectStack::class)) as $step) {
        $steps[$step->name] = $step;
    }

    return $steps;
}

it('runs php with wp-cli and mariadb', function () {
    flightProject(['recipe' => 'wordpress']);

    $services = app(ProjectStack::class)->project()->services();

    expect($services['php'])->toMatchArray(['webroot' => '.', 'wp_cli' => true])
        ->and($services['php']['extensions'])->toContain('mysqli')
        ->and($services['mariadb'])->toMatchArray(['type' => 'mariadb', 'database' => 'wordpress']);
});

it('downloads, configures and installs wordpress, each once', function () {
    $steps = wordpressSteps();

    expect(array_keys($steps))->toBe(['Download WordPress', 'Configure WordPress', 'Install WordPress'])
        ->and($steps['Download WordPress']->unless)->toBe('test -f wp-load.php')
        ->and($steps['Configure WordPress']->unless)->toBe('test -f wp-config.php')
        ->and($steps['Install WordPress']->unless)->toBe('wp core is-installed');
});

it('connects wordpress to the mariadb service', function () {
    $command = wordpressSteps()['Configure WordPress']->command;

    expect($command)->toStartWith("wp config create --dbhost='mariadb' --dbname='wordpress' --dbuser='wordpress' --dbpass='wordpress' --extra-php <<'PHP'")
        ->and($command)->toContain("\$_SERVER['HTTPS'] = 'on';");
});

it('installs wordpress at the project url with the default admin', function () {
    $command = wordpressSteps()['Install WordPress']->command;

    expect($command)->toContain("--url='https://myapp.flght.dev'")
        ->toContain("--title='myapp'")
        ->toContain("--admin_user='admin'")
        ->toContain("--admin_email='admin@flght.dev'");
});

it('takes the admin and title from its options, quoted for the shell', function () {
    $command = wordpressSteps(['title' => "Nick's Shop", 'admin_user' => 'nick'])['Install WordPress']->command;

    expect($command)->toContain("--title='Nick'\\''s Shop'")
        ->toContain("--admin_user='nick'");
});

it('rejects an invalid admin email', function () {
    wordpressSteps(['admin_email' => 'not an email']);
})->throws(FlightException::class, 'Invalid "recipe.wordpress.admin_email"');
