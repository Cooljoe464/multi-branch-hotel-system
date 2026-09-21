<?php

namespace App\Exceptions;

/**
 * A rate restriction rejected a booking. Carries a machine code plus the
 * offending dates so the UI can highlight nights. Rendered as 422
 * (403 for OVERBOOK-style permission failures via the parent mapping).
 */
class RestrictionViolation extends AvailabilityException
{
    /**
     * @param  list<string>  $unavailableDates
     */
    public function __construct(string $availabilityCode, string $message, array $unavailableDates = [])
    {
        parent::__construct($availabilityCode, $message, $unavailableDates);
    }
}
