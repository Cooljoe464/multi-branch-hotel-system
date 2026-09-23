<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';

interface Quote {
    offer_id: number;
    kind: string;
    fee_minor: number;
    eligible: boolean;
    reason: string | null;
}

const props = defineProps<{
    reservationId: number;
    quotes: Quote[];
    canGrantFree: boolean;
}>();

const form = useForm({ offer_id: null as number | null, grant_free: false });

function accept(offerId: number, free: boolean) {
    form.offer_id = offerId;
    form.grant_free = free;
    form.post(`/reservations/${props.reservationId}/upsells/accept`, {
        onSuccess: () => {
            form.reset();
            router.reload();
        },
    });
}
</script>

<template>
    <div class="border-border bg-card rounded-lg border p-6">
        <h2 class="text-foreground mb-4 text-lg font-semibold">Upsells</h2>
        <div v-if="!quotes.length" class="text-muted-foreground text-sm">
            No offers configured for this property.
        </div>
        <ul class="space-y-2">
            <li
                v-for="q in quotes"
                :key="q.offer_id"
                class="flex flex-wrap items-center justify-between gap-2 rounded border p-3 text-sm"
            >
                <div>
                    <span class="font-medium">{{
                        q.kind.replace('_', ' ')
                    }}</span>
                    <span class="text-muted-foreground">
                        —
                        {{
                            q.eligible ? `${q.fee_minor} minor` : q.reason
                        }}</span
                    >
                </div>
                <div v-if="q.eligible" class="flex gap-1">
                    <Button size="sm" @click="accept(q.offer_id, false)"
                        >Sell</Button
                    >
                    <Button
                        v-if="canGrantFree"
                        size="sm"
                        variant="outline"
                        @click="accept(q.offer_id, true)"
                        >Grant free</Button
                    >
                </div>
            </li>
        </ul>
    </div>
</template>
