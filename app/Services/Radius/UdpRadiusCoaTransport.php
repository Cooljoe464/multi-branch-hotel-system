<?php

namespace App\Services\Radius;

use App\Contracts\RadiusCoaTransport;

class UdpRadiusCoaTransport implements RadiusCoaTransport
{
    public function disconnect(
        string $host,
        int $port,
        string $sharedSecret,
        string $username,
        int $timeoutSeconds,
        int $retries,
    ): bool {
        $packet = $this->request($username, $sharedSecret);
        $attempts = max(1, $retries);
        $socket = @stream_socket_client(
            "udp://{$host}:{$port}",
            $errorCode,
            $errorMessage,
            max(1, $timeoutSeconds),
            STREAM_CLIENT_CONNECT,
        );

        if (! is_resource($socket)) {
            return false;
        }

        try {
            stream_set_timeout($socket, max(1, $timeoutSeconds));
            for ($attempt = 0; $attempt < $attempts; $attempt++) {
                if (@fwrite($socket, $packet) !== strlen($packet)) {
                    continue;
                }

                $response = @fread($socket, 4096);
                $metadata = stream_get_meta_data($socket);
                if (! is_string($response) || $response === '' || $metadata['timed_out']) {
                    continue;
                }

                $accepted = $this->authenticatedResponse($response, $packet, $sharedSecret);
                if ($accepted !== null) {
                    return $accepted;
                }
            }
        } finally {
            fclose($socket);
        }

        return false;
    }

    private function request(string $username, string $sharedSecret): string
    {
        $identifier = random_int(0, 255);
        $attributes = $this->attribute(1, $username).$this->attribute(80, str_repeat("\0", 16));
        $length = 20 + strlen($attributes);
        $packet = pack('CCn', 40, $identifier, $length).str_repeat("\0", 16).$attributes;
        $messageAuthenticator = hash_hmac('md5', $packet, $sharedSecret, true);
        $packet = substr($packet, 0, -16).$messageAuthenticator;
        $requestAuthenticator = md5(
            substr($packet, 0, 4).str_repeat("\0", 16).substr($packet, 20).$sharedSecret,
            true,
        );

        return substr($packet, 0, 4).$requestAuthenticator.substr($packet, 20);
    }

    private function authenticatedResponse(string $response, string $request, string $sharedSecret): ?bool
    {
        if (strlen($response) < 20 || ord($response[1]) !== ord($request[1])) {
            return null;
        }

        $length = unpack('nlength', substr($response, 2, 2))['length'] ?? 0;
        if (! is_int($length) || $length < 20 || $length > strlen($response)) {
            return null;
        }

        $attributes = substr($response, 20, $length - 20);
        $offset = 0;
        $messageAuthenticatorOffset = null;
        while ($offset < strlen($attributes)) {
            if (strlen($attributes) - $offset < 2) {
                return null;
            }

            $attributeLength = ord($attributes[$offset + 1]);
            if ($attributeLength < 2 || $offset + $attributeLength > strlen($attributes)) {
                return null;
            }

            if (ord($attributes[$offset]) === 80) {
                if ($attributeLength !== 18 || $messageAuthenticatorOffset !== null) {
                    return null;
                }
                $messageAuthenticatorOffset = 20 + $offset + 2;
            }

            $offset += $attributeLength;
        }

        $requestAuthenticator = substr($request, 4, 16);
        $responseAuthenticator = substr($response, 4, 16);
        $expectedResponseAuthenticator = md5(
            substr($response, 0, 4).$requestAuthenticator.$attributes.$sharedSecret,
            true,
        );

        if (! hash_equals($expectedResponseAuthenticator, $responseAuthenticator)) {
            return null;
        }

        if ($messageAuthenticatorOffset === null) {
            return ord($response[0]) === 41;
        }

        $messageAuthenticator = substr($response, $messageAuthenticatorOffset, 16);
        $authenticatedPacket = substr($response, 0, 4)
            .$requestAuthenticator
            .substr($response, 20, $length - 20);
        $relativeOffset = $messageAuthenticatorOffset;
        $authenticatedPacket = substr_replace($authenticatedPacket, str_repeat("\0", 16), $relativeOffset, 16);

        if (! hash_equals(hash_hmac('md5', $authenticatedPacket, $sharedSecret, true), $messageAuthenticator)) {
            return null;
        }

        return ord($response[0]) === 41;
    }

    private function attribute(int $type, string $value): string
    {
        return pack('CC', $type, strlen($value) + 2).$value;
    }
}
