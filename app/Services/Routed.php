<?php

declare(strict_types=1);

namespace App\Services;

/**
 * A service the proxy serves over HTTPS, which can also be shared with
 * `flight share`.
 */
interface Routed
{
    /**
     * Where the proxy reaches the service on the stack's network, e.g.
     * "https://app:8443". With https, the self-signed certificate is
     * accepted.
     */
    public function origin(): string;
}
