<?php

namespace App\Services\Accounting;

use App\Exceptions\AvailabilityException;

class AccountingDriverManager
{
    public function driver(string $provider): AccountingExporter
    {
        return match ($provider) {
            'fake' => new FakeAccountingDriver,
            'xero' => new XeroDriver,
            'quickbooks' => new QuickBooksDriver,
            'sage' => new SageDriver,
            default => throw new AvailabilityException('ACCOUNTING_PROVIDER', "Unknown accounting provider {$provider}."),
        };
    }
}
