<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { ArrowLeft } from '@lucide/vue';
import { formatDate, formatDateTime } from '@/lib/dates';
import LocaleSwitcher from '@/components/LocaleSwitcher.vue';
import { t } from '@/lib/locale';

interface Guest {
    id: number;
    first_name: string;
    last_name: string;
    email: string;
    phone: string | null;
    vip_status: string;
    total_stays: number;
    total_nights: number;
    total_spent: number;
}

interface Branch {
    id: number;
    name: string;
    address: string | null;
    city: string;
    phone: string | null;
    email: string | null;
    currency_symbol: string;
}

interface RoomType {
    id: number;
    name: string;
}

interface Room {
    id: number;
    number: string;
    floor: string | null;
}

interface Transaction {
    id: number;
    type: string;
    category: string;
    description: string;
    amount: number;
    tax_amount: number;
    is_voided: boolean;
    created_at: string;
}

interface Folio {
    id: number;
    folio_number: string;
    type: string;
    status: string;
    description: string | null;
    balance: number;
    created_at: string;
}

interface Reservation {
    id: number;
    confirmation_number: string;
    status: string;
    guest_name: string;
    guest_email: string | null;
    guest_phone: string | null;
    adults: number;
    children: number;
    check_in_date: string;
    check_out_date: string;
    actual_check_in_at: string | null;
    actual_check_out_at: string | null;
    room_rate: number;
    total_amount: number;
    amount_paid: number;
    payment_status: string;
    special_requests: string[] | null;
    branch: Branch;
    room: Room | null;
    room_type: RoomType;
    guest: Guest | null;
    nights: number;
}

const props = defineProps<{
    folio: Folio | null;
    reservation: Reservation;
    guest: Guest | null;
    transactions: Transaction[];
}>();

import { formatCurrency as formatCurrencyRaw } from '@/lib/format';
const formatCurrency = (amount: number) =>
    formatCurrencyRaw(amount, props.reservation.branch.currency_symbol);

const getStatusBadgeClass = (status: string) => {
    const classes: Record<string, string> = {
        pending:
            'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300',
        confirmed:
            'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300',
        reserved:
            'bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-300',
        checked_in:
            'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300',
        checked_out: 'bg-muted text-muted-foreground',
        cancelled: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300',
    };
    return classes[status] || 'bg-muted text-muted-foreground';
};

const getVipBadgeClass = (status: string) => {
    const classes: Record<string, string> = {
        none: '',
        silver: 'bg-muted text-muted-foreground',
        gold: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300',
        platinum:
            'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-300',
        diamond:
            'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300',
    };
    return classes[status] || '';
};

const categoryLabels = computed<Record<string, string>>(() => ({
    room_rate: t('portal.category_room_rate'),
    tax: t('portal.category_tax'),
    minibar: t('portal.category_minibar'),
    restaurant: t('portal.category_restaurant'),
    laundry: t('portal.category_laundry'),
    spa: t('portal.category_spa'),
    parking: t('portal.category_parking'),
    misc: t('portal.category_misc'),
    payment: t('portal.category_payment'),
    refund: t('portal.category_refund'),
    adjustment: t('portal.category_adjustment'),
    transfer: t('portal.category_transfer'),
}));

const getCategoryLabel = (category: string) => {
    return categoryLabels.value[category] || category;
};

const debits = () =>
    props.transactions.filter((t) => t.type === 'debit' && !t.is_voided);
const credits = () =>
    props.transactions.filter((t) => t.type === 'credit' && !t.is_voided);
const debitsTotal = () => debits().reduce((sum, t) => sum + t.amount, 0);
const creditsTotal = () => credits().reduce((sum, t) => sum + t.amount, 0);

const goBack = () => {
    window.history.back();
};
</script>

<template>
    <Head
        :title="`${t('portal.folio_title')} - ${reservation.confirmation_number}`"
    />

    <div class="bg-background min-h-screen">
        <!-- Header -->
        <header class="bg-card shadow">
            <div class="mx-auto max-w-4xl px-4 py-6">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <Button
                            variant="ghost"
                            size="sm"
                            @click="goBack()"
                            class="text-muted-foreground hover:text-foreground gap-1"
                        >
                            <ArrowLeft class="size-4" /> {{ t('common.back') }}
                        </Button>
                        <div>
                            <h1 class="text-foreground text-2xl font-bold">
                                {{ reservation.branch.name }}
                            </h1>
                            <p class="text-muted-foreground">
                                {{ reservation.branch.city }}
                            </p>
                        </div>
                    </div>
                    <div class="flex items-start gap-3">
                        <LocaleSwitcher />
                        <div class="text-right">
                            <p class="text-muted-foreground text-sm">
                                {{ t('common.confirmation') }}
                            </p>
                            <p
                                class="text-foreground font-mono text-lg font-bold"
                            >
                                {{ reservation.confirmation_number }}
                            </p>
                            <Link
                                v-if="reservation.status === 'checked_in'"
                                :href="`/guest/order/${reservation.confirmation_number}`"
                                class="text-primary mt-1 inline-block text-xs hover:underline"
                            >
                                {{ t('portal.order_services') }}
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-4xl px-4 py-8">
            <!-- Status Banner -->
            <div
                class="mb-6 rounded-lg p-4"
                :class="{
                    'border border-green-200 bg-green-50 dark:border-green-800 dark:bg-green-900/30':
                        reservation.status === 'checked_in',
                    'border border-blue-200 bg-blue-50 dark:border-blue-800 dark:bg-blue-900/30':
                        reservation.status === 'confirmed' ||
                        reservation.status === 'reserved',
                    'bg-muted border-border border':
                        reservation.status === 'checked_out',
                }"
            >
                <div class="flex items-center justify-between">
                    <div>
                        <Badge :class="getStatusBadgeClass(reservation.status)">
                            {{
                                reservation.status
                                    .replace('_', ' ')
                                    .toUpperCase()
                            }}
                        </Badge>
                        <Badge
                            v-if="guest && guest.vip_status !== 'none'"
                            class="ml-2"
                            :class="getVipBadgeClass(guest.vip_status)"
                        >
                            {{ guest.vip_status.toUpperCase() }}
                            {{ t('portal.vip_suffix') }}
                        </Badge>
                    </div>
                    <div class="text-right">
                        <p class="text-muted-foreground text-sm">
                            {{ t('common.guest') }}
                        </p>
                        <p class="text-foreground font-medium">
                            {{ reservation.guest_name }}
                        </p>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                <!-- Stay Details -->
                <div class="bg-card rounded-lg p-6 shadow">
                    <h2 class="text-foreground mb-4 text-lg font-semibold">
                        {{ t('portal.stay_details') }}
                    </h2>
                    <dl class="space-y-3">
                        <div class="flex justify-between">
                            <dt class="text-muted-foreground">
                                {{ t('common.room_type') }}
                            </dt>
                            <dd class="text-foreground font-medium">
                                {{ reservation.room_type.name }}
                            </dd>
                        </div>
                        <div
                            v-if="reservation.room"
                            class="flex justify-between"
                        >
                            <dt class="text-muted-foreground">
                                {{ t('portal.room_number') }}
                            </dt>
                            <dd class="text-foreground font-medium">
                                {{ reservation.room.number }}
                            </dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-muted-foreground">
                                {{ t('portal.check_in') }}
                            </dt>
                            <dd class="text-foreground">
                                {{ formatDate(reservation.check_in_date) }}
                            </dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-muted-foreground">
                                {{ t('portal.check_out') }}
                            </dt>
                            <dd class="text-foreground">
                                {{ formatDate(reservation.check_out_date) }}
                            </dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-muted-foreground">
                                {{ t('portal.nights') }}
                            </dt>
                            <dd class="text-foreground">
                                {{ reservation.nights }}
                            </dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-muted-foreground">
                                {{ t('portal.guests') }}
                            </dt>
                            <dd class="text-foreground">
                                {{
                                    t('portal.guests_value', {
                                        adults: reservation.adults,
                                        children: reservation.children,
                                    })
                                }}
                            </dd>
                        </div>
                    </dl>
                </div>

                <!-- Contact Info -->
                <div class="bg-card rounded-lg p-6 shadow">
                    <h2 class="text-foreground mb-4 text-lg font-semibold">
                        {{ t('portal.contact_info') }}
                    </h2>
                    <dl class="space-y-3">
                        <div class="flex justify-between">
                            <dt class="text-muted-foreground">
                                {{ t('common.email') }}
                            </dt>
                            <dd class="text-foreground">
                                {{ reservation.guest_email || '-' }}
                            </dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-muted-foreground">
                                {{ t('portal.phone') }}
                            </dt>
                            <dd class="text-foreground">
                                {{ reservation.guest_phone || '-' }}
                            </dd>
                        </div>
                        <div
                            v-if="reservation.branch.phone"
                            class="flex justify-between"
                        >
                            <dt class="text-muted-foreground">
                                {{ t('portal.property_phone') }}
                            </dt>
                            <dd class="text-foreground">
                                {{ reservation.branch.phone }}
                            </dd>
                        </div>
                        <div
                            v-if="reservation.branch.email"
                            class="flex justify-between"
                        >
                            <dt class="text-muted-foreground">
                                {{ t('portal.property_email') }}
                            </dt>
                            <dd class="text-foreground">
                                {{ reservation.branch.email }}
                            </dd>
                        </div>
                        <div
                            v-if="reservation.branch.address"
                            class="flex justify-between"
                        >
                            <dt class="text-muted-foreground">
                                {{ t('portal.address') }}
                            </dt>
                            <dd class="text-foreground text-right">
                                {{ reservation.branch.address }}
                            </dd>
                        </div>
                    </dl>
                </div>

                <!-- Folio Transactions -->
                <div class="bg-card rounded-lg p-6 shadow md:col-span-2">
                    <div class="mb-4 flex items-center justify-between">
                        <h2 class="text-foreground text-lg font-semibold">
                            {{ t('portal.folio_title') }}
                        </h2>
                        <Badge
                            v-if="folio"
                            variant="outline"
                            class="bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-300"
                        >
                            {{ folio.folio_number }}
                        </Badge>
                    </div>

                    <div v-if="transactions.length > 0">
                        <table class="divide-border mb-4 min-w-full divide-y">
                            <thead class="bg-muted/50">
                                <tr>
                                    <th
                                        class="text-muted-foreground px-3 py-2 text-left text-xs font-medium uppercase"
                                    >
                                        {{ t('portal.table_date') }}
                                    </th>
                                    <th
                                        class="text-muted-foreground px-3 py-2 text-left text-xs font-medium uppercase"
                                    >
                                        {{ t('portal.table_description') }}
                                    </th>
                                    <th
                                        class="text-muted-foreground px-3 py-2 text-right text-xs font-medium uppercase"
                                    >
                                        {{ t('portal.table_debit') }}
                                    </th>
                                    <th
                                        class="text-muted-foreground px-3 py-2 text-right text-xs font-medium uppercase"
                                    >
                                        {{ t('portal.table_credit') }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-border divide-y">
                                <tr
                                    v-for="tx in transactions"
                                    :key="tx.id"
                                    :class="{
                                        'line-through opacity-50': tx.is_voided,
                                    }"
                                >
                                    <td
                                        class="text-muted-foreground px-3 py-2 text-sm"
                                    >
                                        {{ formatDate(tx.created_at) }}
                                    </td>
                                    <td class="px-3 py-2 text-sm">
                                        <span class="text-foreground">{{
                                            tx.description
                                        }}</span>
                                        <span
                                            class="text-muted-foreground ml-1 text-xs"
                                            >({{
                                                getCategoryLabel(tx.category)
                                            }})</span
                                        >
                                    </td>
                                    <td
                                        class="px-3 py-2 text-right text-sm font-medium text-red-600 dark:text-red-400"
                                    >
                                        {{
                                            tx.type === 'debit' && !tx.is_voided
                                                ? formatCurrency(tx.amount)
                                                : ''
                                        }}
                                    </td>
                                    <td
                                        class="px-3 py-2 text-right text-sm font-medium text-green-600 dark:text-green-400"
                                    >
                                        {{
                                            tx.type === 'credit' &&
                                            !tx.is_voided
                                                ? formatCurrency(tx.amount)
                                                : ''
                                        }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>

                        <!-- Summary -->
                        <div class="border-border space-y-2 border-t pt-3">
                            <div class="flex justify-between text-sm">
                                <span class="text-muted-foreground">{{
                                    t('portal.total_charges')
                                }}</span>
                                <span
                                    class="font-medium text-red-600 dark:text-red-400"
                                    >{{ formatCurrency(debitsTotal()) }}</span
                                >
                            </div>
                            <div class="flex justify-between text-sm">
                                <span class="text-muted-foreground">{{
                                    t('portal.total_payments')
                                }}</span>
                                <span
                                    class="font-medium text-green-600 dark:text-green-400"
                                    >{{ formatCurrency(creditsTotal()) }}</span
                                >
                            </div>
                            <div
                                class="border-border flex justify-between border-t pt-2 text-lg font-semibold"
                            >
                                <span class="text-foreground">{{
                                    t('portal.balance_due')
                                }}</span>
                                <span
                                    :class="
                                        folio && folio.balance > 0
                                            ? 'text-red-600 dark:text-red-400'
                                            : 'text-green-600 dark:text-green-400'
                                    "
                                >
                                    {{
                                        formatCurrency(
                                            folio ? folio.balance : 0,
                                        )
                                    }}
                                </span>
                            </div>
                            <div
                                v-if="
                                    folio &&
                                    folio.balance > 0 &&
                                    reservation.status === 'checked_in'
                                "
                                class="pt-3"
                            >
                                <a
                                    :href="`/guest/folio/${reservation.confirmation_number}/pay?amount=${folio.balance}`"
                                    class="bg-primary text-primary-foreground hover:bg-primary/90 block w-full rounded-lg py-3 text-center text-sm font-medium"
                                >
                                    {{ t('portal.pay_now_qr') }}
                                </a>
                            </div>
                        </div>
                    </div>

                    <div v-else class="text-muted-foreground py-8 text-center">
                        <p>{{ t('portal.no_transactions') }}</p>
                    </div>
                </div>

                <!-- Financial Summary -->
                <div class="bg-card rounded-lg p-6 shadow md:col-span-2">
                    <h2 class="text-foreground mb-4 text-lg font-semibold">
                        {{ t('portal.financial_summary') }}
                    </h2>
                    <dl class="space-y-3">
                        <div class="flex justify-between">
                            <dt class="text-muted-foreground">
                                {{ t('portal.room_rate') }}
                            </dt>
                            <dd class="text-foreground">
                                {{ formatCurrency(reservation.room_rate)
                                }}{{ t('portal.night_suffix') }}
                            </dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-muted-foreground">
                                {{
                                    t('portal.subtotal_nights', {
                                        nights: reservation.nights,
                                    })
                                }}
                            </dt>
                            <dd class="text-foreground">
                                {{
                                    formatCurrency(
                                        reservation.room_rate *
                                            reservation.nights,
                                    )
                                }}
                            </dd>
                        </div>
                        <div
                            class="border-border flex justify-between border-t pt-3 text-lg font-semibold"
                        >
                            <dt class="text-foreground">
                                {{ t('portal.total_row') }}
                            </dt>
                            <dd class="text-foreground">
                                {{ formatCurrency(reservation.total_amount) }}
                            </dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-muted-foreground">
                                {{ t('portal.amount_paid') }}
                            </dt>
                            <dd class="text-green-600 dark:text-green-400">
                                {{ formatCurrency(reservation.amount_paid) }}
                            </dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-muted-foreground">
                                {{ t('portal.balance_due') }}
                            </dt>
                            <dd class="text-foreground font-medium">
                                {{
                                    formatCurrency(
                                        reservation.total_amount -
                                            reservation.amount_paid,
                                    )
                                }}
                            </dd>
                        </div>
                    </dl>
                </div>

                <!-- Timeline -->
                <div class="bg-card rounded-lg p-6 shadow md:col-span-2">
                    <h2 class="text-foreground mb-4 text-lg font-semibold">
                        {{ t('portal.timeline') }}
                    </h2>
                    <dl class="space-y-3">
                        <div class="flex justify-between">
                            <dt class="text-muted-foreground">
                                {{ t('portal.booked_on') }}
                            </dt>
                            <dd class="text-foreground">
                                {{ formatDateTime(reservation.created_at) }}
                            </dd>
                        </div>
                        <div
                            v-if="reservation.actual_check_in_at"
                            class="flex justify-between"
                        >
                            <dt class="text-muted-foreground">
                                {{ t('portal.actual_check_in') }}
                            </dt>
                            <dd class="text-foreground">
                                {{
                                    formatDateTime(
                                        reservation.actual_check_in_at,
                                    )
                                }}
                            </dd>
                        </div>
                        <div
                            v-if="reservation.actual_check_out_at"
                            class="flex justify-between"
                        >
                            <dt class="text-muted-foreground">
                                {{ t('portal.actual_check_out') }}
                            </dt>
                            <dd class="text-foreground">
                                {{
                                    formatDateTime(
                                        reservation.actual_check_out_at,
                                    )
                                }}
                            </dd>
                        </div>
                    </dl>
                </div>

                <!-- Guest Profile (if linked) -->
                <div
                    v-if="guest && guest.total_stays > 0"
                    class="bg-card rounded-lg p-6 shadow md:col-span-2"
                >
                    <h2 class="text-foreground mb-4 text-lg font-semibold">
                        {{ t('portal.guest_profile') }}
                    </h2>
                    <div class="grid grid-cols-3 gap-4 text-center">
                        <div>
                            <p class="text-foreground text-2xl font-bold">
                                {{ guest.total_stays }}
                            </p>
                            <p class="text-muted-foreground text-sm">
                                {{ t('portal.total_stays') }}
                            </p>
                        </div>
                        <div>
                            <p class="text-foreground text-2xl font-bold">
                                {{ guest.total_nights }}
                            </p>
                            <p class="text-muted-foreground text-sm">
                                {{ t('portal.total_nights_stat') }}
                            </p>
                        </div>
                        <div>
                            <p class="text-foreground text-2xl font-bold">
                                {{ formatCurrency(guest.total_spent) }}
                            </p>
                            <p class="text-muted-foreground text-sm">
                                {{ t('portal.total_spent') }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</template>
