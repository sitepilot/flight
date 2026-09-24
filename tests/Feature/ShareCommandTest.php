<?php

use App\Support\Tunnel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    flightDirectory();
    $this->project = flightProject();

    // Never ask Cloudflare's DNS.
    $tunnel = Mockery::mock(Tunnel::class.'[resolves]', [flightSettings()]);
    $tunnel->shouldReceive('resolves')->andReturnTrue();
    app()->instance(Tunnel::class, $tunnel);

    // What `docker compose ps` finds running.
    $this->running = 'abc123';

    // What cloudflared logs.
    $this->tunnelLog = [
        "2026-09-24T07:17:46Z INF Requesting new quick Tunnel on trycloudflare.com...\n",
        "2026-09-24T07:17:50Z INF |  https://calm-river-lake.trycloudflare.com  |\n",
        "2026-09-24T07:17:51Z ERR Connection lost connIndex=0\n",
    ];

    $this->commands = new ArrayObject;

    Process::fake(function ($process) {
        $command = implode(' ', (array) $process->command);
        $this->commands[] = $command;

        if (str_contains($command, ' ps ')) {
            return Process::result($this->running);
        }

        if (str_starts_with($command, 'docker run')) {
            $description = Process::describe()->iterations(count($this->tunnelLog) + 2);

            foreach ($this->tunnelLog as $line) {
                $description->errorOutput($line);
            }

            return $description;
        }

        return Process::result('');
    });
});

function dockerRun(ArrayObject $commands): ?string
{
    return collect($commands)->first(fn (string $command): bool => str_starts_with($command, 'docker run'));
}

it('shares the app through a tunnel on the project network', function () {
    $exitCode = $this->withoutMockingConsoleOutput()->artisan('share');

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('https://calm-river-lake.trycloudflare.com')
        ->toContain('https://myapp.flght.dev')
        ->and(collect($this->commands)->contains(fn ($command) => str_starts_with($command, 'docker build --quiet --tag flight-share')))->toBeTrue()
        ->and(dockerRun($this->commands))->toBe(
            'docker run --rm --name flight-myapp-app-share --network flight-myapp_default '
            .'--env UPSTREAM=https://app:8443 --env LOCAL_HOST=myapp.flght.dev flight-share'
        );
});

it('writes the image for the tunnel to the flight directory', function () {
    $this->artisan('share')->assertExitCode(0);

    $directory = $this->flightDirectory.'/share';

    expect(file_get_contents($directory.'/Dockerfile'))->toContain('FROM nginx:')
        ->toContain('COPY --from=cloudflare/cloudflared:')
        ->and(file_get_contents($directory.'/share.conf.template'))->toContain('proxy_set_header Host ${LOCAL_HOST};')
        ->toContain("sub_filter '//\${LOCAL_HOST}' '//\$host';")
        ->and(file_get_contents($directory.'/entrypoint.sh'))->toContain('exec cloudflared tunnel');
});

it('sends the public hostname to the app with --direct', function () {
    $this->artisan('share', ['--direct' => true])->assertExitCode(0);

    expect(dockerRun($this->commands))->toContain('--env UPSTREAM=https://app:8443')
        ->not->toContain('LOCAL_HOST');
});

it('shows the errors cloudflared logs, but not its routine output', function () {
    $this->withoutMockingConsoleOutput()->artisan('share');

    expect(Artisan::output())->toContain('ERR Connection lost')
        ->not->toContain('Requesting new quick Tunnel');
});

it('refuses to share a project that is not running', function () {
    $this->running = '';

    $exitCode = $this->withoutMockingConsoleOutput()->artisan('share');

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('The project is not running.')
        ->and(dockerRun($this->commands))->toBeNull();
});

it('refuses to share a service without a url', function () {
    file_put_contents($this->project.'/flight.yaml', "app:\n  type: php\nservices:\n  db:\n    type: mariadb\n");

    $exitCode = $this->withoutMockingConsoleOutput()->artisan('share', ['service' => 'db']);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('The "db" service has no URL to share.');
});

it('explains when the service is already shared', function () {
    $this->tunnelLog = ["docker: Error response from daemon: Conflict. The container name \"/flight-myapp-app-share\" is already in use.\n"];

    $exitCode = $this->withoutMockingConsoleOutput()->artisan('share');

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('The service is already shared.');
});

it('explains why the tunnel did not open', function () {
    $this->tunnelLog = [
        "2026-09-24T07:17:46Z INF Requesting new quick Tunnel on trycloudflare.com...\n",
        "2026-09-24T07:17:47Z ERR failed to request quick Tunnel: 429 Too Many Requests\n",
    ];

    $exitCode = $this->withoutMockingConsoleOutput()->artisan('share');

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('Could not open the tunnel.')
        ->toContain('429 Too Many Requests');
});
