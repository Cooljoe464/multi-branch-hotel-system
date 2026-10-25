<?php

namespace App\Services\Accounting;

use App\Exceptions\AvailabilityException;

class AccountingDriverManager
{
    public function __construct(private AccountingOAuthManager $oauth) {}

    public function driver(string $provider): AccountingExporter
    {
        return match ($provider) {
            'fake' => new FakeAccountingDriver,
            'xero' => new XeroDriver($this->oauth),
            'quickbooks' => new QuickBooksDriver($this->oauth),
            'sage' => new SageDriver($this->oauth),
            default => throw new AvailabilityException('ACCOUNTING_PROVIDER', "Unknown accounting provider {$provider}."),
        };
    }
}
