<?php

namespace App\Exceptions;

use RuntimeException;

class AvailabilityException extends RuntimeException
{
    /**
     * @param  list<string>  $unavailableDates
     */
    public function __construct(
        public readonly string $availabilityCode,
        string $message,
        public readonly array $unavailableDates = [],
    ) {
        parent::__construct($message);
    }
}
