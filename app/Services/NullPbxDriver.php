<?php

namespace App\Services;

use App\Contracts\PbxDriver;
use App\Models\Branch;
use Illuminate\Support\Carbon;

/**
 * Default, vendor-less PBX driver: the placeholder every environment
 * gets until a telephony vendor is configured. It polls nothing and
 * reports itself as unconfigured, so call billing stays exactly where
 * it is — webhook-driven and opt-in per property.
 */
class NullPbxDriver implements PbxDriver
{
    public function name(): string
    {
        return 'null';
    }

    public function isConfigured(Branch $branch): bool
    {
        $secret = $branch->getAttribute('cdr_secret');

        return is_string($secret) && $secret !== '';
    }

    public function poll(Branch $branch, Carbon $since): array
    {
        return [];
    }
}
