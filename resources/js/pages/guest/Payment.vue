<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';

interface Reservation {
    confirmation_number: string;
    guest_name: string;
}

interface Payment {
    amount: number;
    reference: string;
    authorization_url: string;
    qr_code: string;
}

interface Folio {
    balance: number;
    folio_number: string;
}

const props = defineProps<{
    reservation: Reservation;
    payment: Payment;
    folio: Folio;
}>();

import { formatCurrency, getCurrencySymbol } from '@/lib/format';
const page = usePage();
const branchSymbol = computed(() => (page.props.branch?.current as any)?.currency_symbol || '$');
const resolveSymbol = (code?: string) => getCurrencySymbol(code || 'NGN') || branchSymbol.value;
const formatPrice = (cents: number, currencyCode?: string) => formatCurrency(cents, resolveSymbol(currencyCode));
</script>

<template>
<Head :title="`Pay ${formatPrice(payment.amount)}`" />

<div class="min-h-screen bg-background">
    <div class="bg-card border-b border-border px-4 py-3 sm:px-6">
        <div class="mx-auto max-w-md">
            <h1 class="text-lg font-bold text-foreground">Complete Payment</h1>
            <p class="text-sm text-muted-foreground">{{ reservation.guest_name }}</p>
        </div>
    </div>

    <div class="mx-auto max-w-md px-4 py-8 sm:px-6">
        <div class="rounded-lg border border-border bg-card p-6 text-center">
            <div class="mb-4 text-sm text-muted-foreground">Amount to Pay</div>
            <div class="mb-6 text-3xl font-bold text-foreground">{{ formatPrice(payment.amount) }}</div>

            <div class="mb-6 flex justify-center">
                <div class="rounded-lg border border-border bg-white p-4" v-html="payment.qr_code"></div>
            </div>

            <p class="mb-4 text-sm text-muted-foreground">
                Scan this QR code with your phone camera to complete payment via Paystack
            </p>

            <a
                :href="payment.authorization_url"
                target="_blank"
                rel="noopener noreferrer"
                class="mb-4 block w-full rounded-lg bg-primary py-3 text-sm font-medium text-primary-foreground hover:bg-primary/90"
            >
                Pay with Card &rarr;
            </a>

            <div class="border-t border-border pt-4 mt-4">
                <div class="flex justify-between text-sm">
                    <span class="text-muted-foreground">Folio</span>
                    <span class="text-foreground">{{ folio.folio_number }}</span>
                </div>
                <div class="flex justify-between text-sm">
                    <span class="text-muted-foreground">Remaining Balance</span>
                    <span class="text-foreground">{{ formatPrice(folio.balance) }}</span>
                </div>
                <div class="flex justify-between text-sm">
                    <span class="text-muted-foreground">Reference</span>
                    <span class="text-foreground font-mono text-xs">{{ payment.reference }}</span>
                </div>
            </div>
        </div>

        <div class="mt-6 text-center">
            <Link :href="`/guest/folio/${reservation.confirmation_number}`" class="text-sm text-muted-foreground hover:text-foreground">
                &larr; Back to Folio
            </Link>
        </div>
    </div>
</div>
</template>
