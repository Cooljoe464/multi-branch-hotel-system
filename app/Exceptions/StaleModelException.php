<?php

namespace App\Exceptions;

use RuntimeException;

class StaleModelException extends RuntimeException
{
    public function __construct(string $model, mixed $id, int $expectedVersion)
    {
        $label = is_scalar($id) ? (string) $id : get_debug_type($id);

        parent::__construct("{$model} #{$label} changed since version {$expectedVersion}. Reload and retry.");
    }
}
