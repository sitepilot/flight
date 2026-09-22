<?php

use App\Support\GlobalConfig;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

uses(TestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
|
| Flight reads and writes a configuration directory, so nearly every test
| needs a throwaway one. FLIGHT_CONFIG_DIR is the same seam the CLI exposes
| to users, which keeps tests honest about how the app is really wired.
|
*/

function flightDirectory(): string
{
    $directory = sys_get_temp_dir().'/flight-test-'.bin2hex(random_bytes(6));

    mkdir($directory, 0755, true);

    config(['flight.config_dir' => $directory]);

    test()->flightDirectory = $directory;

    return $directory;
}

function flightConfig(array $settings = []): string
{
    $directory = test()->flightDirectory ?? flightDirectory();

    file_put_contents(
        $directory.'/config.yaml',
        Yaml::dump($settings)
    );

    return $directory;
}

function removeDirectory(string $directory): void
{
    (new Filesystem)->deleteDirectory($directory);
}

/**
 * The application's global configuration, reading the throwaway directory.
 */
function flightSettings(): GlobalConfig
{
    return app(GlobalConfig::class);
}

/**
 * Run a callback with VISUAL/EDITOR set, restoring them afterwards even when
 * an expectation fails.
 */
function withEditorEnv(?string $visual, ?string $editor, Closure $callback): mixed
{
    putenv($visual === null ? 'VISUAL' : "VISUAL={$visual}");
    putenv($editor === null ? 'EDITOR' : "EDITOR={$editor}");

    try {
        return $callback();
    } finally {
        putenv('VISUAL');
        putenv('EDITOR');
    }
}
