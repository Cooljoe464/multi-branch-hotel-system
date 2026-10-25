<?php

namespace App\Services;

use App\Contracts\PbxDriver;
use App\Exceptions\AvailabilityException;
use App\Models\Branch;

/**
 * Resolves the configured PBX driver for a property. PBX_DRIVER stays
 * 'null' until a vendor is picked; adding a vendor means registering
 * its class here and shipping credentials — the billing pipeline it
 * feeds (PbxService) does not change.
 */
class PbxDriverManager
{
    /**
     * @var array<string, class-string<PbxDriver>>
     */
    private const DRIVERS = [
        'null' => NullPbxDriver::class,
    ];

    public function driver(Branch $branch): PbxDriver
    {
        $name = config('services.pbx.driver');
        $name = is_string($name) && $name !== '' ? $name : 'null';

        $class = self::DRIVERS[$name] ?? null;

        if ($class === null) {
            throw new AvailabilityException(
                'PBX_UNCONFIGURED',
                "Unknown PBX driver [{$name}]. Known drivers: ".implode(', ', array_keys(self::DRIVERS)).'.',
            );
        }

        return new $class;
    }
}
