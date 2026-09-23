<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import LocaleSwitcher from '@/components/LocaleSwitcher.vue';
import { t } from '@/lib/locale';

interface Reservation {
    confirmation_number: string;
    guest_name: string;
}

interface OrderItem {
    name: string;
    quantity: number;
    unit_price: number;
    total: number;
    notes: string | null;
}

interface Order {
    id: number;
    items: OrderItem[];
    subtotal: number;
    tax_amount: number;
    total: number;
    status: string;
    created_at: string;
}

const props = defineProps<{
    reservation: Reservation;
    order: Order;
}>();

import { formatCurrency, getCurrencySymbol } from '@/lib/format';
const page = usePage();
const branchSymbol = computed(() => (page.props.branch?.current as any)?.currency_symbol || '$');
const resolveSymbol = (code?: string) => getCurrencySymbol(code || 'NGN') || branchSymbol.value;
const formatPrice = (cents: number, currencyCode?: string) => formatCurrency(cents, resolveSymbol(currencyCode));

const statusSteps = ['pending', 'preparing', 'ready', 'delivered'];
const statusLabels = computed<Record<string, string>>(() => ({
    pending: t('portal.order_received'),
    preparing: t('portal.being_prepared'),
    ready: t('portal.ready'),
    delivered: t('portal.delivered'),
    cancelled: t('portal.cancelled'),
}));

const currentStepIndex = computed(() => statusSteps.indexOf(props.order.status));
</script>

<template>
<Head :title="`Order #${order.id} - ${t('portal.tracking_title')}`" />

<div class="min-h-screen bg-background">
    <div class="bg-card border-b border-border px-4 py-3 sm:px-6">
        <div class="mx-auto max-w-2xl">
            <div class="flex items-center justify-between">
                <Link :href="`/guest/order/${reservation.confirmation_number}`" class="text-xs text-muted-foreground hover:text-foreground">&larr; {{ t('portal.order_more') }}</Link>
                <LocaleSwitcher />
            </div>
            <h1 class="text-lg font-bold text-foreground">Order #{{ order.id }}</h1>
            <p class="text-sm text-muted-foreground">{{ t('portal.placed_at', { time: new Date(order.created_at).toLocaleTimeString() }) }}</p>
        </div>
    </div>

    <div class="mx-auto max-w-2xl px-4 py-6 sm:px-6">
        <div v-if="order.status === 'cancelled'" class="rounded-lg border border-destructive/50 bg-destructive/10 p-4 text-center">
            <p class="font-medium text-destructive">{{ t('portal.order_cancelled') }}</p>
        </div>

        <template v-else>
            <div class="mb-6 flex items-center justify-between">
                <div
                    v-for="(step, i) in statusSteps"
                    :key="step"
                    class="flex flex-1 flex-col items-center"
                >
                    <div
                        class="flex h-8 w-8 items-center justify-center rounded-full text-xs font-bold"
                        :class="i <= currentStepIndex ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground'"
                    >
                        {{ i + 1 }}
                    </div>
                    <div class="mt-1 text-center text-[10px] text-muted-foreground">{{ statusLabels[step] }}</div>
                </div>
            </div>

            <div class="rounded-lg border border-border bg-card p-4">
                <h2 class="mb-3 font-semibold text-foreground">{{ t('portal.order_items') }}</h2>
                <div class="space-y-2">
                    <div v-for="(item, i) in order.items" :key="i" class="flex justify-between text-sm">
                        <span class="text-foreground">{{ item.name }} &times;{{ item.quantity }}</span>
                        <span class="font-medium text-foreground">{{ formatPrice(item.total) }}</span>
                    </div>
                </div>
                <div class="mt-3 border-t border-border pt-3 space-y-1">
                    <div class="flex justify-between text-sm">
                        <span class="text-muted-foreground">{{ t('portal.subtotal') }}</span>
                        <span class="text-foreground">{{ formatPrice(order.subtotal) }}</span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-muted-foreground">{{ t('portal.tax') }}</span>
                        <span class="text-foreground">{{ formatPrice(order.tax_amount) }}</span>
                    </div>
                    <div class="flex justify-between text-base font-bold">
                        <span class="text-foreground">{{ t('common.total') }}</span>
                        <span class="text-foreground">{{ formatPrice(order.total) }}</span>
                    </div>
                </div>
            </div>
        </template>

        <div class="mt-6 text-center">
            <Link :href="`/guest/folio/${reservation.confirmation_number}`" class="text-sm text-muted-foreground hover:text-foreground">
                {{ t('common.view_folio') }}
            </Link>
        </div>
    </div>
</div>
</template>
