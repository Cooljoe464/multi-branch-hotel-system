<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDate } from '@/lib/dates';
import {
    formatCurrency as formatCurrencyRaw,
    getCurrencySymbol,
} from '@/lib/format';
import { BedDouble, LogIn, LogOut, Users, ArrowRight } from '@lucide/vue';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Front Desk', href: '/front-desk' },
        ],
    },
});

interface Room {
    id: number;
    number: string;
    name: string | null;
}

interface Reservation {
    id: number;
    confirmation_number: string;
    guest_name: string;
    check_in_date: string;
    check_out_date: string;
    status: string;
    room_rate: number;
    currency_code?: string | null;
    adults: number;
    children: number;
    room: Room | null;
}

defineProps<{
    branch: {
        name: string;
        currency_symbol: string;
    };
    roomStatusCounts: {
        available: number;
        occupied: number;
        dirty: number;
        out_of_order: number;
    };
    todayArrivals: Reservation[];
    todayDepartures: Reservation[];
    inHouseGuests: Reservation[];
}>();

const page = usePage();
const branchSymbol = computed(
    () => (page.props.branch?.current as any)?.currency_symbol || '₦',
);

function resolveCurrencySymbol(code?: string): string {
    const currencyCode = code || 'NGN';
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
    return symbols[currencyCode] || branchSymbol.value;
}

function formatPrice(cents: number, currencyCode?: string): string {
    return formatCurrencyRaw(cents, resolveCurrencySymbol(currencyCode));
}

const statusColors: Record<string, string> = {
    pending:
        'bg-yellow-100 text-yellow-800 border-yellow-300 dark:bg-yellow-900 dark:text-yellow-300',
    confirmed:
        'bg-blue-100 text-blue-800 border-blue-300 dark:bg-blue-900 dark:text-blue-300',
    reserved:
        'bg-blue-100 text-blue-800 border-blue-300 dark:bg-blue-900 dark:text-blue-300',
    checked_in:
        'bg-green-100 text-green-800 border-green-300 dark:bg-green-900 dark:text-green-300',
    checked_out: 'bg-muted text-muted-foreground border-border',
};

function totalGuests(reservation: Reservation): number {
    return reservation.adults + reservation.children;
}
</script>

<template>
    <Head title="Front Desk Dashboard" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
        <div
            class="border-border flex flex-col justify-between gap-4 border-b pb-2 sm:flex-row sm:items-center"
        >
            <div>
                <h1 class="text-foreground text-2xl font-bold tracking-tight">
                    Front Desk Dashboard
                </h1>
                <p class="text-muted-foreground mt-0.5 text-sm">
                    Operations overview for
                    <span class="text-foreground font-medium">{{
                        branch.name
                    }}</span>
                </p>
            </div>
            <div class="flex items-center gap-2">
                <Button as-child size="sm">
                    <Link href="/reservations/create"> New Reservation </Link>
                </Button>
                <Button as-child size="sm" variant="outline">
                    <Link href="/rooms"> View All Rooms </Link>
                </Button>
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <a
                href="/rooms?status=available"
                class="border-border bg-card flex items-center gap-4 rounded-lg border p-4 shadow-xs transition-all hover:border-green-300 hover:shadow-md"
            >
                <div
                    class="flex h-12 w-12 items-center justify-center rounded-lg bg-green-100 dark:bg-green-900/30"
                >
                    <span class="text-xl">🟢</span>
                </div>
                <div>
                    <p class="text-muted-foreground text-sm font-medium">
                        Available
                    </p>
                    <p
                        class="text-2xl font-bold text-green-600 dark:text-green-400"
                    >
                        {{ roomStatusCounts.available }}
                    </p>
                </div>
            </a>
            <a
                href="/rooms?status=occupied"
                class="border-border bg-card flex items-center gap-4 rounded-lg border p-4 shadow-xs transition-all hover:border-red-300 hover:shadow-md"
            >
                <div
                    class="flex h-12 w-12 items-center justify-center rounded-lg bg-red-100 dark:bg-red-900/30"
                >
                    <span class="text-xl">🔴</span>
                </div>
                <div>
                    <p class="text-muted-foreground text-sm font-medium">
                        Occupied
                    </p>
                    <p
                        class="text-2xl font-bold text-red-600 dark:text-red-400"
                    >
                        {{ roomStatusCounts.occupied }}
                    </p>
                </div>
            </a>
            <a
                href="/rooms?status=dirty"
                class="border-border bg-card flex items-center gap-4 rounded-lg border p-4 shadow-xs transition-all hover:border-yellow-300 hover:shadow-md"
            >
                <div
                    class="flex h-12 w-12 items-center justify-center rounded-lg bg-yellow-100 dark:bg-yellow-900/30"
                >
                    <span class="text-xl">🟡</span>
                </div>
                <div>
                    <p class="text-muted-foreground text-sm font-medium">
                        Needs Cleanup
                    </p>
                    <p
                        class="text-2xl font-bold text-yellow-600 dark:text-yellow-400"
                    >
                        {{ roomStatusCounts.dirty }}
                    </p>
                </div>
            </a>
            <a
                href="/rooms?status=out_of_order"
                class="border-border bg-card hover:border-muted-foreground/30 flex items-center gap-4 rounded-lg border p-4 shadow-xs transition-all hover:shadow-md"
            >
                <div
                    class="bg-muted flex h-12 w-12 items-center justify-center rounded-lg"
                >
                    <span class="text-xl">⚫</span>
                </div>
                <div>
                    <p class="text-muted-foreground text-sm font-medium">
                        Out of Order
                    </p>
                    <p class="text-muted-foreground text-2xl font-bold">
                        {{ roomStatusCounts.out_of_order }}
                    </p>
                </div>
            </a>
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <Card class="border-border shadow-xs">
                <CardHeader
                    class="flex flex-row items-center justify-between space-y-0 pb-2"
                >
                    <CardTitle
                        class="text-muted-foreground flex items-center gap-2 text-sm font-medium"
                    >
                        <LogIn class="h-4 w-4" />
                        Today's Arrivals
                    </CardTitle>
                    <Badge variant="outline">{{ todayArrivals.length }}</Badge>
                </CardHeader>
                <CardContent>
                    <div
                        v-if="todayArrivals.length === 0"
                        class="text-muted-foreground py-4 text-center text-sm"
                    >
                        No arrivals scheduled for today
                    </div>
                    <div v-else class="max-h-80 space-y-3 overflow-y-auto">
                        <Link
                            v-for="reservation in todayArrivals"
                            :key="reservation.id"
                            :href="`/reservations/${reservation.id}`"
                            class="border-border hover:bg-muted/50 flex items-center justify-between rounded-md border p-3 transition-colors"
                        >
                            <div class="space-y-1">
                                <p class="text-sm font-medium">
                                    {{ reservation.guest_name }}
                                </p>
                                <p class="text-muted-foreground text-xs">
                                    Room {{ reservation.room?.number ?? '—' }} ·
                                    {{ totalGuests(reservation) }} guest{{
                                        totalGuests(reservation) > 1 ? 's' : ''
                                    }}
                                </p>
                            </div>
                            <div class="text-right">
                                <p class="text-sm font-medium">
                                    {{
                                        formatPrice(
                                            reservation.room_rate,
                                            reservation.currency_code ??
                                                undefined,
                                        )
                                    }}
                                </p>
                                <p class="text-muted-foreground text-xs">
                                    /night
                                </p>
                            </div>
                        </Link>
                    </div>
                </CardContent>
            </Card>

            <Card class="border-border shadow-xs">
                <CardHeader
                    class="flex flex-row items-center justify-between space-y-0 pb-2"
                >
                    <CardTitle
                        class="text-muted-foreground flex items-center gap-2 text-sm font-medium"
                    >
                        <LogOut class="h-4 w-4" />
                        Today's Departures
                    </CardTitle>
                    <Badge variant="outline">{{
                        todayDepartures.length
                    }}</Badge>
                </CardHeader>
                <CardContent>
                    <div
                        v-if="todayDepartures.length === 0"
                        class="text-muted-foreground py-4 text-center text-sm"
                    >
                        No departures scheduled for today
                    </div>
                    <div v-else class="max-h-80 space-y-3 overflow-y-auto">
                        <Link
                            v-for="reservation in todayDepartures"
                            :key="reservation.id"
                            :href="`/reservations/${reservation.id}`"
                            class="border-border hover:bg-muted/50 flex items-center justify-between rounded-md border p-3 transition-colors"
                        >
                            <div class="space-y-1">
                                <p class="text-sm font-medium">
                                    {{ reservation.guest_name }}
                                </p>
                                <p class="text-muted-foreground text-xs">
                                    Room {{ reservation.room?.number ?? '—' }} ·
                                    {{ totalGuests(reservation) }} guest{{
                                        totalGuests(reservation) > 1 ? 's' : ''
                                    }}
                                </p>
                            </div>
                            <div class="text-right">
                                <Badge
                                    :class="
                                        statusColors[reservation.status] ??
                                        'bg-muted text-muted-foreground border-border'
                                    "
                                    variant="outline"
                                >
                                    {{ reservation.status.replace('_', ' ') }}
                                </Badge>
                            </div>
                        </Link>
                    </div>
                </CardContent>
            </Card>

            <Card class="border-border shadow-xs">
                <CardHeader
                    class="flex flex-row items-center justify-between space-y-0 pb-2"
                >
                    <CardTitle
                        class="text-muted-foreground flex items-center gap-2 text-sm font-medium"
                    >
                        <Users class="h-4 w-4" />
                        In-House Guests
                    </CardTitle>
                    <Badge variant="outline">{{ inHouseGuests.length }}</Badge>
                </CardHeader>
                <CardContent>
                    <div
                        v-if="inHouseGuests.length === 0"
                        class="text-muted-foreground py-4 text-center text-sm"
                    >
                        No guests currently in-house
                    </div>
                    <div v-else class="max-h-80 space-y-3 overflow-y-auto">
                        <Link
                            v-for="reservation in inHouseGuests"
                            :key="reservation.id"
                            :href="`/reservations/${reservation.id}`"
                            class="border-border hover:bg-muted/50 flex items-center justify-between rounded-md border p-3 transition-colors"
                        >
                            <div class="space-y-1">
                                <p class="text-sm font-medium">
                                    {{ reservation.guest_name }}
                                </p>
                                <p class="text-muted-foreground text-xs">
                                    Room {{ reservation.room?.number ?? '—' }} ·
                                    Checkout
                                    {{ formatDate(reservation.check_out_date) }}
                                </p>
                            </div>
                            <ArrowRight class="text-muted-foreground h-4 w-4" />
                        </Link>
                    </div>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
