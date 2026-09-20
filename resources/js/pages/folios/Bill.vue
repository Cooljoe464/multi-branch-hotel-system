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
const formatCurrency = (amount: number) => formatCurrencyRaw(amount, props.branch.currency_symbol);

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

    <div class="min-h-screen bg-background">
        <!-- Print Button (hidden when printing) -->
        <div class="no-print p-4 bg-card border-b border-border flex items-center justify-between">
            <Link :href="`/folios/${folio.id}`" class="text-indigo-600 hover:text-indigo-500 text-sm font-medium">
                &larr; Back to Folio
            </Link>
            <button @click="printBill" class="px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-500 text-sm font-medium">
                Print / Download PDF
            </button>
        </div>

        <!-- Invoice Content -->
        <div class="max-w-4xl mx-auto p-4 md:p-8">
            <div class="bg-card rounded-lg shadow-lg overflow-hidden">
                <!-- Header -->
                <div class="bg-indigo-600 text-white p-6 md:p-8">
                    <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                        <div>
                            <h1 class="text-2xl font-bold">{{ branch.name }}</h1>
                            <p class="text-indigo-200 text-sm mt-1">{{ branch.city }}</p>
                            <p v-if="branch.address" class="text-indigo-200 text-sm">{{ branch.address }}</p>
                            <p v-if="branch.phone" class="text-indigo-200 text-sm mt-1">Tel: {{ branch.phone }}</p>
                            <p v-if="branch.email" class="text-indigo-200 text-sm">{{ branch.email }}</p>
                        </div>
                        <div class="md:text-right">
                            <h2 class="text-xl font-semibold">INVOICE</h2>
                            <p class="text-indigo-200 text-sm mt-1 font-mono">{{ folio.folio_number }}</p>
                            <p class="text-indigo-200 text-sm">Status: {{ folio.status.toUpperCase() }}</p>
                        </div>
                    </div>
                </div>

                <!-- Guest & Reservation Details -->
                <div class="p-6 md:p-8 border-b border-border">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 md:gap-8">
                        <div>
                            <h3 class="text-sm font-semibold text-muted-foreground uppercase mb-2">Bill To</h3>
                            <p class="font-medium text-foreground text-lg">{{ guest_name }}</p>
                            <p v-if="folio.description" class="text-sm text-muted-foreground mt-1">{{ folio.description }}</p>
                        </div>
                        <div>
                            <h3 class="text-sm font-semibold text-muted-foreground uppercase mb-2">Details</h3>
                            <div class="text-sm space-y-1">
                                <p class="text-foreground"><span class="text-muted-foreground">Folio:</span> {{ folio.folio_number }}</p>
                                <p v-if="reservation" class="text-foreground"><span class="text-muted-foreground">Confirmation:</span> {{ reservation.confirmation_number }}</p>
                                <p v-if="reservation?.room" class="text-foreground"><span class="text-muted-foreground">Room:</span> {{ reservation.room.number }}</p>
                                <p v-if="reservation?.room_type" class="text-foreground"><span class="text-muted-foreground">Room Type:</span> {{ reservation.room_type.name }}</p>
                                <p class="text-foreground"><span class="text-muted-foreground">Date:</span> {{ formatDate(folio.created_at) }}</p>
                                <p v-if="folio.closed_at" class="text-foreground"><span class="text-muted-foreground">Closed:</span> {{ formatDate(folio.closed_at) }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Stay Details (if reservation) -->
                <div v-if="reservation" class="p-6 md:p-8 border-b border-border bg-muted">
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-center">
                        <div>
                            <p class="text-sm text-muted-foreground">Check-in</p>
                            <p class="font-medium text-foreground">{{ formatDate(reservation.check_in_date) }}</p>
                        </div>
                        <div>
                            <p class="text-sm text-muted-foreground">Check-out</p>
                            <p class="font-medium text-foreground">{{ formatDate(reservation.check_out_date) }}</p>
                        </div>
                        <div>
                            <p class="text-sm text-muted-foreground">Room Rate</p>
                            <p class="font-medium text-foreground">{{ formatCurrency(reservation.room_rate) }}/night</p>
                        </div>
                    </div>
                </div>

                <!-- Transactions -->
                <div class="p-6 md:p-8">
                    <h3 class="text-sm font-semibold text-muted-foreground uppercase mb-4">Itemized Charges</h3>

                    <!-- Mobile: Card View -->
                    <div class="md:hidden space-y-3">
                        <div v-for="tx in transactions" :key="tx.id" class="border border-border rounded-lg p-3" :class="{ 'opacity-50 line-through': tx.is_voided }">
                            <div class="flex items-start justify-between gap-2 mb-1">
                                <span class="text-xs text-muted-foreground">{{ formatDate(tx.created_at) }}</span>
                                <span class="text-xs text-muted-foreground">{{ getCategoryLabel(tx.category) }}</span>
                            </div>
                            <p class="text-sm text-foreground">
                                {{ tx.description }}
                                <span v-if="tx.is_voided" class="text-xs text-red-500 ml-1">(VOIDED)</span>
                            </p>
                            <div class="mt-1 text-right">
                                <span v-if="tx.type === 'debit' && !tx.is_voided" class="text-sm font-medium text-red-600 dark:text-red-400">{{ formatCurrency(tx.amount) }}</span>
                                <span v-else-if="tx.type === 'credit' && !tx.is_voided" class="text-sm font-medium text-green-600 dark:text-green-400">{{ formatCurrency(tx.amount) }}</span>
                                <span v-else class="text-sm text-muted-foreground">-</span>
                            </div>
                        </div>
                        <div v-if="transactions.length === 0" class="py-8 text-center text-muted-foreground">
                            No transactions.
                        </div>
                    </div>

                    <!-- Desktop: Table View -->
                    <div class="hidden md:block">
                        <table class="w-full">
                            <thead class="bg-muted/50">
                                <tr class="border-b border-border">
                                    <th class="h-12 px-4 font-medium text-muted-foreground text-left">Date</th>
                                    <th class="h-12 px-4 font-medium text-muted-foreground text-left">Category</th>
                                    <th class="h-12 px-4 font-medium text-muted-foreground text-left">Description</th>
                                    <th class="h-12 px-4 font-medium text-muted-foreground text-right">Debit</th>
                                    <th class="h-12 px-4 font-medium text-muted-foreground text-right">Credit</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="tx in transactions" :key="tx.id" class="border-b border-border" :class="{ 'opacity-50 line-through': tx.is_voided }">
                                    <td class="py-3 text-sm text-muted-foreground">{{ formatDate(tx.created_at) }}</td>
                                    <td class="py-3 text-sm text-muted-foreground">{{ getCategoryLabel(tx.category) }}</td>
                                    <td class="py-3 text-sm text-foreground">
                                        {{ tx.description }}
                                        <span v-if="tx.is_voided" class="text-xs text-red-500 ml-1">(VOIDED)</span>
                                    </td>
                                    <td class="py-3 text-sm text-right font-medium text-red-600 dark:text-red-400">
                                        {{ tx.type === 'debit' && !tx.is_voided ? formatCurrency(tx.amount) : '' }}
                                    </td>
                                    <td class="py-3 text-sm text-right font-medium text-green-600 dark:text-green-400">
                                        {{ tx.type === 'credit' && !tx.is_voided ? formatCurrency(tx.amount) : '' }}
                                    </td>
                                </tr>
                                <tr v-if="transactions.length === 0">
                                    <td colspan="5" class="py-8 text-center text-muted-foreground">
                                        No transactions.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Summary -->
                <div class="p-6 md:p-8 bg-muted border-t border-border">
                    <div class="flex justify-end">
                        <div class="w-full sm:w-72 space-y-2">
                            <div class="flex justify-between text-sm">
                                <span class="text-muted-foreground">Total Charges</span>
                                <span class="font-medium text-foreground">{{ formatCurrency(debits_total) }}</span>
                            </div>
                            <div class="flex justify-between text-sm">
                                <span class="text-muted-foreground">Tax Included</span>
                                <span class="font-medium text-foreground">{{ formatCurrency(tax_total) }}</span>
                            </div>
                            <div class="flex justify-between text-sm">
                                <span class="text-muted-foreground">Total Payments</span>
                                <span class="font-medium text-green-600 dark:text-green-400">{{ formatCurrency(credits_total) }}</span>
                            </div>
                            <div class="flex justify-between text-lg font-bold pt-2 border-t border-border">
                                <span class="text-foreground">Balance Due</span>
                                <span :class="balance > 0 ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400'">
                                    {{ formatCurrency(balance) }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Footer -->
                <div class="p-6 md:p-8 text-center text-xs text-muted-foreground border-t border-border">
                    <p>Thank you for staying with us.</p>
                    <p class="mt-1">{{ branch.name }} &mdash; {{ branch.city }}</p>
                    <p class="mt-1">Generated {{ formatDateTime(generated_at) }}</p>
                </div>
            </div>
        </div>
    </div>
</template>

<style>
    @media print {
        .no-print { display: none !important; }
        body { background: white !important; }
    }
</style>
