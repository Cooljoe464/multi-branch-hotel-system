<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';

interface TabletOrder { id: number; status: string; items: any[]; total: number; payment_status: string; created_at: string; }

const props = defineProps<{ order: TabletOrder; }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Track Order', href: '#' }] } });

const statusSteps = ['pending', 'preparing', 'ready', 'delivered'];
const currentStep = ref(statusSteps.indexOf(props.order.status));

const statusColor: Record<string, string> = { pending: 'bg-yellow-500 dark:bg-yellow-600', preparing: 'bg-blue-500 dark:bg-blue-600', ready: 'bg-green-500 dark:bg-green-600', delivered: 'bg-green-700 dark:bg-green-800' };
import { formatCurrency, getCurrencySymbol } from '@/lib/format';
const page = usePage();
const branchSymbol = computed(() => (page.props.branch?.current as any)?.currency_symbol || '$');
const resolveSymbol = (code?: string) => getCurrencySymbol(code || 'NGN') || branchSymbol.value;
const formatPrice = (cents: number, currencyCode?: string) => formatCurrency(cents, resolveSymbol(currencyCode));

let echo: any = null;

onMounted(() => {
    if (typeof window !== 'undefined' && typeof window.initEcho === 'function') {
        echo = window.initEcho();
        echo.channel(`tablet.order.${props.order.id}`).listen('.order.status.updated', (data: any) => {
            props.order.status = data.status;
            currentStep.value = statusSteps.indexOf(data.status);
        });
    }
});

onUnmounted(() => {
    if (echo) echo.disconnect();
});
</script>

<template>
    <Head title="Track Order" />
<div class="min-h-screen bg-background p-6 flex flex-col items-center">
    <h1 class="text-2xl font-bold text-foreground mb-2">Order #{{ order.id }}</h1>
    <Badge variant="outline" class="capitalize mb-6">{{ order.status }}</Badge>

    <div class="w-full max-w-md mb-8">
        <div class="flex justify-between items-center mb-4">
            <div v-for="(step, i) in statusSteps" :key="step" class="flex flex-col items-center">
                <div class="w-8 h-8 rounded-full flex items-center justify-center text-white text-sm font-bold" :class="i <= currentStep ? statusColor[step] : 'bg-muted'">{{ i + 1 }}</div>
                <span class="text-xs text-muted-foreground mt-1 capitalize">{{ step }}</span>
            </div>
        </div>
        <div class="h-2 bg-muted rounded-full"><div class="h-2 rounded-full transition-all duration-500" :class="statusColor[order.status] || 'bg-muted'" :style="{ width: `${((currentStep + 1) / statusSteps.length) * 100}%` }"></div></div>
    </div>

    <div class="w-full max-w-md bg-card rounded-lg border border-border p-4">
        <h2 class="font-semibold text-foreground mb-2">Items</h2>
        <div v-for="(item, i) in order.items" :key="i" class="flex justify-between py-1 text-sm">
            <span class="text-muted-foreground">{{ item.name }} x{{ item.quantity }}</span>
            <span class="text-foreground">{{ formatPrice(item.total || item.unit_price * item.quantity) }}</span>
        </div>
        <div class="border-t border-border mt-2 pt-2 flex justify-between font-bold"><span>Total</span><span>{{ formatPrice(order.total) }}</span></div>
    </div>
</div>
</template>
