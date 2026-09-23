<?php

use App\Support\Certificate;

/**
 * Issue a self-signed certificate for the given SANs without mkcert.
 */
function writeCertificate(array $domains, int $days = 30): void
{
    $config = flightSettings();
    $config->scaffold();

    $key = openssl_pkey_new(['private_key_bits' => 2048]);

    $csr = openssl_csr_new(
        ['commonName' => $domains[0]],
        $key,
        ['req_extensions' => 'v3_req', 'digest_alg' => 'sha256']
    );

    $conf = tempnam(sys_get_temp_dir(), 'flight-openssl-');
    file_put_contents($conf, "[v3_req]\nsubjectAltName=".implode(',', array_map(
        fn (string $domain): string => 'DNS:'.$domain,
        $domains
    ))."\n");

    $certificate = openssl_csr_sign($csr, null, $key, $days, [
        'config' => $conf,
        'x509_extensions' => 'v3_req',
        'digest_alg' => 'sha256',
    ]);

    openssl_x509_export($certificate, $pem);
    openssl_pkey_export($key, $keyPem);

    file_put_contents($config->certificateFile(), $pem);
    file_put_contents($config->keyFile(), $keyPem);

    unlink($conf);
}

beforeEach(function () {
    flightDirectory();
});

it('treats a missing certificate as invalid', function () {

    expect(app(Certificate::class)->isValidFor('flght.dev'))->toBeFalse();
});

it('accepts a wildcard certificate for the configured domain', function () {
    writeCertificate(['*.flght.dev']);

    expect(app(Certificate::class)->isValidFor('flght.dev'))->toBeTrue();
});

it('rejects a certificate issued for a different domain', function () {
    writeCertificate(['*.other.dev']);

    expect(app(Certificate::class)->isValidFor('flght.dev'))->toBeFalse();
});

it('rejects an expired certificate', function () {

    $config = flightSettings();
    $config->scaffold();

    // A fixture, because OpenSSL 3.0 can't backdate a certificate. Its SAN
    // covers *.flght.dev, so only the expiry fails.
    copy(__DIR__.'/../Fixtures/expired.crt', $config->certificateFile());
    file_put_contents($config->keyFile(), 'not read when the dates fail');

    expect(app(Certificate::class)->isValidFor('flght.dev'))->toBeFalse();
});

it('rejects a non wildcard certificate for the domain', function () {
    writeCertificate(['flght.dev']);

    expect(app(Certificate::class)->isValidFor('flght.dev'))->toBeFalse();
});

it('does not reissue when the certificate is already valid', function () {
    writeCertificate(['*.flght.dev']);

    // Issuing would run mkcert, which fails in tests.
    expect(app(Certificate::class)->ensure())->toBeFalse();
});

it('picks the mkcert binary for the platform', function (bool $wsl, string $binary) {
    $certificate = Mockery::mock(Certificate::class.'[isWsl]', [flightSettings()]);
    $certificate->shouldReceive('isWsl')->andReturn($wsl);

    expect($certificate->binary())->toBe($binary);
})->with([
    'wsl' => [true, 'mkcert.exe'],
    'native' => [false, 'mkcert'],
]);
