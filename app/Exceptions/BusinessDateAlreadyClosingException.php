<?php

namespace App\Exceptions;

use RuntimeException;

class BusinessDateAlreadyClosingException extends RuntimeException
{
    public function __construct(string $message = 'The business date is already being closed.')
    {
        parent::__construct($message);
    }
}
