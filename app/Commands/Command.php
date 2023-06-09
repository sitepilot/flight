<?php

namespace App\Commands;

use App\Repositories\ConfigRepository;
use Illuminate\Support\Facades\File;
use LaravelZero\Framework\Commands\Command as BaseCommand;
use Symfony\Component\Process\Process;

abstract class Command extends BaseCommand
{
    protected ConfigRepository $config;

    protected bool $ignoreValidationErrors = false;

    public function __construct(
        ConfigRepository $config,
    )
    {
        parent::__construct();

        if ($this->ignoreValidationErrors) {
            $this->ignoreValidationErrors();
        }

        $this->config = $config;
    }

    public function localCmd(array $command, int $timeout = 0): Process
    {
        return (new Process($command))
            ->setTty(Process::isTtySupported())
            ->setTimeout($timeout);
    }

    public function remoteCmd(array|string $command, int $timeout = 0): Process
    {
        if (is_array($command)) $command = implode(" ", $command);

        $remotePath = $this->config->get('path') . str_replace($this->config->path(), '', getcwd());

        $command = [
            'ssh', '-t', '-o', 'LogLevel=QUIET', '-o', 'ServerAliveInterval=60', '-p', $this->config->get('port', 22),
            $this->config->get('user') . '@' . $this->config->get('host'),
            "cd $remotePath ; $command"
        ];

        return (new Process($command, $localWorkdir ?? null))
            ->setTty(Process::isTtySupported())
            ->setTimeout($timeout);
    }

    public function composeCmd(array $command, int $timeout = 0): Process
    {
        $this->assertComposeProject();

        return $this->remoteCmd(array_merge([
            'docker', 'compose', 'exec', '-u',
            $this->config->get('container.user', 'root'),
            $this->config->get('container.name')
        ], $command), $timeout);
    }

    public function isWSL(): bool
    {
        return !empty(getenv('WSL_DISTRO_NAME'));
    }

    public function isComposeProject(): bool
    {
        return File::exists($this->config->path('docker-compose.yml'));
    }

    public function assertComposeProject(): void
    {
        if (!$this->isComposeProject()) {
            $this->abort('It looks like this project doesn\'t contain a Docker Compose file.');
        }
    }

    public function shouldRunInContainer(): bool
    {
        return $this->isComposeProject() && $this->config->get('container.name');
    }

    public function isLaravelProject(): bool
    {
        return File::exists($this->config->path('artisan'));
    }

    public function assertLaravelProject(): void
    {
        if (!$this->isLaravelProject()) {
            $this->abort('It looks like this isn\'t a Laravel project.');
        }
    }

    public function isWordPressProject(): bool
    {
        return File::exists($this->config->path('wp-config.php'));
    }

    public function assertWordPressProject(): void
    {
        if (!$this->isWordPressProject()) {
            $this->abort('It looks like this isn\'t a WordPress project.');
        }
    }

    public function abort(string $message, int $code = 1)
    {
        $this->config->abort($message, $code);
    }
}
