<?php

namespace App\Contracts;

use App\Models\DoorLockAuditLog;
use App\Models\DoorLockGateway;

interface LockProvider
{
    public function issueKey(DoorLockGateway $gateway, DoorLockAuditLog $log): bool;

    public function revokeKey(DoorLockGateway $gateway, DoorLockAuditLog $log): bool;

    public function extendKey(DoorLockGateway $gateway, DoorLockAuditLog $log): bool;

    public function getProviderName(): string;
}
