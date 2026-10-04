<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Analytics', href: '/analytics' },
            { title: 'Tax Liability', href: '/reports/tax-liability' },
        ],
    },
});

const props = defineProps<{
    taxSummary: Array<{
        business_date: string;
        total_tax: number;
    }>;
}>();

import { formatCurrency } from '@/lib/format';

const page = usePage();
const branch = computed(() => page.props.branch?.current);
const currencySymbol = computed(() => branch.value?.currency_symbol || '₦');

function formatCents(cents: number): string {
    return formatCurrency(cents, currencySymbol.value, 0);
}

function formatDate(dateStr: string): string {
    return new Date(dateStr).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
}

const totalTax = props.taxSummary.reduce((sum, row) => sum + row.total_tax, 0);
</script>

<template>
    <Head title="Tax Liability" />

    <div
        class="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-4 md:p-6"
    >
        <div>
            <h1 class="text-foreground text-2xl font-bold">
                Tax Liability Report
            </h1>
            <p class="text-muted-foreground text-sm">
                Daily tax collection summary (last 30 days)
            </p>
        </div>

        <!-- Total Tax Card -->
        <div
            class="bg-card text-card-foreground rounded-xl border p-4 shadow-sm md:p-6"
        >
            <div
                class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between"
            >
                <h2 class="text-foreground text-lg font-semibold">
                    Total Tax Liability (30 days)
                </h2>
                <span class="text-3xl font-bold text-amber-600">{{
                    formatCents(totalTax)
                }}</span>
            </div>
        </div>

        <div class="bg-card text-card-foreground rounded-xl border shadow-sm">
            <!-- Mobile: Card View -->
            <div class="divide-border divide-y md:hidden">
                <div
                    v-for="row in taxSummary"
                    :key="row.business_date"
                    class="flex items-center justify-between p-4"
                >
                    <span class="text-foreground text-sm font-medium">{{
                        formatDate(row.business_date)
                    }}</span>
                    <span class="text-foreground text-sm font-medium">{{
                        formatCents(row.total_tax)
                    }}</span>
                </div>
                <div
                    v-if="taxSummary.length === 0"
                    class="text-muted-foreground p-4 py-8 text-center"
                >
                    No tax data available.
                </div>
            </div>

            <!-- Desktop: Table View -->
            <div class="hidden overflow-x-auto md:block">
                <table class="w-full caption-bottom text-sm">
                    <thead class="bg-muted/50 border-b [&_tr]:border-b">
                        <tr>
                            <th
                                class="text-muted-foreground h-12 px-4 text-left font-medium"
                            >
                                Date
                            </th>
                            <th
                                class="text-muted-foreground h-12 px-4 text-right font-medium"
                            >
                                Tax Collected
                            </th>
                        </tr>
                    </thead>
                    <tbody class="[&_tr:last-child]:border-0">
                        <tr
                            v-for="row in taxSummary"
                            :key="row.business_date"
                            class="hover:bg-muted/50 border-b transition-colors"
                        >
                            <td class="text-foreground p-4 font-medium">
                                {{ formatDate(row.business_date) }}
                            </td>
                            <td class="text-foreground p-4 text-right">
                                {{ formatCents(row.total_tax) }}
                            </td>
                        </tr>
                        <tr v-if="taxSummary.length === 0">
                            <td
                                colspan="2"
                                class="text-muted-foreground p-4 py-8 text-center"
                            >
                                No tax data available.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
