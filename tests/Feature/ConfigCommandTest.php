<?php

use App\Exceptions\FlightException;
use App\Support\Editor;
use Illuminate\Support\Facades\Process;

/**
 * Replace the editor with one that rewrites the file, standing in for a user
 * saving their changes.
 */
function editorWriting(?string $contents): void
{
    $editor = Mockery::mock(Editor::class);
    $editor->shouldReceive('open')->andReturnUsing(function (string $file) use ($contents) {
        if ($contents !== null) {
            file_put_contents($file, $contents);
        }
    });

    app()->instance(Editor::class, $editor);
}

beforeEach(function () {
    flightDirectory();
    Process::fake();
});

it('creates the config file before opening it', function () {
    editorWriting(null);

    $this->artisan('stack:config')->assertExitCode(0);

    expect(flightSettings()->file())->toBeFile();
});

it('opens the config file in the editor', function () {
    $editor = Mockery::mock(Editor::class);
    $editor->shouldReceive('open')->once()->with($this->flightDirectory.'/config.yaml');
    app()->instance(Editor::class, $editor);

    $this->artisan('stack:config')->assertExitCode(0);
});

it('reports when nothing was changed', function () {
    editorWriting(null);

    $this->artisan('stack:config')
        ->expectsOutputToContain('No changes made')
        ->assertExitCode(0);
});

it('validates what was saved', function () {
    editorWriting("domain: flght.dev\nnetwork: flight\nhttp_port: 99999\n");

    $this->artisan('stack:config')->assertExitCode(1);
});

it('accepts a valid edit', function () {
    editorWriting("domain: edited.dev\nnetwork: flight\n");

    $this->artisan('stack:config')->assertExitCode(0);

    expect(flightSettings()->domain())->toBe('edited.dev');
});

it('never touches docker, so it works while the daemon is down', function () {
    editorWriting(null);

    $this->artisan('stack:config')->assertExitCode(0);

    // Editing configuration must not depend on a running Docker.
    Process::assertDidntRun(['docker', 'info']);
});

it('opens a config file that is currently invalid', function () {
    // The whole point: you cannot fix a broken file if the command refuses
    // to open it.
    file_put_contents($this->flightDirectory.'/config.yaml', "http_port: 99999\n");

    editorWriting("domain: flght.dev\nnetwork: flight\nhttp_port: 80\n");

    $this->artisan('stack:config')->assertExitCode(0);
});

it('still reports an invalid file when nothing was changed', function () {
    // Otherwise "No changes made" and a zero exit would imply a broken
    // config is fine.
    file_put_contents($this->flightDirectory.'/config.yaml', "http_port: 99999\n");

    editorWriting(null);

    $this->artisan('stack:config')->assertExitCode(1);
});

it('runs the resolved editor against the file', function () {
    withEditorEnv(null, 'vi', fn () => (new Editor)->open('/tmp/some-config.yaml'));

    Process::assertRan(['vi', '/tmp/some-config.yaml']);
});

it('prefers VISUAL over EDITOR', function () {
    expect(withEditorEnv('vi', 'nano', fn () => (new Editor)->command()))->toBe(['vi']);
});

it('keeps arguments given in EDITOR', function () {
    expect(withEditorEnv(null, 'vi --noplugin', fn () => (new Editor)->command()))->toBe(['vi', '--noplugin']);
});

it('explains when EDITOR points at something missing', function () {
    withEditorEnv(null, 'definitely-not-an-editor', fn () => (new Editor)->command());
})->throws(FlightException::class, 'not found in your PATH');
