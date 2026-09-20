<?php

use App\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('branch.{id}', function (User $user, int $id) {
    return $user->hasAccessToBranch(Branch::findOrFail($id));
});
