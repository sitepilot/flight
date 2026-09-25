<?php

declare(strict_types=1);

namespace App\Services;

/**
 * A service with `x-flight` in the compose files listed under `compose`,
 * served by the proxy. Flight only adds the proxy's labels and network to it.
 */
class ComposeService extends Service implements Routed
{
    protected function defaults(): array
    {
        return [
            'origin' => null,
            'hostnames' => [],
        ];
    }

    protected function rules(): array
    {
        return [
            'origin' => ['required', 'string', 'regex:#^https?://[A-Za-z0-9._-]+:\d{1,5}$#'],
            'hostnames' => ['list'],
            'hostnames.*' => ['string', 'regex:'.self::LABEL],
        ];
    }

    protected function messages(): array
    {
        return [
            'origin.required' => 'Expected where the proxy reaches the service, such as `origin: https://app:8443`.',
            'origin.regex' => 'Expected where the proxy reaches the service, such as `origin: https://app:8443`.',
            'hostnames.*.regex' => 'Expected a lowercase subdomain such as "admin", which becomes admin.'.$this->global->domain().'.',
        ];
    }

    public function origin(): string
    {
        return $this->option('origin');
    }

    public function composeName(): string
    {
        return (string) parse_url($this->origin(), PHP_URL_HOST);
    }

    public function definition(): array
    {
        return [];
    }
}
