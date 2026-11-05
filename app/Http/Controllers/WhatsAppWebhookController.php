<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessWhatsAppDeliveryStatusJob;
use App\Jobs\ProcessWhatsAppWebhookJob;
use App\Models\Branch;
use App\Services\WhatsAppCloudApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Meta WhatsApp Cloud API webhook. GET completes the subscription
 * challenge with the branch verify token; POST validates
 * X-Hub-Signature-256 against the branch app secret, then queues each
 * message. Meta retries on non-2xx, so unrouteable payloads are logged
 * and acknowledged.
 */
class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request, WhatsAppCloudApiService $whatsapp): Response
    {
        $mode = $this->query($request, 'hub_mode', 'hub.mode');
        $token = $this->query($request, 'hub_verify_token', 'hub.verify_token');
        $challenge = $this->query($request, 'hub_challenge', 'hub.challenge');

        if ($mode !== 'subscribe' || $token === null || $challenge === null) {
            return response('Invalid verification request.', 403);
        }

        if (! $whatsapp->findBranchByVerifyToken($token) instanceof Branch) {
            Log::warning('WhatsApp webhook verification rejected: unknown verify token.');

            return response('Unknown verify token.', 403);
        }

        return response($challenge, 200)->header('Content-Type', 'text/plain');
    }

    public function store(Request $request, WhatsAppCloudApiService $whatsapp): JsonResponse
    {
        $decoded = json_decode($request->getContent(), true);

        if (! is_array($decoded)) {
            return response()->json(['message' => 'Invalid payload.'], 400);
        }

        $entries = is_array($decoded['entry'] ?? null) ? $decoded['entry'] : [];
        $signature = $request->header('X-Hub-Signature-256');
        $signature = is_string($signature) ? $signature : null;

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $changes = is_array($entry['changes'] ?? null) ? $entry['changes'] : [];

            foreach ($changes as $change) {
                if (! is_array($change)) {
                    continue;
                }

                $value = $change['value'] ?? null;

                if (! is_array($value)) {
                    continue;
                }

                $metadata = $value['metadata'] ?? null;
                $phoneNumberId = is_array($metadata) ? ($metadata['phone_number_id'] ?? null) : null;

                if (! is_string($phoneNumberId) || $phoneNumberId === '') {
                    continue;
                }

                $branch = $whatsapp->findBranchByPhoneNumberId($phoneNumberId);

                if (! $branch instanceof Branch) {
                    Log::info('WhatsApp webhook for unknown phone number.', ['phone_number_id' => $phoneNumberId]);

                    continue;
                }

                if (! $whatsapp->verifySignature($branch, $request->getContent(), $signature)) {
                    return response()->json(['message' => 'Invalid signature.'], 401);
                }

                $messages = $this->normalizeMessages($value['messages'] ?? null);

                if ($messages === []) {
                    continue;
                }

                $inbound = [];
                $statuses = [];

                foreach ($messages as $message) {
                    $statusValue = $message['status'] ?? null;
                    $statusName = is_string($statusValue) ? $statusValue : (is_array($statusValue) ? (is_string($statusValue['status'] ?? null) ? $statusValue['status'] : null) : null);

                    if ($statusName !== null) {
                        $statuses[] = [
                            'message_id' => is_string($statusValue['message_id'] ?? null) ? $statusValue['message_id'] : (is_string($message['id'] ?? null) ? $message['id'] : null),
                            'status' => $statusName,
                        ];

                        continue;
                    }

                    $inbound[] = $message;
                }

                $contacts = is_array($value['contacts'] ?? null) ? $value['contacts'] : [];
                $contact = $this->normalizeContact($contacts[0] ?? null);

                if ($inbound !== []) {
                    ProcessWhatsAppWebhookJob::dispatch($branch->id, $inbound, $contact);
                }

                if ($statuses !== []) {
                    ProcessWhatsAppDeliveryStatusJob::dispatch($branch->id, $statuses);
                }
            }
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeMessages(mixed $messages): array
    {
        if (! is_array($messages)) {
            return [];
        }

        $normalized = [];

        foreach ($messages as $message) {
            if (! is_array($message)) {
                continue;
            }

            $row = [];

            foreach ($message as $key => $value) {
                if (is_string($key)) {
                    $row[$key] = $value;
                }
            }

            $normalized[] = $row;
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeContact(mixed $contact): array
    {
        if (! is_array($contact)) {
            return [];
        }

        $normalized = [];

        foreach ($contact as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    private function query(Request $request, string $underscored, string $dotted): ?string
    {
        $value = $request->query($underscored) ?? $request->query($dotted);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
