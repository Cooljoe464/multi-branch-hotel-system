<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import LocaleSwitcher from '@/components/LocaleSwitcher.vue';
import { t } from '@/lib/locale';

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
<Head :title="`${t('portal.payment_title')} ${formatPrice(payment.amount)}`" />

<div class="min-h-screen bg-background">
    <div class="bg-card border-b border-border px-4 py-3 sm:px-6">
        <div class="mx-auto max-w-md flex items-center justify-between">
            <div>
                <h1 class="text-lg font-bold text-foreground">{{ t('portal.payment_title') }}</h1>
                <p class="text-sm text-muted-foreground">{{ reservation.guest_name }}</p>
            </div>
            <LocaleSwitcher />
        </div>
    </div>

    <div class="mx-auto max-w-md px-4 py-8 sm:px-6">
        <div class="rounded-lg border border-border bg-card p-6 text-center">
            <div class="mb-4 text-sm text-muted-foreground">{{ t('portal.amount_to_pay') }}</div>
            <div class="mb-6 text-3xl font-bold text-foreground">{{ formatPrice(payment.amount) }}</div>

            <div class="mb-6 flex justify-center">
                <div class="rounded-lg border border-border bg-white p-4" v-html="payment.qr_code"></div>
            </div>

            <p class="mb-4 text-sm text-muted-foreground">
                {{ t('portal.scan_qr') }}
            </p>

            <a
                :href="payment.authorization_url"
                target="_blank"
                rel="noopener noreferrer"
                class="mb-4 block w-full rounded-lg bg-primary py-3 text-sm font-medium text-primary-foreground hover:bg-primary/90"
            >
                {{ t('portal.pay_with_card') }}
            </a>

            <div class="border-t border-border pt-4 mt-4">
                <div class="flex justify-between text-sm">
                    <span class="text-muted-foreground">{{ t('portal.folio_row') }}</span>
                    <span class="text-foreground">{{ folio.folio_number }}</span>
                </div>
                <div class="flex justify-between text-sm">
                    <span class="text-muted-foreground">{{ t('portal.remaining_balance') }}</span>
                    <span class="text-foreground">{{ formatPrice(folio.balance) }}</span>
                </div>
                <div class="flex justify-between text-sm">
                    <span class="text-muted-foreground">{{ t('portal.reference') }}</span>
                    <span class="text-foreground font-mono text-xs">{{ payment.reference }}</span>
                </div>
            </div>
        </div>

        <div class="mt-6 text-center">
            <Link :href="`/guest/folio/${reservation.confirmation_number}`" class="text-sm text-muted-foreground hover:text-foreground">
                {{ t('portal.back_to_folio') }}
            </Link>
        </div>
    </div>
</div>
</template>
