<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Analytics', href: '/analytics' },
            { title: 'Profit & Loss', href: '/reports/pnl' },
        ],
    },
});

const props = defineProps<{
    summary: {
        room_revenue: number;
        pos_revenue: number;
        other_charges: number;
        total_tax: number;
        total_payments: number;
        waste_cost: number;
        net_revenue: number;
        total_expenses: number;
        net_income: number;
    };
}>();

import { formatCurrency } from '@/lib/format';

const page = usePage();
const branch = computed(() => page.props.branch?.current);
const currencySymbol = computed(() => branch.value?.currency_symbol || '₦');

function formatCents(cents: number): string {
    return formatCurrency(cents, currencySymbol.value, 0);
}
</script>

<template>
    <Head title="Profit & Loss" />

    <div
        class="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-4"
    >
        <div>
            <h1 class="text-foreground text-2xl font-bold">
                Profit &amp; Loss Statement
            </h1>
            <p class="text-muted-foreground text-sm">
                Consolidated financial summary
            </p>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <!-- Revenue Section -->
            <div
                class="bg-card text-card-foreground rounded-xl border p-6 shadow-sm"
            >
                <h2
                    class="mb-4 text-lg font-semibold text-emerald-600 dark:text-emerald-400"
                >
                    Revenue
                </h2>
                <div class="space-y-3">
                    <div
                        class="border-border flex justify-between border-b pb-2"
                    >
                        <span class="text-muted-foreground">Room Revenue</span>
                        <span class="text-foreground font-medium">{{
                            formatCents(summary.room_revenue)
                        }}</span>
                    </div>
                    <div
                        class="border-border flex justify-between border-b pb-2"
                    >
                        <span class="text-muted-foreground">POS Revenue</span>
                        <span class="text-foreground font-medium">{{
                            formatCents(summary.pos_revenue)
                        }}</span>
                    </div>
                    <div
                        class="border-border flex justify-between border-b pb-2"
                    >
                        <span class="text-muted-foreground">Other Charges</span>
                        <span class="text-foreground font-medium">{{
                            formatCents(summary.other_charges)
                        }}</span>
                    </div>
                    <div
                        class="border-border flex justify-between border-b pb-2"
                    >
                        <span class="text-muted-foreground"
                            >Total Tax Collected</span
                        >
                        <span class="text-foreground font-medium">{{
                            formatCents(summary.total_tax)
                        }}</span>
                    </div>
                    <div class="flex justify-between pt-2 text-lg font-bold">
                        <span class="text-foreground">Net Revenue</span>
                        <span class="text-emerald-600 dark:text-emerald-400">{{
                            formatCents(summary.net_revenue)
                        }}</span>
                    </div>
                </div>
            </div>

            <!-- Expenses Section -->
            <div
                class="bg-card text-card-foreground rounded-xl border p-6 shadow-sm"
            >
                <h2
                    class="mb-4 text-lg font-semibold text-red-600 dark:text-red-400"
                >
                    Expenses
                </h2>
                <div class="space-y-3">
                    <div
                        class="border-border flex justify-between border-b pb-2"
                    >
                        <span class="text-muted-foreground"
                            >Kitchen Waste & Spoilage</span
                        >
                        <span class="text-foreground font-medium">{{
                            formatCents(summary.waste_cost)
                        }}</span>
                    </div>
                    <div class="flex justify-between pt-2 text-lg font-bold">
                        <span class="text-foreground">Total Expenses</span>
                        <span class="text-red-600 dark:text-red-400">{{
                            formatCents(summary.total_expenses)
                        }}</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Net Income -->
        <div
            class="bg-card text-card-foreground rounded-xl border p-6 shadow-sm"
        >
            <div class="flex items-center justify-between">
                <h2 class="text-foreground text-xl font-bold">Net Income</h2>
                <span
                    class="text-3xl font-bold"
                    :class="
                        summary.net_income >= 0
                            ? 'text-emerald-600 dark:text-emerald-400'
                            : 'text-red-600 dark:text-red-400'
                    "
                >
                    {{ formatCents(summary.net_income) }}
                </span>
            </div>
            <p class="text-muted-foreground mt-2 text-sm">
                Net Revenue ({{ formatCents(summary.net_revenue) }}) - Total
                Expenses ({{ formatCents(summary.total_expenses) }})
            </p>
        </div>

        <!-- Summary Cards -->
        <div class="grid gap-4 md:grid-cols-3">
            <div
                class="bg-card text-card-foreground rounded-xl border p-6 shadow-sm"
            >
                <h3 class="text-muted-foreground text-sm font-medium">
                    Total Payments Received
                </h3>
                <p
                    class="mt-2 text-2xl font-bold text-indigo-600 dark:text-indigo-400"
                >
                    {{ formatCents(summary.total_payments) }}
                </p>
            </div>
            <div
                class="bg-card text-card-foreground rounded-xl border p-6 shadow-sm"
            >
                <h3 class="text-muted-foreground text-sm font-medium">
                    Tax Liability
                </h3>
                <p
                    class="mt-2 text-2xl font-bold text-amber-600 dark:text-amber-400"
                >
                    {{ formatCents(summary.total_tax) }}
                </p>
            </div>
            <div
                class="bg-card text-card-foreground rounded-xl border p-6 shadow-sm"
            >
                <h3 class="text-muted-foreground text-sm font-medium">
                    Revenue per Dollar of Expense
                </h3>
                <p class="text-foreground mt-2 text-2xl font-bold">
                    {{
                        summary.total_expenses > 0
                            ? (
                                  summary.net_revenue / summary.total_expenses
                              ).toFixed(1)
                            : '∞'
                    }}x
                </p>
            </div>
        </div>
    </div>
</template>
