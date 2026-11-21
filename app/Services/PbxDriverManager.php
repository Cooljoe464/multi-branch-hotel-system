<?php

namespace App\Services;

use App\Contracts\PbxDriver;
use App\Exceptions\AvailabilityException;
use App\Models\Branch;
use App\Services\Pbx\AsteriskAmiPbxDriver;
use App\Services\Pbx\GrandstreamUcmPbxDriver;
use App\Services\Pbx\ThreeCxPbxDriver;

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
        '3cx' => ThreeCxPbxDriver::class,
        'asterisk' => AsteriskAmiPbxDriver::class,
        'grandstream' => GrandstreamUcmPbxDriver::class,
    ];

    public function driver(Branch $branch): PbxDriver
    {
        $name = $branch->settings['pbx_driver'] ?? config('services.pbx.driver');
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
