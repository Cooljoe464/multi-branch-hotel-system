<?php

namespace App\Notifications;

use App\Models\Reservation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CheckInNotification extends Notification implements ShouldQueue
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
        $room = $this->reservation->room;

        $name = $guest->full_name ?? $this->reservation->guest_name;
        $roomNumber = $room->number ?? 'To be assigned';

        return (new MailMessage)
            ->subject("Welcome to {$branch->name} - You're Checked In!")
            ->greeting("Welcome, {$name}!")
            ->line("You're now checked in at {$branch->name}.")
            ->line("**Room Number:** {$roomNumber}")
            ->line("**Check-out Date:** {$this->reservation->check_out_date->format('l, F j, Y')}")
            ->line("**Confirmation:** {$this->reservation->confirmation_number}")
            ->line('Here are some helpful details for your stay:')
            ->line('**Address:** '.($branch->address ?? 'N/A'))
            ->line('**Phone:** '.($branch->phone ?? 'N/A'))
            ->line('**WiFi:** Available in all rooms')
            ->action('View Your Folio', route('guest.folio', $this->reservation->confirmation_number))
            ->salutation('Enjoy your stay!');
    }
}
