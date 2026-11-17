<?php

namespace App\Contracts;

interface RadiusCoaTransport
{
    public function disconnect(
        string $host,
        int $port,
        string $sharedSecret,
        string $username,
        int $timeoutSeconds,
        int $retries,
    ): bool;
}
