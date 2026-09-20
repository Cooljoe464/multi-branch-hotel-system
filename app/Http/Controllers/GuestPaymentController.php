<?php

namespace App\Http\Controllers;

use App\Models\Folio;
use App\Models\Reservation;
use App\Services\PaymentService;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GuestPaymentController extends Controller
{
    public function initiate(Request $request, string $confirmationNumber): Response
    {
        $request->validate([
            'amount' => 'required|integer|min:1',
        ]);

        $reservation = Reservation::where('confirmation_number', $confirmationNumber)
            ->where('status', 'checked_in')
            ->with('branch')
            ->firstOrFail();

        $folio = Folio::whereHas('reservation', fn ($q) => $q->where('confirmation_number', $confirmationNumber))
            ->where('status', 'open')
            ->firstOrFail();

        $amount = $request->integer('amount');
        $balance = $folio->outstanding_balance;

        if ($amount > $balance) {
            $amount = $balance;
        }

        $guestEmail = $reservation->guest_email ?? 'guest@hotel.com';

        $callbackUrl = route('guest.payment.callback', [
            'confirmationNumber' => $confirmationNumber,
        ]);

        $paymentService = new PaymentService;
        $paymentData = $paymentService->initializePayment(
            $folio,
            $amount,
            $guestEmail,
            $callbackUrl
        );

        $renderer = new ImageRenderer(
            new RendererStyle(300),
            new SvgImageBackEnd
        );
        $writer = new Writer($renderer);
        $qrCode = $writer->writeString($paymentData['authorization_url']);

        return Inertia::render('guest/Payment', [
            'reservation' => [
                'confirmation_number' => $reservation->confirmation_number,
                'guest_name' => $reservation->guest_name,
            ],
            'payment' => [
                'amount' => $amount,
                'reference' => $paymentData['reference'],
                'authorization_url' => $paymentData['authorization_url'],
                'qr_code' => $qrCode,
            ],
            'folio' => [
                'balance' => $balance,
                'folio_number' => $folio->folio_number,
            ],
        ]);
    }

    public function callback(string $confirmationNumber): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Payment completed successfully. Your folio has been updated.']);

        return redirect()->route('guest.folio', ['confirmationNumber' => $confirmationNumber]);
    }
}
