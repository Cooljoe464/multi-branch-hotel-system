<?php

namespace App\Concerns;

use App\Exceptions\StaleModelException;
use Illuminate\Database\Eloquent\Model;

/**
 * Optimistic locking via a version column.
 *
 * Readers submit the version they saw; writers fail with
 * StaleModelException (mapped to HTTP 409) when the row moved on.
 * Complements the short pessimistic locks inside posting transactions.
 *
 * @mixin Model
 */
trait HasOptimisticLock
{
    public function initializeHasOptimisticLock(): void
    {
        $version = $this->getAttribute('version');

        if ($version === null) {
            $this->setAttribute('version', 1);
        }
    }

    /**
     * Persist attributes only when the row is still at the expected
     * version, then bump the version atomically.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function saveWithVersion(array $attributes, int $expectedVersion): bool
    {
        $updated = static::where('id', $this->getKey())
            ->where('version', $expectedVersion)
            ->update(array_merge($attributes, ['version' => $expectedVersion + 1]));

        if ($updated === 0) {
            throw new StaleModelException(static::class, $this->getKey(), $expectedVersion);
        }

        $this->fill(array_merge($attributes, ['version' => $expectedVersion + 1]));
        $this->syncOriginal();

        return true;
    }
}
