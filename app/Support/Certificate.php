<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\FlightException;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * The locally trusted wildcard certificate, issued with mkcert.
 */
class Certificate
{
    public function __construct(protected GlobalConfig $config) {}

    public function wildcard(): string
    {
        return '*.'.$this->config->domain();
    }

    /**
     * Returns whether a new certificate was issued.
     */
    public function ensure(): bool
    {
        if ($this->isValidFor($this->config->domain())) {
            return false;
        }

        $this->issue();

        return true;
    }

    public function issue(): void
    {
        $binary = $this->binary();

        if (! Executable::exists($binary)) {
            throw FlightException::make(
                "{$binary} was not found in your PATH.",
                $this->isWsl()
                    ? 'Install mkcert on Windows so that mkcert.exe is reachable from WSL: https://github.com/FiloSottile/mkcert'
                    : 'Install it from https://github.com/FiloSottile/mkcert',
            );
        }

        Files::ensureDirectory($this->config->certsDirectory());

        // Under WSL, mkcert.exe can't read Linux paths, so run it from the
        // certs directory with relative file names.
        $result = Process::path($this->config->certsDirectory())
            ->timeout(120)
            ->run([
                $binary,
                '-install',
                '-key-file=ssl.key',
                '-cert-file=ssl.crt',
                '*.'.$this->config->domain(),
            ]);

        if ($result->failed()) {
            throw FlightException::fromProcess(
                $result,
                'Could not issue a certificate for *.'.$this->config->domain().'.',
            );
        }
    }

    public function isValidFor(string $domain): bool
    {
        if (! is_file($this->config->certificateFile()) || ! is_file($this->config->keyFile())) {
            return false;
        }

        // Without ext-openssl, only check that the files exist.
        if (! function_exists('openssl_x509_parse')) {
            return true;
        }

        $contents = @file_get_contents($this->config->certificateFile());

        if ($contents === false) {
            return false;
        }

        $parsed = @openssl_x509_parse($contents);

        if (! is_array($parsed)) {
            return false;
        }

        if (isset($parsed['validTo_time_t']) && $parsed['validTo_time_t'] < time()) {
            return false;
        }

        $names = array_map(
            // The DNS: prefix is optional, so this works either way.
            fn (string $name): string => Str::after(trim($name), 'DNS:'),
            explode(',', (string) ($parsed['extensions']['subjectAltName'] ?? '')),
        );

        return in_array('*.'.$domain, $names, true);
    }

    /**
     * Under WSL, the Windows binary adds the root CA to the Windows trust
     * store.
     */
    public function binary(): string
    {
        return $this->isWsl() ? 'mkcert.exe' : 'mkcert';
    }

    public function isWsl(): bool
    {
        $release = @file_get_contents('/proc/sys/kernel/osrelease');

        return is_string($release) && str_contains(strtolower($release), 'microsoft');
    }
}
