<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Maps a journal event to its debit/credit chart accounts. Editable only
 * through the accounting permissions; the journal validates against it.
 *
 * @property int $id
 * @property string $event
 * @property string $debit_account
 * @property string $credit_account
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'event',
    'debit_account',
    'credit_account',
])]
class PostingRule extends Model
{
    //
}
