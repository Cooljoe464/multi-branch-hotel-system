<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';

interface TabletOrder {
    id: number;
    status: string;
    items: any[];
    total: number;
    payment_status: string;
    created_at: string;
}

const props = defineProps<{ order: TabletOrder }>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Track Order', href: '#' }] },
});

const statusSteps = ['pending', 'preparing', 'ready', 'delivered'];
const currentStep = ref(statusSteps.indexOf(props.order.status));

const statusColor: Record<string, string> = {
    pending: 'bg-yellow-500 dark:bg-yellow-600',
    preparing: 'bg-blue-500 dark:bg-blue-600',
    ready: 'bg-green-500 dark:bg-green-600',
    delivered: 'bg-green-700 dark:bg-green-800',
};
import { formatCurrency, getCurrencySymbol } from '@/lib/format';
const page = usePage();
const branchSymbol = computed(
    () => (page.props.branch?.current as any)?.currency_symbol || '$',
);
const resolveSymbol = (code?: string) =>
    getCurrencySymbol(code || 'NGN') || branchSymbol.value;
const formatPrice = (cents: number, currencyCode?: string) =>
    formatCurrency(cents, resolveSymbol(currencyCode));

let echo: any = null;

onMounted(() => {
    if (
        typeof window !== 'undefined' &&
        typeof window.initEcho === 'function'
    ) {
        echo = window.initEcho();
        echo.channel(`tablet.order.${props.order.id}`).listen(
            '.order.status.updated',
            (data: any) => {
                props.order.status = data.status;
                currentStep.value = statusSteps.indexOf(data.status);
            },
        );
    }
});

onUnmounted(() => {
    if (echo) echo.disconnect();
});
</script>

<template>
    <Head title="Track Order" />
    <div class="bg-background flex min-h-screen flex-col items-center p-6">
        <h1 class="text-foreground mb-2 text-2xl font-bold">
            Order #{{ order.id }}
        </h1>
        <Badge variant="outline" class="mb-6 capitalize">{{
            order.status
        }}</Badge>

        <div class="mb-8 w-full max-w-md">
            <div class="mb-4 flex items-center justify-between">
                <div
                    v-for="(step, i) in statusSteps"
                    :key="step"
                    class="flex flex-col items-center"
                >
                    <div
                        class="flex h-8 w-8 items-center justify-center rounded-full text-sm font-bold text-white"
                        :class="
                            i <= currentStep ? statusColor[step] : 'bg-muted'
                        "
                    >
                        {{ i + 1 }}
                    </div>
                    <span
                        class="text-muted-foreground mt-1 text-xs capitalize"
                        >{{ step }}</span
                    >
                </div>
            </div>
            <div class="bg-muted h-2 rounded-full">
                <div
                    class="h-2 rounded-full transition-all duration-500"
                    :class="statusColor[order.status] || 'bg-muted'"
                    :style="{
                        width: `${((currentStep + 1) / statusSteps.length) * 100}%`,
                    }"
                ></div>
            </div>
        </div>

        <div
            class="bg-card border-border w-full max-w-md rounded-lg border p-4"
        >
            <h2 class="text-foreground mb-2 font-semibold">Items</h2>
            <div
                v-for="(item, i) in order.items"
                :key="i"
                class="flex justify-between py-1 text-sm"
            >
                <span class="text-muted-foreground"
                    >{{ item.name }} x{{ item.quantity }}</span
                >
                <span class="text-foreground">{{
                    formatPrice(item.total || item.unit_price * item.quantity)
                }}</span>
            </div>
            <div
                class="border-border mt-2 flex justify-between border-t pt-2 font-bold"
            >
                <span>Total</span><span>{{ formatPrice(order.total) }}</span>
            </div>
        </div>
    </div>
</template>
