<?php

use App\Contracts\RadiusCoaTransport;
use App\Services\Radius\UdpRadiusCoaTransport;
use App\Services\RadiusService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;

class RecordingRadiusService extends RadiusService
{
    public bool $disabled = false;

    public function disableUser(string $username): bool
    {
        $this->disabled = true;

        return true;
    }
}

class FixedRadiusCoaTransport implements RadiusCoaTransport
{
    /** @var array{host: string, port: int, secret: string, username: string, timeout: int, retries: int}|null */
    public ?array $lastRequest = null;

    public function __construct(private readonly bool $result) {}

    public function disconnect(
        string $host,
        int $port,
        string $sharedSecret,
        string $username,
        int $timeoutSeconds,
        int $retries,
    ): bool {
        $this->lastRequest = [
            'host' => $host,
            'port' => $port,
            'secret' => $sharedSecret,
            'username' => $username,
            'timeout' => $timeoutSeconds,
            'retries' => $retries,
        ];

        return $this->result;
    }
}

function configureRadiusUnitTest(): void
{
    $container = new Application(dirname(__DIR__, 2));
    $container->instance('config', new Repository([
        'radius' => [
            'fake' => false,
            'host' => '100.64.0.1',
            'coa_port' => 3799,
            'timeout_secs' => 1,
            'retries' => 2,
            'shared_secret' => 'test-radius-secret',
        ],
    ]));
    $container->instance('log', Mockery::mock()->shouldIgnoreMissing());
    Container::setInstance($container);
    Facade::setFacadeApplication($container);
}

function coaResponse(string $request, string $secret, int $code, bool $includeMessageAuthenticator = true): string
{
    $attributes = $includeMessageAuthenticator ? pack('CC', 80, 18).str_repeat("\0", 16) : '';
    $length = 20 + strlen($attributes);
    $header = pack('CCn', $code, ord($request[1]), $length);
    $requestAuthenticator = substr($request, 4, 16);
    $packet = $header.$requestAuthenticator.$attributes;

    if ($includeMessageAuthenticator) {
        $messageAuthenticator = hash_hmac('md5', $packet, $secret, true);
        $packet = substr($packet, 0, 22).$messageAuthenticator;
        $attributes = substr($packet, 20);
    }

    $responseAuthenticator = md5($header.$requestAuthenticator.$attributes.$secret, true);

    return $header.$responseAuthenticator.$attributes;
}

function validateCoaResponse(string $response, string $request, string $secret): ?bool
{
    $transport = new UdpRadiusCoaTransport;
    $validator = new ReflectionMethod($transport, 'authenticatedResponse');
    $result = $validator->invoke($transport, $response, $request, $secret);

    if ($result !== null && ! is_bool($result)) {
        throw new LogicException('RADIUS response validator returned an invalid result.');
    }

    return $result;
}

it('accepts a Disconnect-ACK only when its authenticators match', function () {
    $secret = 'radius-test-secret';
    $request = pack('CCn', 40, 23, 20).str_repeat('r', 16);
    $response = coaResponse($request, $secret, 41);

    expect(validateCoaResponse($response, $request, $secret))->toBeTrue()
        ->and(validateCoaResponse($response, $request, 'wrong-secret'))->toBeNull();
});

it('accepts an authenticated disconnect response with Message-Authenticator', function () {
    $secret = 'radius-test-secret';
    $request = pack('CCn', 40, 62, 20).str_repeat('q', 16);

    expect(validateCoaResponse(coaResponse($request, $secret, 41, true), $request, $secret))->toBeTrue();
});

it('rejects a response with a bad Message-Authenticator even if its response authenticator is valid', function () {
    $secret = 'radius-test-secret';
    $request = pack('CCn', 40, 64, 20).str_repeat('m', 16);
    $response = coaResponse($request, $secret, 41, true);
    $response[22] = chr(ord($response[22]) ^ 1);
    $attributes = substr($response, 20);
    $responseAuthenticator = md5(
        substr($response, 0, 4).substr($request, 4, 16).$attributes.$secret,
        true,
    );
    $response = substr($response, 0, 4).$responseAuthenticator.substr($response, 20);

    expect(validateCoaResponse($response, $request, $secret))->toBeNull();
});

it('treats an authenticated Disconnect-NAK as a final rejection', function () {
    $secret = 'radius-test-secret';
    $request = pack('CCn', 40, 91, 20).str_repeat('x', 16);

    expect(validateCoaResponse(coaResponse($request, $secret, 42, false), $request, $secret))->toBeFalse();
});

it('rejects responses with another request identifier', function () {
    $secret = 'radius-test-secret';
    $request = pack('CCn', 40, 17, 20).str_repeat('z', 16);
    $response = coaResponse($request, $secret, 41, false);
    $response[1] = chr(18);

    expect(validateCoaResponse($response, $request, $secret))->toBeNull();
});

it('AC-053 falls back to RADIUS SQL re-authentication when CoA is unavailable', function () {
    configureRadiusUnitTest();
    $transport = new FixedRadiusCoaTransport(false);
    $radius = new RecordingRadiusService($transport);

    expect($radius->disconnectUser('guest-1'))->toBeTrue();
    expect($radius->disabled)->toBeTrue();
    expect($transport->lastRequest)->toBe([
        'host' => '100.64.0.1',
        'port' => 3799,
        'secret' => 'test-radius-secret',
        'username' => 'guest-1',
        'timeout' => 1,
        'retries' => 2,
    ]);
});

it('AC-053 avoids the SQL fallback after CoA disconnect succeeds', function () {
    configureRadiusUnitTest();
    $transport = new FixedRadiusCoaTransport(true);
    $radius = new RecordingRadiusService($transport);

    expect($radius->disconnectUser('guest-1'))->toBeTrue();
    expect($radius->disabled)->toBeFalse();
});
