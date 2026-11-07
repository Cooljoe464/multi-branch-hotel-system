<?php

namespace App\Notifications;

use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SubscriptionStateNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public string $status;

    public function __construct(Subscription $subscription)
    {
        $this->status = $subscription->status;
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)->subject($this->subject());

        foreach ($this->lines() as $line) {
            $message->line($line);
        }

        return $message->line('Pay the outstanding invoice to restore full service. Guests in house can always check out.');
    }

    private function subject(): string
    {
        return match ($this->status) {
            Subscription::STATUS_PAST_DUE => 'Your HospitalityOS payment is past due',
            Subscription::STATUS_GRACE => 'Your HospitalityOS subscription is in its grace period',
            Subscription::STATUS_RESTRICTED => 'Your HospitalityOS subscription has been restricted',
            default => 'Your HospitalityOS subscription has changed',
        };
    }

    /**
     * @return list<string>
     */
    private function lines(): array
    {
        return match ($this->status) {
            Subscription::STATUS_PAST_DUE => ['An invoice is overdue. Service continues while we wait for payment.'],
            Subscription::STATUS_GRACE => ['The invoice is still unpaid. Premium modules and new reservations will be blocked when the grace period ends.'],
            Subscription::STATUS_RESTRICTED => ['New reservations and premium modules are now blocked. Check-out, folios, keys and Wi-Fi for in-house guests keep working.'],
            default => ['The status of your subscription has changed.'],
        };
    }
}
