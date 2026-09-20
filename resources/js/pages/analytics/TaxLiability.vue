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

    <div class="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-4 md:p-6">
        <div>
            <h1 class="text-2xl font-bold text-foreground">Tax Liability Report</h1>
            <p class="text-sm text-muted-foreground">Daily tax collection summary (last 30 days)</p>
        </div>

        <!-- Total Tax Card -->
        <div class="rounded-xl border bg-card p-4 md:p-6 text-card-foreground shadow-sm">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <h2 class="text-lg font-semibold text-foreground">Total Tax Liability (30 days)</h2>
                <span class="text-3xl font-bold text-amber-600">{{ formatCents(totalTax) }}</span>
            </div>
        </div>

        <div class="rounded-xl border bg-card text-card-foreground shadow-sm">
            <!-- Mobile: Card View -->
            <div class="md:hidden divide-y divide-border">
                <div v-for="row in taxSummary" :key="row.business_date" class="p-4 flex items-center justify-between">
                    <span class="text-sm font-medium text-foreground">{{ formatDate(row.business_date) }}</span>
                    <span class="text-sm font-medium text-foreground">{{ formatCents(row.total_tax) }}</span>
                </div>
                <div v-if="taxSummary.length === 0" class="p-4 py-8 text-center text-muted-foreground">
                    No tax data available.
                </div>
            </div>

            <!-- Desktop: Table View -->
            <div class="hidden md:block overflow-x-auto">
                <table class="w-full caption-bottom text-sm">
                    <thead class="border-b bg-muted/50 [&_tr]:border-b">
                        <tr>
                            <th class="h-12 px-4 text-left font-medium text-muted-foreground">Date</th>
                            <th class="h-12 px-4 text-right font-medium text-muted-foreground">Tax Collected</th>
                        </tr>
                    </thead>
                    <tbody class="[&_tr:last-child]:border-0">
                        <tr v-for="row in taxSummary" :key="row.business_date" class="border-b transition-colors hover:bg-muted/50">
                            <td class="p-4 font-medium text-foreground">{{ formatDate(row.business_date) }}</td>
                            <td class="p-4 text-right text-foreground">{{ formatCents(row.total_tax) }}</td>
                        </tr>
                        <tr v-if="taxSummary.length === 0">
                            <td colspan="2" class="p-4 text-center text-muted-foreground py-8">
                                No tax data available.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
