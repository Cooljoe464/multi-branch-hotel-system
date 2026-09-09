<?php

namespace App\Notifications;

use App\Models\Reservation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CheckoutNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Reservation $reservation,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $guest = $this->reservation->guest;
        $branch = $this->reservation->branch;

        $name = $guest?->full_name ?? $this->reservation->guest_name;
        $nights = $this->reservation->nights;
        $total = number_format($this->reservation->total_amount / 100, 2);

        return (new MailMessage)
            ->subject("Thank you for staying at {$branch->name}")
            ->greeting("Thank you, {$name}!")
            ->line("We hope you enjoyed your {$nights}-night stay at {$branch->name}.")
            ->line("**Confirmation:** {$this->reservation->confirmation_number}")
            ->line('**Total Amount:** '.($this->reservation->currency_code ?? 'USD')." {$total}")
            ->line('Your folio is ready for review.')
            ->action('View Your Folio', route('guest.folio', $this->reservation->confirmation_number))
            ->line('We would love to hear about your experience. Your feedback helps us improve.')
            ->line("We look forward to welcoming you back to {$branch->name} soon!")
            ->salutation('Warm regards,');
    }
}
