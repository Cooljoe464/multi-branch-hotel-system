<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { ArrowLeft } from '@lucide/vue';
import { formatDate, formatDateTime } from '@/lib/dates';

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
const formatCurrency = (amount: number) => formatCurrencyRaw(amount, props.reservation.branch.currency_symbol);

const getStatusBadgeClass = (status: string) => {
    const classes: Record<string, string> = {
        pending: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300',
        confirmed: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300',
        reserved: 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-300',
        checked_in: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300',
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
        platinum: 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-300',
        diamond: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300',
    };
    return classes[status] || '';
};

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

const debits = () => props.transactions.filter((t) => t.type === 'debit' && !t.is_voided);
const credits = () => props.transactions.filter((t) => t.type === 'credit' && !t.is_voided);
const debitsTotal = () => debits().reduce((sum, t) => sum + t.amount, 0);
const creditsTotal = () => credits().reduce((sum, t) => sum + t.amount, 0);

const goBack = () => {
    window.history.back();
};
</script>

<template>
    <Head :title="`Folio - ${reservation.confirmation_number}`" />

    <div class="min-h-screen bg-background">
        <!-- Header -->
        <header class="bg-card shadow">
            <div class="max-w-4xl mx-auto px-4 py-6">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <Button variant="ghost" size="sm" @click="goBack()" class="gap-1 text-muted-foreground hover:text-foreground">
                            <ArrowLeft class="size-4" /> Back
                        </Button>
                        <div>
                            <h1 class="text-2xl font-bold text-foreground">{{ reservation.branch.name }}</h1>
                            <p class="text-muted-foreground">{{ reservation.branch.city }}</p>
                        </div>
                    </div>
                    <div class="text-right">
                        <p class="text-sm text-muted-foreground">Confirmation</p>
                        <p class="font-mono font-bold text-lg text-foreground">{{ reservation.confirmation_number }}</p>
                        <Link v-if="reservation.status === 'checked_in'" :href="`/guest/order/${reservation.confirmation_number}`" class="mt-1 inline-block text-xs text-primary hover:underline">
                            Order Food &amp; Services &rarr;
                        </Link>
                    </div>
                </div>
            </div>
        </header>

        <main class="max-w-4xl mx-auto px-4 py-8">
            <!-- Status Banner -->
            <div class="mb-6 p-4 rounded-lg" :class="{
                'bg-green-50 border border-green-200 dark:bg-green-900/30 dark:border-green-800': reservation.status === 'checked_in',
                'bg-blue-50 border border-blue-200 dark:bg-blue-900/30 dark:border-blue-800': reservation.status === 'confirmed' || reservation.status === 'reserved',
                'bg-muted border border-border': reservation.status === 'checked_out',
            }">
                <div class="flex items-center justify-between">
                    <div>
                        <Badge :class="getStatusBadgeClass(reservation.status)">
                            {{ reservation.status.replace('_', ' ').toUpperCase() }}
                        </Badge>
                        <Badge v-if="guest && guest.vip_status !== 'none'" class="ml-2" :class="getVipBadgeClass(guest.vip_status)">
                            {{ guest.vip_status.toUpperCase() }} VIP
                        </Badge>
                    </div>
                    <div class="text-right">
                        <p class="text-sm text-muted-foreground">Guest</p>
                        <p class="font-medium text-foreground">{{ reservation.guest_name }}</p>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Stay Details -->
                <div class="bg-card rounded-lg shadow p-6">
                    <h2 class="text-lg font-semibold mb-4 text-foreground">Stay Details</h2>
                    <dl class="space-y-3">
                        <div class="flex justify-between">
                            <dt class="text-muted-foreground">Room Type</dt>
                            <dd class="font-medium text-foreground">{{ reservation.room_type.name }}</dd>
                        </div>
                        <div v-if="reservation.room" class="flex justify-between">
                            <dt class="text-muted-foreground">Room Number</dt>
                            <dd class="font-medium text-foreground">{{ reservation.room.number }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-muted-foreground">Check-in</dt>
                            <dd class="text-foreground">{{ formatDate(reservation.check_in_date) }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-muted-foreground">Check-out</dt>
                            <dd class="text-foreground">{{ formatDate(reservation.check_out_date) }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-muted-foreground">Nights</dt>
                            <dd class="text-foreground">{{ reservation.nights }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-muted-foreground">Guests</dt>
                            <dd class="text-foreground">{{ reservation.adults }} adults, {{ reservation.children }} children</dd>
                        </div>
                    </dl>
                </div>

                <!-- Contact Info -->
                <div class="bg-card rounded-lg shadow p-6">
                    <h2 class="text-lg font-semibold mb-4 text-foreground">Contact Information</h2>
                    <dl class="space-y-3">
                        <div class="flex justify-between">
                            <dt class="text-muted-foreground">Email</dt>
                            <dd class="text-foreground">{{ reservation.guest_email || '-' }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-muted-foreground">Phone</dt>
                            <dd class="text-foreground">{{ reservation.guest_phone || '-' }}</dd>
                        </div>
                        <div v-if="reservation.branch.phone" class="flex justify-between">
                            <dt class="text-muted-foreground">Property Phone</dt>
                            <dd class="text-foreground">{{ reservation.branch.phone }}</dd>
                        </div>
                        <div v-if="reservation.branch.email" class="flex justify-between">
                            <dt class="text-muted-foreground">Property Email</dt>
                            <dd class="text-foreground">{{ reservation.branch.email }}</dd>
                        </div>
                        <div v-if="reservation.branch.address" class="flex justify-between">
                            <dt class="text-muted-foreground">Address</dt>
                            <dd class="text-right text-foreground">{{ reservation.branch.address }}</dd>
                        </div>
                    </dl>
                </div>

                <!-- Folio Transactions -->
                <div class="bg-card rounded-lg shadow p-6 md:col-span-2">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-lg font-semibold text-foreground">Folio</h2>
                        <Badge v-if="folio" variant="outline" class="bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-300">
                            {{ folio.folio_number }}
                        </Badge>
                    </div>

                    <div v-if="transactions.length > 0">
                        <table class="min-w-full divide-y divide-border mb-4">
                            <thead class="bg-muted/50">
                                <tr>
                                    <th class="px-3 py-2 text-left text-xs font-medium text-muted-foreground uppercase">Date</th>
                                    <th class="px-3 py-2 text-left text-xs font-medium text-muted-foreground uppercase">Description</th>
                                    <th class="px-3 py-2 text-right text-xs font-medium text-muted-foreground uppercase">Debit</th>
                                    <th class="px-3 py-2 text-right text-xs font-medium text-muted-foreground uppercase">Credit</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                <tr v-for="tx in transactions" :key="tx.id" :class="{ 'opacity-50 line-through': tx.is_voided }">
                                    <td class="px-3 py-2 text-sm text-muted-foreground">
                                        {{ formatDate(tx.created_at) }}
                                    </td>
                                    <td class="px-3 py-2 text-sm">
                                        <span class="text-foreground">{{ tx.description }}</span>
                                        <span class="ml-1 text-xs text-muted-foreground">({{ getCategoryLabel(tx.category) }})</span>
                                    </td>
                                    <td class="px-3 py-2 text-sm text-right font-medium text-red-600 dark:text-red-400">
                                        {{ tx.type === 'debit' && !tx.is_voided ? formatCurrency(tx.amount) : '' }}
                                    </td>
                                    <td class="px-3 py-2 text-sm text-right font-medium text-green-600 dark:text-green-400">
                                        {{ tx.type === 'credit' && !tx.is_voided ? formatCurrency(tx.amount) : '' }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>

                        <!-- Summary -->
                        <div class="border-t border-border pt-3 space-y-2">
                            <div class="flex justify-between text-sm">
                                <span class="text-muted-foreground">Total Charges</span>
                                <span class="font-medium text-red-600 dark:text-red-400">{{ formatCurrency(debitsTotal()) }}</span>
                            </div>
                            <div class="flex justify-between text-sm">
                                <span class="text-muted-foreground">Total Payments</span>
                                <span class="font-medium text-green-600 dark:text-green-400">{{ formatCurrency(creditsTotal()) }}</span>
                            </div>
                            <div class="flex justify-between text-lg font-semibold pt-2 border-t border-border">
                                <span class="text-foreground">Balance Due</span>
                                <span :class="folio && folio.balance > 0 ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400'">
                                    {{ formatCurrency(folio ? folio.balance : 0) }}
                                </span>
                            </div>
                            <div v-if="folio && folio.balance > 0 && reservation.status === 'checked_in'" class="pt-3">
                                <a
                                    :href="`/guest/folio/${reservation.confirmation_number}/pay?amount=${folio.balance}`"
                                    class="block w-full rounded-lg bg-primary py-3 text-center text-sm font-medium text-primary-foreground hover:bg-primary/90"
                                >
                                    Pay Now via QR Code
                                </a>
                            </div>
                        </div>
                    </div>

                    <div v-else class="text-center py-8 text-muted-foreground">
                        <p>No transactions on this folio yet.</p>
                    </div>
                </div>

                <!-- Financial Summary -->
                <div class="bg-card rounded-lg shadow p-6 md:col-span-2">
                    <h2 class="text-lg font-semibold mb-4 text-foreground">Financial Summary</h2>
                    <dl class="space-y-3">
                        <div class="flex justify-between">
                            <dt class="text-muted-foreground">Room Rate</dt>
                            <dd class="text-foreground">{{ formatCurrency(reservation.room_rate) }} / night</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-muted-foreground">Subtotal ({{ reservation.nights }} nights)</dt>
                            <dd class="text-foreground">{{ formatCurrency(reservation.room_rate * reservation.nights) }}</dd>
                        </div>
                        <div class="flex justify-between text-lg font-semibold pt-3 border-t border-border">
                            <dt class="text-foreground">Total</dt>
                            <dd class="text-foreground">{{ formatCurrency(reservation.total_amount) }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-muted-foreground">Amount Paid</dt>
                            <dd class="text-green-600 dark:text-green-400">{{ formatCurrency(reservation.amount_paid) }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-muted-foreground">Balance Due</dt>
                            <dd class="font-medium text-foreground">{{ formatCurrency(reservation.total_amount - reservation.amount_paid) }}</dd>
                        </div>
                    </dl>
                </div>

                <!-- Timeline -->
                <div class="bg-card rounded-lg shadow p-6 md:col-span-2">
                    <h2 class="text-lg font-semibold mb-4 text-foreground">Timeline</h2>
                    <dl class="space-y-3">
                        <div class="flex justify-between">
                            <dt class="text-muted-foreground">Booked On</dt>
                            <dd class="text-foreground">{{ formatDateTime(reservation.created_at) }}</dd>
                        </div>
                        <div v-if="reservation.actual_check_in_at" class="flex justify-between">
                            <dt class="text-muted-foreground">Actual Check-in</dt>
                            <dd class="text-foreground">{{ formatDateTime(reservation.actual_check_in_at) }}</dd>
                        </div>
                        <div v-if="reservation.actual_check_out_at" class="flex justify-between">
                            <dt class="text-muted-foreground">Actual Check-out</dt>
                            <dd class="text-foreground">{{ formatDateTime(reservation.actual_check_out_at) }}</dd>
                        </div>
                    </dl>
                </div>

                <!-- Guest Profile (if linked) -->
                <div v-if="guest && guest.total_stays > 0" class="bg-card rounded-lg shadow p-6 md:col-span-2">
                    <h2 class="text-lg font-semibold mb-4 text-foreground">Guest Profile</h2>
                    <div class="grid grid-cols-3 gap-4 text-center">
                        <div>
                            <p class="text-2xl font-bold text-foreground">{{ guest.total_stays }}</p>
                            <p class="text-sm text-muted-foreground">Total Stays</p>
                        </div>
                        <div>
                            <p class="text-2xl font-bold text-foreground">{{ guest.total_nights }}</p>
                            <p class="text-sm text-muted-foreground">Total Nights</p>
                        </div>
                        <div>
                            <p class="text-2xl font-bold text-foreground">{{ formatCurrency(guest.total_spent) }}</p>
                            <p class="text-sm text-muted-foreground">Total Spent</p>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</template>
