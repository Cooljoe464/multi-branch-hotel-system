<?php

namespace App\Services\Sms;

use App\Models\Branch;
use App\Models\Reservation;
use App\Services\UsageMeterService;

/**
 * Universal SMS Service powered strictly and exclusively by Termii.
 */
class SmsService
{
    public function __construct(
        private TermiiSmsDriver $driver,
        private readonly ?UsageMeterService $usage = null,
    ) {}

    /**
     * Send transactional OTP with guaranteed DND delivery.
     *
     * @return array{ok: bool, message_id: ?string, balance: ?float, error: ?string}
     */
    public function sendOtp(string $phone, string $otp, ?Branch $branch = null): array
    {
        $sender = $this->driver->senderId($branch);
        $message = "Your {$sender} verification code is: {$otp}. Valid for 10 minutes.";

        $response = $this->driver->send($phone, $message, $branch, 'dnd');
        if ($response['ok']) {
            $this->recordSms($branch);
        }

        return $response;
    }

    /**
     * Send room keypad access code / digital passcode to guest.
     *
     * @return array{ok: bool, message_id: ?string, balance: ?float, error: ?string}
     */
    public function sendCheckinPasscode(string $phone, string $guestName, string $roomNumber, string $passcode, ?Branch $branch = null): array
    {
        $hotel = $branch?->name ?? 'the hotel';
        $message = "Welcome to {$hotel}, {$guestName}! Your room is {$roomNumber}. Your door entry PIN is {$passcode}. Enjoy your stay!";

        $response = $this->driver->send($phone, $message, $branch, 'dnd');
        if ($response['ok']) {
            $this->recordSms($branch);
        }

        return $response;
    }

    /**
     * Send instant SMS confirmation upon reservation booking.
     *
     * @return array{ok: bool, message_id: ?string, balance: ?float, error: ?string}
     */
    public function sendReservationConfirmation(Reservation $reservation, ?string $checkinLink = null): array
    {
        $guest = $reservation->guest;
        $phone = $guest?->phone;

        if (! $phone) {
            return ['ok' => false, 'message_id' => null, 'balance' => null, 'error' => 'No phone number found for guest.'];
        }

        $branch = $reservation->branch;
        $hotel = $branch->name;
        $ref = $reservation->confirmation_number;
        $checkIn = $reservation->check_in_date->format('M d, Y');

        $message = "Booking confirmed at {$hotel}! Ref: {$ref}. Check-in: {$checkIn}.";
        if ($checkinLink) {
            $message .= " Self check-in online: {$checkinLink}";
        }

        $response = $this->driver->send($phone, $message, $branch, 'dnd');
        if ($response['ok']) {
            $this->recordSms($branch);
        }

        return $response;
    }

    /**
     * General SMS dispatch.
     *
     * @return array{ok: bool, message_id: ?string, balance: ?float, error: ?string}
     */
    public function send(string $phone, string $message, ?Branch $branch = null, string $channel = 'dnd'): array
    {
        $response = $this->driver->send($phone, $message, $branch, $channel);
        if ($response['ok']) {
            $this->recordSms($branch);
        }

        return $response;
    }

    private function recordSms(?Branch $branch, int $count = 1): void
    {
        if ($this->usage === null || $branch === null) {
            return;
        }

        $this->usage->record('sms', $branch->tenant_id, $branch->id, now(), $count);
    }

    /**
     * @return array{ok: bool, balance: float, currency: string, error: ?string}
     */
    public function checkBalance(?Branch $branch = null): array
    {
        return $this->driver->getBalance($branch);
    }
}
