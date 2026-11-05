<?php

namespace App\Services;

use App\Events\ConciergeReplied;
use App\Events\HandoffRequested;
use App\Exceptions\AvailabilityException;
use App\Models\Branch;
use App\Models\ConciergeAction;
use App\Models\ConciergeMessage;
use App\Models\ConciergeThread;
use App\Models\Consent;
use App\Models\Guest;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\UpsellOffer;
use App\Models\User;
use App\Services\Concierge\Ai\ConciergeAiProvider;
use App\Services\Concierge\ConciergeDriver;
use App\Services\Concierge\ConciergeIntent;
use App\Services\Concierge\HeuristicConciergeDriver;
use App\Services\Concierge\ModelConciergeDriver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * WhatsApp concierge orchestrator. Inbound webhook turns are stored,
 * grounded against branch knowledge, answered with an AI disclosure,
 * and handed to a human whenever confidence is low, sentiment is
 * negative, or the guest asks for money to move. Draft actions are
 * never applied here — staff approval calls the owning service.
 */
class ConciergeService
{
    public const AI_DISCLOSURE = 'AI assistant';

    public const HANDOFF_REPLY = 'I have passed this to our front desk team and someone will reply here shortly.';

    private const OPT_OUT_PATTERN = '/\b(?:stop|stop messaging|stop messages|unsubscribe|unsubscribe me|opt ?out|optout|end marketing)\b/i';

    public function __construct(
        private readonly WhatsAppCloudApiService $whatsapp,
        private readonly ConciergeDriver $driver,
    ) {}

    public static function make(?ConciergeAiProvider $provider = null): self
    {
        return new self(new WhatsAppCloudApiService, self::driver($provider));
    }

    private static function driver(?ConciergeAiProvider $provider): ConciergeDriver
    {
        if ($provider !== null) {
            return new ModelConciergeDriver($provider);
        }

        $manager = app(ConciergeAiManager::class);

        return $manager->isConfigured()
            ? new ModelConciergeDriver($manager->provider())
            : new HeuristicConciergeDriver;
    }

    /**
     * Process one Meta message object. Returns the stored guest message
     * (or null when it is a redelivery/unsupported payload).
     *
     * @param  array<string, mixed>  $message
     * @param  array<string, mixed>  $contact
     */
    public function handleInbound(Branch $branch, array $message, array $contact = []): ?ConciergeMessage
    {
        $from = $message['from'] ?? null;

        if (! is_string($from) || $from === '') {
            return null;
        }

        $waMessageId = isset($message['id']) && is_string($message['id']) ? $message['id'] : null;

        if ($waMessageId !== null && ConciergeMessage::where('whatsapp_message_id', $waMessageId)->exists()) {
            return null;
        }

        $body = $this->extractBody($message);

        $thread = $this->threadFor($branch, $from, $contact);
        $this->linkIdentity($thread, $from);

        $inbound = $thread->messages()->create([
            'role' => ConciergeMessage::ROLE_GUEST,
            'body' => $body,
            'whatsapp_message_id' => $waMessageId,
            'metadata' => ['type' => $message['type'] ?? 'text'],
        ]);

        $thread->update(['last_message_at' => $inbound->created_at ?? now()]);

        if ($thread->status === ConciergeThread::STATUS_CLOSED) {
            $thread->update([
                'status' => ConciergeThread::STATUS_ACTIVE,
                'handoff_at' => null,
            ]);
        }

        if (! $this->whatsapp->isEnabled($branch)) {
            $this->requestHandoff($thread, 'ai_disabled');

            return $inbound;
        }

        if ($thread->status === ConciergeThread::STATUS_HANDOFF) {
            return $inbound;
        }

        if ($this->isOptOut($body)) {
            $this->handleOptOut($thread);

            return $inbound;
        }

        $intent = $this->driver->interpret($thread, $body);

        try {
            $this->executeIntent($thread, $intent, $body);
        } catch (\Throwable $e) {
            Log::warning('Concierge reply failed; handing off.', [
                'branch_id' => $branch->id,
                'thread_id' => $thread->id,
                'error' => $e->getMessage(),
            ]);

            $this->requestHandoff($thread, 'reply_failed', self::HANDOFF_REPLY);
        }

        return $inbound;
    }

    /**
     * @param  array<string, mixed>  $contact
     */
    private function threadFor(Branch $branch, string $waId, array $contact): ConciergeThread
    {
        $thread = ConciergeThread::firstOrCreate(
            ['branch_id' => $branch->id, 'whatsapp_wa_id' => $waId],
            [
                'channel' => ConciergeThread::CHANNEL_WHATSAPP,
                'status' => ConciergeThread::STATUS_ACTIVE,
                'phone_number_id' => $this->whatsapp->phoneNumberId($branch),
            ],
        );

        $profile = $contact['profile'] ?? null;
        $profileName = is_array($profile) ? ($profile['name'] ?? null) : null;
        $metadata = $thread->metadata ?? [];

        if (is_string($profileName) && $profileName !== '' && ($metadata['profile_name'] ?? null) !== $profileName) {
            $metadata['profile_name'] = $profileName;
            $thread->update(['metadata' => $metadata]);
        }

        return $thread;
    }

    private function handleOptOut(ConciergeThread $thread): void
    {
        if ($thread->guest_id !== null) {
            Consent::query()
                ->where('guest_id', $thread->guest_id)
                ->where('channel', 'whatsapp')
                ->where('purpose', 'marketing')
                ->update(['granted' => false, 'at' => now()]);
        }

        $this->sendAssistant(
            $thread,
            'You have opted out of marketing messages from our hotel. Reply "agent" anytime to talk to our team.',
        );
    }

    private function isOptOut(string $body): bool
    {
        $normalized = mb_strtolower(trim((string) preg_replace('/\s+/', ' ', $body) ?? ''));

        return preg_match(self::OPT_OUT_PATTERN, $normalized) === 1;
    }

    private function linkIdentity(ConciergeThread $thread, string $waId): void
    {
        if ($thread->reservation_id !== null) {
            return;
        }

        $digits = preg_replace('/\D+/', '', $waId) ?? '';
        $suffix = substr($digits, -10);

        if ($suffix === '') {
            return;
        }

        $guest = Guest::query()
            ->where('phone', 'like', '%'.$suffix.'%')
            ->first();

        $reservation = Reservation::query()
            ->where('branch_id', $thread->branch_id)
            ->whereIn('status', ['reserved', 'confirmed', 'checked_in'])
            ->where(function ($query) use ($suffix, $guest) {
                $query->where('guest_phone', 'like', '%'.$suffix.'%');

                if ($guest instanceof Guest) {
                    $query->orWhere('guest_id', $guest->id);
                }
            })
            ->orderByDesc('check_in_date')
            ->first();

        $patch = [];

        if ($guest instanceof Guest) {
            $patch['guest_id'] = $guest->id;
        }

        if ($reservation instanceof Reservation) {
            $patch['reservation_id'] = $reservation->id;

            if ($reservation->guest_id !== null) {
                $patch['guest_id'] = $reservation->guest_id;
            }
        }

        if ($patch !== []) {
            $thread->update($patch);
        }
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function extractBody(array $message): string
    {
        $type = $message['type'] ?? 'text';
        $type = is_string($type) ? $type : 'unknown';

        if ($type === 'text') {
            $text = $message['text'] ?? null;
            $body = is_array($text) ? ($text['body'] ?? null) : null;

            return is_string($body) && trim($body) !== '' ? trim($body) : '[empty message]';
        }

        if ($type === 'interactive') {
            $interactive = $message['interactive'] ?? null;
            $buttonReply = is_array($interactive) ? ($interactive['button_reply'] ?? null) : null;
            $listReply = is_array($interactive) ? ($interactive['list_reply'] ?? null) : null;
            $body = is_array($buttonReply) ? ($buttonReply['title'] ?? null) : null;
            $body ??= is_array($listReply) ? ($listReply['title'] ?? null) : null;

            return is_string($body) && trim($body) !== '' ? trim($body) : '[interactive reply]';
        }

        if ($type === 'button') {
            $button = $message['button'] ?? null;
            $body = is_array($button) ? ($button['text'] ?? null) : null;

            return is_string($body) && trim($body) !== '' ? trim($body) : '[button reply]';
        }

        return '['.$type.' received]';
    }

    private function executeIntent(ConciergeThread $thread, ConciergeIntent $intent, string $rawBody): void
    {
        match ($intent->intent) {
            ConciergeIntent::FAQ => $this->answerFaq($thread, $intent),
            ConciergeIntent::RATE => $this->answerRate($thread),
            ConciergeIntent::BOOKING => $this->answerBooking($thread),
            ConciergeIntent::LATE_CHECKOUT => $this->requestLateCheckout($thread, $intent),
            ConciergeIntent::MAINTENANCE => $this->logMaintenance($thread, $intent, $rawBody),
            ConciergeIntent::CONFIRM => $this->confirmPendingAction($thread),
            ConciergeIntent::DECLINE => $this->declinePendingAction($thread),
            ConciergeIntent::GREETING => $this->sendAssistant(
                $thread,
                'Hello! I can help with Wi-Fi, breakfast, check-out times, your booking, or a late checkout. How can I help?',
            ),
            default => $this->requestHandoff($thread, $intent->handoffReason ?? 'low_confidence', self::HANDOFF_REPLY),
        };
    }

    private function answerFaq(ConciergeThread $thread, ConciergeIntent $intent): void
    {
        $entry = $intent->entry;

        if ($entry === null) {
            $this->requestHandoff($thread, 'low_confidence', self::HANDOFF_REPLY);

            return;
        }

        $this->sendAssistant(
            $thread,
            $entry->answer,
            [['name' => 'kb_lookup', 'kb_entry_id' => $entry->id, 'topic' => $entry->topic]],
        );
    }

    private function answerRate(ConciergeThread $thread): void
    {
        $reservation = $thread->reservation;

        if (! $reservation instanceof Reservation) {
            $this->requestHandoff(
                $thread,
                'identity_required',
                'Please share your confirmation number and I can quote your stay exactly.',
            );

            return;
        }

        $branch = Branch::find($thread->branch_id);

        if (! $branch instanceof Branch) {
            $this->requestHandoff($thread, 'branch_missing');

            return;
        }

        $plan = $reservation->rate_plan_id !== null ? RatePlan::find($reservation->rate_plan_id) : null;
        $roomType = RoomType::find($reservation->room_type_id);

        if (! $plan instanceof RatePlan || ! $roomType instanceof RoomType) {
            $this->requestHandoff($thread, 'rate_context_missing', self::HANDOFF_REPLY);

            return;
        }

        try {
            $quote = (new RateEngine)->price(
                $branch,
                $plan,
                $roomType,
                $reservation->check_in_date->toDateString(),
                $reservation->check_out_date->toDateString(),
            );
        } catch (AvailabilityException $e) {
            $this->requestHandoff($thread, 'rate_unavailable', $e->getMessage());

            return;
        }

        $nights = count($quote['nights']);
        $amount = $this->formatMinor($quote['total_minor'], $quote['currency']);

        $this->sendAssistant(
            $thread,
            "Your current stay total is {$amount} for {$nights} night(s) on the {$plan->code} rate. This is our live rate quote.",
            [['name' => 'quote_rate', 'plan_code' => $plan->code, 'total_minor' => $quote['total_minor']]],
        );
    }

    private function answerBooking(ConciergeThread $thread): void
    {
        $reservation = $thread->reservation;

        if (! $reservation instanceof Reservation) {
            $this->requestHandoff(
                $thread,
                'identity_required',
                'Please share your confirmation number so I can look up your booking.',
            );

            return;
        }

        $roomModel = $reservation->room;
        $room = $roomModel instanceof Room ? $roomModel->number : 'not assigned yet';
        $balance = max(0, (int) $reservation->total_amount - (int) $reservation->amount_paid);

        $this->sendAssistant(
            $thread,
            "Booking {$reservation->confirmation_number} is {$reservation->status}. "
            ."Check-in {$reservation->check_in_date->format('d M Y')}, check-out {$reservation->check_out_date->format('d M Y')}. "
            ."Room: {$room}. Outstanding balance: ".$this->formatMinor($balance, (string) ($reservation->currency_code ?? 'NGN')).'.',
            [['name' => 'get_booking', 'reservation_id' => $reservation->id]],
        );
    }

    private function requestLateCheckout(ConciergeThread $thread, ConciergeIntent $intent): void
    {
        $reservation = $thread->reservation;
        $branch = Branch::find($thread->branch_id);

        if (! $reservation instanceof Reservation || ! $branch instanceof Branch) {
            $this->requestHandoff(
                $thread,
                'identity_required',
                'Please share your confirmation number and I can check late checkout for you.',
            );

            return;
        }

        $offer = UpsellOffer::forBranch($branch->id)
            ->active()
            ->where('kind', UpsellOffer::KIND_LATE_CHECKOUT)
            ->orderBy('id')
            ->first();

        if (! $offer instanceof UpsellOffer) {
            $this->sendAssistant($thread, 'Late checkout is not offered at this property right now.');

            return;
        }

        $quotes = (new UpsellService)->quote($branch, $reservation);
        $quote = null;

        foreach ($quotes as $candidate) {
            if ($candidate['offer_id'] === $offer->id) {
                $quote = $candidate;
                break;
            }
        }

        if ($quote === null || $quote['eligible'] !== true) {
            $reason = is_array($quote) && is_string($quote['reason'] ?? null)
                ? $quote['reason']
                : 'Late checkout is not available for this stay.';

            $this->sendAssistant($thread, $reason);

            return;
        }

        $key = 'concierge.'.$thread->id.'.'.ConciergeAction::TYPE_LATE_CHECKOUT;
        $action = ConciergeAction::firstOrCreate(
            ['idempotency_key' => $key],
            [
                'concierge_thread_id' => $thread->id,
                'reservation_id' => $reservation->id,
                'type' => ConciergeAction::TYPE_LATE_CHECKOUT,
                'status' => ConciergeAction::STATUS_PENDING_GUEST_CONFIRM,
                'payload' => [
                    'offer_id' => $offer->id,
                    'fee_minor' => $quote['fee_minor'],
                    'kind' => $offer->kind,
                ],
            ],
        );

        if (in_array($action->status, [ConciergeAction::STATUS_REJECTED, ConciergeAction::STATUS_EXPIRED], true)) {
            $action->update([
                'status' => ConciergeAction::STATUS_PENDING_GUEST_CONFIRM,
                'payload' => [
                    'offer_id' => $offer->id,
                    'fee_minor' => $quote['fee_minor'],
                    'kind' => $offer->kind,
                ],
            ]);
        }

        $amount = $this->formatMinor($quote['fee_minor'], (string) ($reservation->currency_code ?? 'NGN'));
        $this->sendAssistant(
            $thread,
            "Late checkout is available for {$amount}. Reply YES and our front desk will confirm it.",
            [['name' => 'request_late_checkout', 'action_id' => $action->id, 'fee_minor' => $quote['fee_minor']]],
        );

        $this->requestHandoff($thread, 'money_action');
    }

    private function logMaintenance(ConciergeThread $thread, ConciergeIntent $intent, string $rawBody): void
    {
        $note = is_string($intent->args['note'] ?? null) ? $intent->args['note'] : $rawBody;

        $action = ConciergeAction::firstOrCreate(
            ['idempotency_key' => 'concierge.'.$thread->id.'.'.ConciergeAction::TYPE_MAINTENANCE],
            [
                'concierge_thread_id' => $thread->id,
                'reservation_id' => $thread->reservation_id,
                'type' => ConciergeAction::TYPE_MAINTENANCE,
                'status' => ConciergeAction::STATUS_GUEST_CONFIRMED,
                'guest_confirmed_at' => now(),
                'payload' => [
                    'note' => $note,
                    'room_id' => $thread->reservation?->room_id,
                ],
            ],
        );

        $this->sendAssistant(
            $thread,
            'Thanks for letting us know — I have logged a maintenance request for the team.',
            [['name' => 'log_maintenance', 'action_id' => $action->id]],
        );
    }

    private function confirmPendingAction(ConciergeThread $thread): void
    {
        $action = $thread->actions()->pending()->latest('id')->first();

        if (! $action instanceof ConciergeAction) {
            $this->sendAssistant($thread, 'There is nothing waiting for your confirmation right now.');

            return;
        }

        $action->update([
            'status' => ConciergeAction::STATUS_GUEST_CONFIRMED,
            'guest_confirmed_at' => now(),
        ]);

        $this->sendAssistant(
            $thread,
            'Thank you — our front desk team will apply this now.',
            [['name' => 'confirm_action', 'action_id' => $action->id]],
        );

        if ($action->type !== ConciergeAction::TYPE_MAINTENANCE) {
            $this->requestHandoff($thread, 'money_action');
        }
    }

    private function declinePendingAction(ConciergeThread $thread): void
    {
        $action = $thread->actions()->pending()->latest('id')->first();

        if (! $action instanceof ConciergeAction) {
            return;
        }

        $action->update(['status' => ConciergeAction::STATUS_REJECTED]);

        $this->sendAssistant($thread, 'No problem — I have cancelled that request.');
    }

    /**
     * Staff reply from the agent inbox.
     */
    public function replyAsAgent(ConciergeThread $thread, string $body, User $by): ConciergeMessage
    {
        $thread->update([
            'status' => ConciergeThread::STATUS_HANDOFF,
            'handoff_at' => $thread->handoff_at ?? now(),
            'assigned_to' => $by->id,
            'last_message_at' => now(),
        ]);

        $message = $thread->messages()->create([
            'role' => ConciergeMessage::ROLE_AGENT,
            'body' => $body,
        ]);

        $thread->update(['last_message_at' => $message->created_at ?? now()]);

        $this->deliver($thread, $body);
        event(new ConciergeReplied($message));

        return $message;
    }

    public function takeOver(ConciergeThread $thread, User $by): ConciergeThread
    {
        $thread->update([
            'status' => ConciergeThread::STATUS_HANDOFF,
            'handoff_at' => $thread->handoff_at ?? now(),
            'assigned_to' => $by->id,
        ]);

        return $thread;
    }

    public function handBack(ConciergeThread $thread): ConciergeThread
    {
        $thread->update([
            'status' => ConciergeThread::STATUS_ACTIVE,
            'handoff_at' => null,
        ]);

        return $thread;
    }

    public function close(ConciergeThread $thread): ConciergeThread
    {
        $thread->update(['status' => ConciergeThread::STATUS_CLOSED]);

        return $thread;
    }

    public function markRead(ConciergeThread $thread): void
    {
        $thread->messages()
            ->where('role', ConciergeMessage::ROLE_GUEST)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    /**
     * Apply a guest-confirmed draft through the owning service. Only a
     * staff member with concierge.reply reaches this method.
     */
    public function applyAction(ConciergeAction $action, User $by): ConciergeAction
    {
        if ($action->status === ConciergeAction::STATUS_APPLIED) {
            return $action;
        }

        if ($action->status !== ConciergeAction::STATUS_GUEST_CONFIRMED) {
            throw new AvailabilityException('CONCIERGE_ACTION', 'The guest has not confirmed this request yet.');
        }

        $thread = $action->thread;
        $branch = Branch::find($thread->branch_id);

        if (! $branch instanceof Branch) {
            throw new AvailabilityException('CONCIERGE_ACTION', 'The conversation is missing.');
        }

        DB::transaction(function () use ($action, $branch, $by) {
            if ($action->type === ConciergeAction::TYPE_LATE_CHECKOUT) {
                $reservation = $action->reservation;

                if (! $reservation instanceof Reservation) {
                    throw new AvailabilityException('CONCIERGE_ACTION', 'The reservation is missing.');
                }

                $offerId = $action->payload['offer_id'] ?? null;
                $offer = is_numeric($offerId) ? UpsellOffer::find((int) $offerId) : null;

                if (! $offer instanceof UpsellOffer) {
                    $offer = UpsellOffer::forBranch($branch->id)
                        ->active()
                        ->where('kind', UpsellOffer::KIND_LATE_CHECKOUT)
                        ->orderBy('id')
                        ->first();
                }

                if (! $offer instanceof UpsellOffer) {
                    throw new AvailabilityException('CONCIERGE_ACTION', 'The late checkout offer is no longer available.');
                }

                (new UpsellService)->accept($reservation, $offer, $by, 'concierge:action:'.$action->id);
            }

            if ($action->type === ConciergeAction::TYPE_MAINTENANCE) {
                $note = $action->payload['note'] ?? 'Guest reported an issue via WhatsApp.';
                $roomId = $action->payload['room_id'] ?? null;

                (new MaintenanceService)->raise($branch, [
                    'title' => 'Guest maintenance report',
                    'description' => is_string($note) ? $note : 'Guest reported an issue via WhatsApp.',
                    'category' => 'other',
                    'priority' => 'normal',
                    'room_id' => is_numeric($roomId) ? (int) $roomId : null,
                ], $by);
            }

            $action->update([
                'status' => ConciergeAction::STATUS_APPLIED,
                'applied_by' => $by->id,
                'applied_at' => now(),
            ]);
        }, 3);

        return $action->fresh() ?? $action;
    }

    public function rejectAction(ConciergeAction $action): ConciergeAction
    {
        if ($action->status !== ConciergeAction::STATUS_APPLIED) {
            $action->update(['status' => ConciergeAction::STATUS_REJECTED]);
        }

        return $action->fresh() ?? $action;
    }

    public function canSendMarketing(Guest $guest): bool
    {
        return Consent::granted($guest->id, 'whatsapp', 'marketing');
    }

    /**
     * Marketing sends require an explicit opt-in. Service replies are
     * never blocked by marketing consent.
     */
    public function sendMarketing(ConciergeThread $thread, string $body): bool
    {
        $guest = $thread->guest;

        if (! $guest instanceof Guest || ! $this->canSendMarketing($guest)) {
            Log::info('WhatsApp marketing skipped: no consent.', ['thread_id' => $thread->id]);

            return false;
        }

        $branch = Branch::find($thread->branch_id);

        if (! $branch instanceof Branch) {
            return false;
        }

        $this->deliver($thread, $body);

        return true;
    }

    /**
     * @param  array<int, array<string, mixed>>  $toolCalls
     */
    private function sendAssistant(ConciergeThread $thread, string $body, array $toolCalls = []): ConciergeMessage
    {
        $final = $this->withDisclosure($body);

        $message = $thread->messages()->create([
            'role' => ConciergeMessage::ROLE_ASSISTANT,
            'body' => $final,
            'tool_calls' => $toolCalls === [] ? null : $toolCalls,
        ]);

        $thread->update(['last_message_at' => $message->created_at ?? now()]);

        $this->deliver($thread, $final);
        event(new ConciergeReplied($message));

        return $message;
    }

    public function handOff(ConciergeThread $thread, string $reason): ConciergeThread
    {
        if ($thread->status === ConciergeThread::STATUS_HANDOFF) {
            return $thread;
        }

        $thread->update([
            'status' => ConciergeThread::STATUS_HANDOFF,
            'handoff_at' => now(),
        ]);

        event(new HandoffRequested($thread, $reason));

        return $thread;
    }

    private function requestHandoff(ConciergeThread $thread, string $reason, ?string $reply = null): ?ConciergeMessage
    {
        if ($thread->status !== ConciergeThread::STATUS_HANDOFF) {
            $this->handOff($thread, $reason);
        }

        return $reply !== null ? $this->sendAssistant($thread, $reply, [['name' => 'handoff', 'reason' => $reason]]) : null;
    }

    private function deliver(ConciergeThread $thread, string $body): void
    {
        $branch = Branch::find($thread->branch_id);

        if (! $branch instanceof Branch || ! $this->whatsapp->isConfigured($branch)) {
            return;
        }

        try {
            $this->whatsapp->sendText($branch, $thread->whatsapp_wa_id, $body);
        } catch (\Throwable $e) {
            Log::warning('WhatsApp delivery failed.', [
                'thread_id' => $thread->id,
                'error' => $e->getMessage(),
            ]);

            $this->handOff($thread, 'delivery_failed');
        }
    }

    private function withDisclosure(string $body): string
    {
        if (str_contains($body, self::AI_DISCLOSURE)) {
            return $body;
        }

        return $body."\n\n— ".self::AI_DISCLOSURE.'. Reply “agent” to talk to our team.';
    }

    private function formatMinor(int $minor, string $currency): string
    {
        return number_format($minor / 100, 2).' '.$currency;
    }
}
