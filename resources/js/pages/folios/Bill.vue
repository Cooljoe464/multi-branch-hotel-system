<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { formatDate, formatDateTime } from '@/lib/dates';

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

interface Branch {
    id: number;
    name: string;
    city: string;
    address: string | null;
    phone: string | null;
    email: string | null;
    currency_symbol: string;
    tax_rate: number;
}

interface Folio {
    id: number;
    folio_number: string;
    type: string;
    status: string;
    description: string | null;
    guest_name: string | null;
    balance: number;
    created_at: string;
    closed_at: string | null;
}

interface Reservation {
    id: number;
    confirmation_number: string;
    guest_name: string;
    check_in_date: string;
    check_out_date: string;
    room_rate: number;
    room: { number: string } | null;
    room_type: { name: string } | null;
}

const props = defineProps<{
    folio: Folio;
    branch: Branch;
    reservation: Reservation | null;
    guest_name: string;
    transactions: Transaction[];
    debits_total: number;
    tax_total: number;
    credits_total: number;
    balance: number;
    generated_at: string;
}>();

import { formatCurrency as formatCurrencyRaw } from '@/lib/format';
const formatCurrency = (amount: number) =>
    formatCurrencyRaw(amount, props.branch.currency_symbol);

const getCategoryLabel = (category: string) => {
    const labels: Record<string, string> = {
        room_rate: 'Room Rate',
        tax: 'Tax',
        minibar: 'Minibar',
        restaurant: 'Restaurant',
        laundry: 'Laundry',
        spa: 'Spa',
        parking: 'Parking',
        misc: 'Miscellaneous',
        payment: 'Payment',
        refund: 'Refund',
        adjustment: 'Adjustment',
        transfer: 'Transfer',
    };
    return labels[category] || category;
};

const printBill = () => {
    window.print();
};
</script>

<template>
    <Head :title="`Bill - ${folio.folio_number}`" />

    <div class="bg-background min-h-screen">
        <!-- Print Button (hidden when printing) -->
        <div
            class="no-print bg-card border-border flex items-center justify-between border-b p-4"
        >
            <Link
                :href="`/folios/${folio.id}`"
                class="text-sm font-medium text-indigo-600 hover:text-indigo-500"
            >
                &larr; Back to Folio
            </Link>
            <button
                @click="printBill"
                class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500"
            >
                Print / Download PDF
            </button>
        </div>

        <!-- Invoice Content -->
        <div class="mx-auto max-w-4xl p-4 md:p-8">
            <div class="bg-card overflow-hidden rounded-lg shadow-lg">
                <!-- Header -->
                <div class="bg-indigo-600 p-6 text-white md:p-8">
                    <div
                        class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between"
                    >
                        <div>
                            <h1 class="text-2xl font-bold">
                                {{ branch.name }}
                            </h1>
                            <p class="mt-1 text-sm text-indigo-200">
                                {{ branch.city }}
                            </p>
                            <p
                                v-if="branch.address"
                                class="text-sm text-indigo-200"
                            >
                                {{ branch.address }}
                            </p>
                            <p
                                v-if="branch.phone"
                                class="mt-1 text-sm text-indigo-200"
                            >
                                Tel: {{ branch.phone }}
                            </p>
                            <p
                                v-if="branch.email"
                                class="text-sm text-indigo-200"
                            >
                                {{ branch.email }}
                            </p>
                        </div>
                        <div class="md:text-right">
                            <h2 class="text-xl font-semibold">INVOICE</h2>
                            <p class="mt-1 font-mono text-sm text-indigo-200">
                                {{ folio.folio_number }}
                            </p>
                            <p class="text-sm text-indigo-200">
                                Status: {{ folio.status.toUpperCase() }}
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Guest & Reservation Details -->
                <div class="border-border border-b p-6 md:p-8">
                    <div class="grid grid-cols-1 gap-6 md:grid-cols-2 md:gap-8">
                        <div>
                            <h3
                                class="text-muted-foreground mb-2 text-sm font-semibold uppercase"
                            >
                                Bill To
                            </h3>
                            <p class="text-foreground text-lg font-medium">
                                {{ guest_name }}
                            </p>
                            <p
                                v-if="folio.description"
                                class="text-muted-foreground mt-1 text-sm"
                            >
                                {{ folio.description }}
                            </p>
                        </div>
                        <div>
                            <h3
                                class="text-muted-foreground mb-2 text-sm font-semibold uppercase"
                            >
                                Details
                            </h3>
                            <div class="space-y-1 text-sm">
                                <p class="text-foreground">
                                    <span class="text-muted-foreground"
                                        >Folio:</span
                                    >
                                    {{ folio.folio_number }}
                                </p>
                                <p v-if="reservation" class="text-foreground">
                                    <span class="text-muted-foreground"
                                        >Confirmation:</span
                                    >
                                    {{ reservation.confirmation_number }}
                                </p>
                                <p
                                    v-if="reservation?.room"
                                    class="text-foreground"
                                >
                                    <span class="text-muted-foreground"
                                        >Room:</span
                                    >
                                    {{ reservation.room.number }}
                                </p>
                                <p
                                    v-if="reservation?.room_type"
                                    class="text-foreground"
                                >
                                    <span class="text-muted-foreground"
                                        >Room Type:</span
                                    >
                                    {{ reservation.room_type.name }}
                                </p>
                                <p class="text-foreground">
                                    <span class="text-muted-foreground"
                                        >Date:</span
                                    >
                                    {{ formatDate(folio.created_at) }}
                                </p>
                                <p
                                    v-if="folio.closed_at"
                                    class="text-foreground"
                                >
                                    <span class="text-muted-foreground"
                                        >Closed:</span
                                    >
                                    {{ formatDate(folio.closed_at) }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Stay Details (if reservation) -->
                <div
                    v-if="reservation"
                    class="border-border bg-muted border-b p-6 md:p-8"
                >
                    <div
                        class="grid grid-cols-1 gap-4 text-center sm:grid-cols-3"
                    >
                        <div>
                            <p class="text-muted-foreground text-sm">
                                Check-in
                            </p>
                            <p class="text-foreground font-medium">
                                {{ formatDate(reservation.check_in_date) }}
                            </p>
                        </div>
                        <div>
                            <p class="text-muted-foreground text-sm">
                                Check-out
                            </p>
                            <p class="text-foreground font-medium">
                                {{ formatDate(reservation.check_out_date) }}
                            </p>
                        </div>
                        <div>
                            <p class="text-muted-foreground text-sm">
                                Room Rate
                            </p>
                            <p class="text-foreground font-medium">
                                {{
                                    formatCurrency(reservation.room_rate)
                                }}/night
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Transactions -->
                <div class="p-6 md:p-8">
                    <h3
                        class="text-muted-foreground mb-4 text-sm font-semibold uppercase"
                    >
                        Itemized Charges
                    </h3>

                    <!-- Mobile: Card View -->
                    <div class="space-y-3 md:hidden">
                        <div
                            v-for="tx in transactions"
                            :key="tx.id"
                            class="border-border rounded-lg border p-3"
                            :class="{ 'line-through opacity-50': tx.is_voided }"
                        >
                            <div
                                class="mb-1 flex items-start justify-between gap-2"
                            >
                                <span class="text-muted-foreground text-xs">{{
                                    formatDate(tx.created_at)
                                }}</span>
                                <span class="text-muted-foreground text-xs">{{
                                    getCategoryLabel(tx.category)
                                }}</span>
                            </div>
                            <p class="text-foreground text-sm">
                                {{ tx.description }}
                                <span
                                    v-if="tx.is_voided"
                                    class="ml-1 text-xs text-red-500"
                                    >(VOIDED)</span
                                >
                            </p>
                            <div class="mt-1 text-right">
                                <span
                                    v-if="tx.type === 'debit' && !tx.is_voided"
                                    class="text-sm font-medium text-red-600 dark:text-red-400"
                                    >{{ formatCurrency(tx.amount) }}</span
                                >
                                <span
                                    v-else-if="
                                        tx.type === 'credit' && !tx.is_voided
                                    "
                                    class="text-sm font-medium text-green-600 dark:text-green-400"
                                    >{{ formatCurrency(tx.amount) }}</span
                                >
                                <span
                                    v-else
                                    class="text-muted-foreground text-sm"
                                    >-</span
                                >
                            </div>
                        </div>
                        <div
                            v-if="transactions.length === 0"
                            class="text-muted-foreground py-8 text-center"
                        >
                            No transactions.
                        </div>
                    </div>

                    <!-- Desktop: Table View -->
                    <div class="hidden md:block">
                        <table class="w-full">
                            <thead class="bg-muted/50">
                                <tr class="border-border border-b">
                                    <th
                                        class="text-muted-foreground h-12 px-4 text-left font-medium"
                                    >
                                        Date
                                    </th>
                                    <th
                                        class="text-muted-foreground h-12 px-4 text-left font-medium"
                                    >
                                        Category
                                    </th>
                                    <th
                                        class="text-muted-foreground h-12 px-4 text-left font-medium"
                                    >
                                        Description
                                    </th>
                                    <th
                                        class="text-muted-foreground h-12 px-4 text-right font-medium"
                                    >
                                        Debit
                                    </th>
                                    <th
                                        class="text-muted-foreground h-12 px-4 text-right font-medium"
                                    >
                                        Credit
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="tx in transactions"
                                    :key="tx.id"
                                    class="border-border border-b"
                                    :class="{
                                        'line-through opacity-50': tx.is_voided,
                                    }"
                                >
                                    <td
                                        class="text-muted-foreground py-3 text-sm"
                                    >
                                        {{ formatDate(tx.created_at) }}
                                    </td>
                                    <td
                                        class="text-muted-foreground py-3 text-sm"
                                    >
                                        {{ getCategoryLabel(tx.category) }}
                                    </td>
                                    <td class="text-foreground py-3 text-sm">
                                        {{ tx.description }}
                                        <span
                                            v-if="tx.is_voided"
                                            class="ml-1 text-xs text-red-500"
                                            >(VOIDED)</span
                                        >
                                    </td>
                                    <td
                                        class="py-3 text-right text-sm font-medium text-red-600 dark:text-red-400"
                                    >
                                        {{
                                            tx.type === 'debit' && !tx.is_voided
                                                ? formatCurrency(tx.amount)
                                                : ''
                                        }}
                                    </td>
                                    <td
                                        class="py-3 text-right text-sm font-medium text-green-600 dark:text-green-400"
                                    >
                                        {{
                                            tx.type === 'credit' &&
                                            !tx.is_voided
                                                ? formatCurrency(tx.amount)
                                                : ''
                                        }}
                                    </td>
                                </tr>
                                <tr v-if="transactions.length === 0">
                                    <td
                                        colspan="5"
                                        class="text-muted-foreground py-8 text-center"
                                    >
                                        No transactions.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Summary -->
                <div class="bg-muted border-border border-t p-6 md:p-8">
                    <div class="flex justify-end">
                        <div class="w-full space-y-2 sm:w-72">
                            <div class="flex justify-between text-sm">
                                <span class="text-muted-foreground"
                                    >Total Charges</span
                                >
                                <span class="text-foreground font-medium">{{
                                    formatCurrency(debits_total)
                                }}</span>
                            </div>
                            <div class="flex justify-between text-sm">
                                <span class="text-muted-foreground"
                                    >Tax Included</span
                                >
                                <span class="text-foreground font-medium">{{
                                    formatCurrency(tax_total)
                                }}</span>
                            </div>
                            <div class="flex justify-between text-sm">
                                <span class="text-muted-foreground"
                                    >Total Payments</span
                                >
                                <span
                                    class="font-medium text-green-600 dark:text-green-400"
                                    >{{ formatCurrency(credits_total) }}</span
                                >
                            </div>
                            <div
                                class="border-border flex justify-between border-t pt-2 text-lg font-bold"
                            >
                                <span class="text-foreground">Balance Due</span>
                                <span
                                    :class="
                                        balance > 0
                                            ? 'text-red-600 dark:text-red-400'
                                            : 'text-green-600 dark:text-green-400'
                                    "
                                >
                                    {{ formatCurrency(balance) }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Footer -->
                <div
                    class="text-muted-foreground border-border border-t p-6 text-center text-xs md:p-8"
                >
                    <p>Thank you for staying with us.</p>
                    <p class="mt-1">
                        {{ branch.name }} &mdash; {{ branch.city }}
                    </p>
                    <p class="mt-1">
                        Generated {{ formatDateTime(generated_at) }}
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>

<style>
@media print {
    .no-print {
        display: none !important;
    }
    body {
        background: white !important;
    }
}
</style>
