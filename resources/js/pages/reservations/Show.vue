<script setup lang="ts">
import { Head, router, Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import MoveDialog from '@/components/MoveDialog.vue';
import UpsellPanel from '@/components/UpsellPanel.vue';
import { ArrowLeft } from '@lucide/vue';
import { formatDate, formatDateTime } from '@/lib/dates';
import { formatCurrency as formatCurrencyRaw } from '@/lib/format';

interface Room {
    id: number;
    number: string;
    floor: string;
}

interface RoomType {
    id: number;
    name: string;
    code: string;
}

interface Branch {
    id: number;
    name: string;
}

interface UpsellQuote {
    offer_id: number;
    kind: string;
    fee_minor: number;
    eligible: boolean;
    reason: string | null;
}

interface HotspotTier {
    id: number;
    name: string;
    code: string;
    price_minor: number;
}

interface HotspotSelection {
    hotspot_tier_id: number;
    fee_minor: number;
    tier?: HotspotTier | null;
}

interface Reservation {
    id: number;
    confirmation_number: string;
    guest_name: string;
    guest_email: string | null;
    guest_phone: string | null;
    guest_notes: string | null;
    adults: number;
    children: number;
    check_in_date: string;
    check_out_date: string;
    actual_check_in_at: string | null;
    actual_check_out_at: string | null;
    status: string;
    room_rate: number;
    total_amount: number;
    amount_paid: number;
    currency_code?: string | null;
    payment_status: string;
    special_requests: string[] | null;
    room: Room | null;
    room_type: RoomType;
    branch: Branch;
    created_at: string;
}

const props = withDefaults(
    defineProps<{
        reservation: Reservation;
        upsells?: UpsellQuote[];
        can_grant_free_upsell?: boolean;
        hotspotTiers?: HotspotTier[];
        hotspotSelection?: HotspotSelection | null;
        can_select_hotspot?: boolean;
    }>(),
    {
        upsells: () => [],
        can_grant_free_upsell: false,
        hotspotTiers: () => [],
        hotspotSelection: null,
        can_select_hotspot: false,
    },
);

const selectedTier = ref<string>(
    props.hotspotSelection?.hotspot_tier_id
        ? String(props.hotspotSelection.hotspot_tier_id)
        : '',
);

const saveTier = () => {
    if (!selectedTier.value) return;
    router.post(`/reservations/${props.reservation.id}/hotspot-tier`, {
        hotspot_tier_id: Number(selectedTier.value),
    });
};

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Reservations', href: '/reservations' },
            { title: 'Details', href: '/reservations' },
        ],
    },
});

const page = usePage();
const branchSymbol = computed(
    () => (page.props.branch?.current as any)?.currency_symbol || '$',
);
const recordCurrencyCode = computed(
    () => props.reservation.currency_code || 'NGN',
);
const currencySymbol = computed(() => {
    const code = recordCurrencyCode.value;
    const symbols: Record<string, string> = {
        NGN: '₦',
        USD: '$',
        EUR: '€',
        GBP: '£',
        CAD: 'C$',
        AUD: 'A$',
        SGD: 'S$',
        INR: '₹',
        AED: 'د.إ',
    };
    return symbols[code] || branchSymbol.value;
});
const formatCurrency = (amount: number, decimals = 2) =>
    formatCurrencyRaw(amount, currencySymbol.value, decimals);

const statusBadgeVariant: Record<string, string> = {
    pending:
        'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300',
    confirmed:
        'bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-300',
    reserved:
        'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-300',
    checked_in:
        'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300',
    checked_out: 'bg-muted text-muted-foreground',
    cancelled: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300',
};

const checkIn = () => {
    if (confirm('Check in this reservation?')) {
        router.post(`/reservations/${props.reservation.id}/check-in`);
    }
};

const checkOut = () => {
    if (confirm('Check out this reservation?')) {
        router.post(`/reservations/${props.reservation.id}/check-out`);
    }
};

const cancel = () => {
    if (confirm('Cancel this reservation?')) {
        router.post(`/reservations/${props.reservation.id}/cancel`);
    }
};

const goBack = () => {
    window.history.back();
};

const showMove = ref(false);
</script>

<template>
    <div class="mx-auto max-w-4xl p-4 md:p-6">
        <div
            class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
        >
            <div class="flex items-center gap-3">
                <Button
                    variant="ghost"
                    size="sm"
                    @click="goBack()"
                    class="text-muted-foreground hover:text-foreground gap-1"
                >
                    <ArrowLeft class="size-4" /> Back
                </Button>
                <div>
                    <h1 class="text-foreground text-2xl font-bold">
                        {{ reservation.confirmation_number }}
                    </h1>
                    <p class="text-muted-foreground">
                        Guest: {{ reservation.guest_name }}
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button
                        v-if="
                            reservation.status === 'confirmed' ||
                            reservation.status === 'reserved'
                        "
                        variant="default"
                        class="bg-green-600 text-white hover:bg-green-700 dark:bg-green-700 dark:hover:bg-green-800"
                        @click="checkIn"
                    >
                        Check In
                    </Button>
                    <Button
                        v-if="reservation.status === 'checked_in'"
                        @click="checkOut"
                    >
                        Check Out
                    </Button>
                    <Button
                        v-if="
                            reservation.status !== 'checked_out' &&
                            reservation.status !== 'cancelled'
                        "
                        variant="destructive"
                        @click="cancel"
                    >
                        Cancel
                    </Button>
                    <Button
                        v-if="
                            reservation.status === 'reserved' ||
                            reservation.status === 'confirmed' ||
                            reservation.status === 'checked_in'
                        "
                        variant="outline"
                        @click="showMove = true"
                    >
                        Move Room
                    </Button>
                    <Link :href="`/reservations/${reservation.id}/edit`">
                        <Button variant="outline"> Edit </Button>
                    </Link>
                    <Link
                        v-if="reservation.status === 'checked_in'"
                        :href="`/registration-cards/${reservation.id}`"
                    >
                        <Button variant="outline"> Registration Card </Button>
                    </Link>
                    <MoveDialog
                        :open="showMove"
                        :reservation-id="reservation.id"
                        @close="showMove = false"
                    />
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
            <!-- Guest Info -->
            <div class="border-border bg-card rounded-lg border p-6">
                <h2 class="text-foreground mb-4 text-lg font-semibold">
                    Guest Information
                </h2>
                <dl class="space-y-2">
                    <div class="flex justify-between">
                        <dt class="text-muted-foreground">Name:</dt>
                        <dd class="text-foreground font-medium">
                            {{ reservation.guest_name }}
                        </dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-muted-foreground">Email:</dt>
                        <dd class="text-foreground">
                            {{ reservation.guest_email || '-' }}
                        </dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-muted-foreground">Phone:</dt>
                        <dd class="text-foreground">
                            {{ reservation.guest_phone || '-' }}
                        </dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-muted-foreground">Adults:</dt>
                        <dd class="text-foreground">
                            {{ reservation.adults }}
                        </dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-muted-foreground">Children:</dt>
                        <dd class="text-foreground">
                            {{ reservation.children }}
                        </dd>
                    </div>
                </dl>
            </div>

            <!-- Stay Info -->
            <div class="border-border bg-card rounded-lg border p-6">
                <h2 class="text-foreground mb-4 text-lg font-semibold">
                    Stay Details
                </h2>
                <dl class="space-y-2">
                    <div class="flex justify-between">
                        <dt class="text-muted-foreground">Status:</dt>
                        <dd>
                            <Badge
                                :class="
                                    statusBadgeVariant[reservation.status] ?? ''
                                "
                                variant="outline"
                            >
                                {{ reservation.status.replace('_', ' ') }}
                            </Badge>
                        </dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-muted-foreground">Room:</dt>
                        <dd class="text-foreground">
                            {{ reservation.room?.number || 'Unassigned' }}
                        </dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-muted-foreground">Room Type:</dt>
                        <dd class="text-foreground">
                            {{ reservation.room_type.name }}
                        </dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-muted-foreground">Check-in:</dt>
                        <dd class="text-foreground">
                            {{ formatDate(reservation.check_in_date) }}
                        </dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-muted-foreground">Check-out:</dt>
                        <dd class="text-foreground">
                            {{ formatDate(reservation.check_out_date) }}
                        </dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-muted-foreground">Actual Check-in:</dt>
                        <dd class="text-foreground">
                            {{
                                formatDateTime(
                                    reservation.actual_check_in_at ?? '',
                                )
                            }}
                        </dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-muted-foreground">Actual Check-out:</dt>
                        <dd class="text-foreground">
                            {{
                                formatDateTime(
                                    reservation.actual_check_out_at ?? '',
                                )
                            }}
                        </dd>
                    </div>
                </dl>
            </div>

            <!-- Payment Info -->
            <div class="border-border bg-card rounded-lg border p-6">
                <h2 class="text-foreground mb-4 text-lg font-semibold">
                    Payment
                </h2>
                <dl class="space-y-2">
                    <div class="flex justify-between">
                        <dt class="text-muted-foreground">Room Rate:</dt>
                        <dd class="text-foreground">
                            {{ formatCurrency(reservation.room_rate) }}/night
                        </dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-muted-foreground">Total Amount:</dt>
                        <dd class="text-foreground font-bold">
                            {{ formatCurrency(reservation.total_amount) }}
                        </dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-muted-foreground">Amount Paid:</dt>
                        <dd class="text-foreground">
                            {{ formatCurrency(reservation.amount_paid) }}
                        </dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-muted-foreground">Payment Status:</dt>
                        <dd class="text-foreground">
                            {{ reservation.payment_status }}
                        </dd>
                    </div>
                </dl>
            </div>

            <!-- Notes -->
            <div class="border-border bg-card rounded-lg border p-6">
                <h2 class="text-foreground mb-4 text-lg font-semibold">
                    Notes
                </h2>
                <p class="text-muted-foreground">
                    {{ reservation.guest_notes || 'No notes' }}
                </p>
                <div v-if="reservation.special_requests?.length" class="mt-4">
                    <h3 class="text-muted-foreground mb-2 text-sm font-medium">
                        Special Requests
                    </h3>
                    <ul class="text-muted-foreground list-inside list-disc">
                        <li
                            v-for="(
                                request, idx
                            ) in reservation.special_requests"
                            :key="idx"
                        >
                            {{ request }}
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="mt-6">
            <UpsellPanel
                :reservation-id="reservation.id"
                :quotes="upsells"
                :can-grant-free="can_grant_free_upsell"
            />
        </div>

        <div
            v-if="hotspotTiers.length && can_select_hotspot"
            class="border-border bg-card mt-6 rounded-lg border p-6"
        >
            <h2 class="text-foreground mb-4 text-lg font-semibold">
                Wi-Fi Tier
            </h2>
            <p
                v-if="hotspotSelection?.tier"
                class="text-muted-foreground mb-3 text-sm"
            >
                Current: {{ hotspotSelection.tier.name }}
            </p>
            <div class="flex items-end gap-3">
                <div class="grid gap-2">
                    <label class="text-sm font-medium">Tier</label>
                    <select
                        v-model="selectedTier"
                        class="border-input bg-background flex h-10 rounded-md border px-3 py-2 text-sm"
                    >
                        <option value="" disabled>Select tier</option>
                        <option
                            v-for="tier in hotspotTiers"
                            :key="tier.id"
                            :value="String(tier.id)"
                        >
                            {{ tier.name }} ({{
                                tier.price_minor === 0
                                    ? 'Free'
                                    : tier.price_minor
                            }})
                        </option>
                    </select>
                </div>
                <Button @click="saveTier">Set tier</Button>
            </div>
        </div>
    </div>
</template>
