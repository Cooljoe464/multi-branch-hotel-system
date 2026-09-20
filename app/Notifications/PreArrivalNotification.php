<?php

namespace App\Notifications;

use App\Models\Reservation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PreArrivalNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Reservation $reservation,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $guest = $this->reservation->guest;
        $branch = $this->reservation->branch;
        $roomType = $this->reservation->roomType;

        $name = $guest->full_name ?? $this->reservation->guest_name;

        return (new MailMessage)
            ->subject("Your upcoming stay at {$branch->name}")
            ->greeting("Hello {$name}!")
            ->line("We're excited to welcome you to {$branch->name} on {$this->reservation->check_in_date->format('l, F j, Y')}.")
            ->line("**Room Type:** {$roomType->name}")
            ->line("**Check-in:** {$this->reservation->check_in_date->format('l, F j, Y')}")
            ->line("**Check-out:** {$this->reservation->check_out_date->format('l, F j, Y')}")
            ->line("**Confirmation:** {$this->reservation->confirmation_number}")
            ->line('You can complete your digital check-in 24 hours before arrival to save time at the front desk.')
            ->action('Digital Check-In', route('guest.checkin', $this->reservation->confirmation_number))
            ->line('If you have any special requests, please don\'t hesitate to contact us.')
            ->line('**Phone:** '.($branch->phone ?? 'N/A'))
            ->line('**Email:** '.($branch->email ?? 'N/A'))
            ->salutation('We look forward to your stay!');
    }
}
