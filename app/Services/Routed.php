<?php

declare(strict_types=1);

namespace App\Services;

/**
 * A service the proxy serves over HTTPS, at the label the stack gives it
 * plus any `hostnames` option it declares. It can also be shared with
 * `flight share`.
 */
interface Routed
{
    /**
     * Where the proxy reaches this service on the stack's network, e.g.
     * "https://app:8443". With https, the proxy accepts the container's
     * self-signed certificate.
     */
    public function origin(): string;
}
