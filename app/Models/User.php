<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Activitylog\Models\Concerns\CausesActivity;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property int|null $branch_id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property bool $is_global_admin
 * @property Carbon|null $gdpr_consent_at
 * @property string|null $gdpr_consent_version
 * @property Carbon|null $last_login_at
 * @property string|null $last_login_ip
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Branch|null $currentBranch
 * @property-read Collection<Branch> $branches
 */
#[Fillable(['name', 'email', 'password', 'branch_id', 'is_global_admin'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use CausesActivity;

    use HasFactory;
    use HasRoles;
    use LogsActivity;
    use Notifiable;
    use PasskeyAuthenticatable;
    use SoftDeletes;
    use TwoFactorAuthenticatable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'is_global_admin' => 'boolean',
            'gdpr_consent_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'is_global_admin'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function currentBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'user_branch')
            ->withPivot('is_default')
            ->withTimestamps();
    }

    public function defaultBranch(): ?Branch
    {
        return $this->branches()
            ->wherePivot('is_default', true)
            ->first();
    }

    public function hasAccessToBranch(Branch $branch): bool
    {
        if ($this->is_global_admin) {
            return true;
        }

        return $this->branches()->where('branches.id', $branch->id)->exists();
    }

    public function scopeGlobalAdmin(Builder $query): Builder
    {
        return $query->where('is_global_admin', true);
    }

    public function scopeForBranch(Builder $query, int $branchId): Builder
    {
        return $query->where('branch_id', $branchId);
    }
}
