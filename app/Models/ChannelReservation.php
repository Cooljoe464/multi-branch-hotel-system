<?php

namespace App\Models;

use Database\Factories\ChannelReservationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $channel_provider_id
 * @property int|null $reservation_id
 * @property string $channel_booking_id
 * @property array<string, mixed> $raw_payload
 * @property string $sync_status
 * @property int|null $virtual_card_token_id
 * @property string|null $replay_nonce
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ChannelProviderModel $channelProvider
 * @property-read Reservation|null $reservation
 * @property-read PaymentMethod|null $virtualCard
 */
#[Fillable(['channel_provider_id', 'reservation_id', 'channel_booking_id', 'raw_payload', 'sync_status', 'virtual_card_token_id', 'replay_nonce'])]
class ChannelReservation extends Model
{
    /** @use HasFactory<ChannelReservationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'raw_payload' => 'array',
        ];
    }

    /** @return BelongsTo<ChannelProviderModel, $this> */
    public function channelProvider(): BelongsTo
    {
        return $this->belongsTo(ChannelProviderModel::class, 'channel_provider_id');
    }

    /** @return BelongsTo<Reservation, $this> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /** @return BelongsTo<PaymentMethod, $this> */
    public function virtualCard(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'virtual_card_token_id');
    }
}
