import { router, useForm } from '@inertiajs/vue3';

const HEADER = 'X-Idempotency-Key';

function newKey(): string {
    return typeof crypto !== 'undefined' && 'randomUUID' in crypto
        ? crypto.randomUUID()
        : `${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

/**
 * Fresh key for non-Inertia callers (raw fetch) so the strict
 * RequireIdempotencyKey middleware passes.
 */
export function newIdempotencyKey(): string {
    return newKey();
}

/**
 * Attach a fresh X-Idempotency-Key to every mutating Inertia visit so the
 * strict RequireIdempotencyKey middleware passes. Forms that must survive
 * double-clicks with a STABLE key should use useIdempotentForm() instead.
 */
export function initIdempotencyHeader(): void {
    router.on('before', (event) => {
        const visit = (
            event as CustomEvent<{
                visit?: { method?: string; headers?: Record<string, string> };
            }>
        ).detail?.visit;

        if (!visit || visit.method === 'get') {
            return;
        }

        visit.headers = visit.headers ?? {};

        if (!visit.headers[HEADER]) {
            visit.headers[HEADER] = newKey();
        }
    });
}

/**
 * Inertia form with a stable idempotency key for its lifetime: retries and
 * double-clicks replay under the same key instead of posting twice.
 */
export function useIdempotentForm(initial: object) {
    const key = newKey();
    const form = useForm(initial);

    const withKey = { headers: { [HEADER]: key } };

    return { form, idempotencyKey: key, withKey };
}
