<?php

namespace App\Models;

use Carbon\Carbon;
use Database\Factories\BrandingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property string $app_name
 * @property string $currency_code
 * @property string $currency_symbol
 * @property string|null $logo_path
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read string|null $logo_url
 */
class Branding extends Model
{
    /** @use HasFactory<BrandingFactory> */
    use HasFactory;

    protected $fillable = [
        'app_name',
        'currency_code',
        'currency_symbol',
        'logo_path',
    ];

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeInstance(Builder $query): Builder
    {
        return $query->where('id', 1);
    }

    public static function instance(): static
    {
        return static::query()->firstOrCreate(
            ['id' => 1],
            ['app_name' => config('app.name', 'Laravel')]
        );
    }

    public function getLogoUrlAttribute(): ?string
    {
        if (! $this->logo_path) {
            return null;
        }

        return Storage::disk('r2')->temporaryUrl(
            $this->logo_path,
            now()->addMinutes(60 * 24 * 7),
        );
    }

    /**
     * @param  UploadedFile|mixed  $file
     */
    public function setLogo(mixed $file): void
    {
        $this->deleteLogo();

        if (! $file instanceof UploadedFile) {
            return;
        }

        $path = $file->store('branding', 'r2');

        $this->update(['logo_path' => $path]);
    }

    public function deleteLogo(): void
    {
        if ($this->logo_path && Storage::disk('r2')->exists($this->logo_path)) {
            Storage::disk('r2')->delete($this->logo_path);
        }

        $this->update(['logo_path' => null]);
    }
}
