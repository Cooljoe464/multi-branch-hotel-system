<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

interface WasteReport { reason: string; total_quantity: number; total_cost: number; }

const props = defineProps<{ wasteReport: WasteReport[]; filters?: { from?: string; to?: string }; }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }, { title: 'Waste Report', href: '/kitchen/waste/report' }] } });

import { formatCurrency, getCurrencySymbol } from '@/lib/format';
const page = usePage();
const branchSymbol = computed(() => (page.props.branch?.current as any)?.currency_symbol || '$');
const resolveSymbol = (code?: string) => getCurrencySymbol(code || 'NGN') || branchSymbol.value;
const formatMoney = (cents: number, currencyCode?: string) => formatCurrency(cents, resolveSymbol(currencyCode));
const totalCost = () => props.wasteReport.reduce((sum, r) => sum + r.total_cost, 0);
const dateFrom = ref(props.filters?.from ?? '');
const dateTo = ref(props.filters?.to ?? '');
const applyFilters = () => router.get('/kitchen/waste/report', { from: dateFrom.value, to: dateTo.value }, { preserveState: true, replace: true });
</script>

<template>
    <Head title="Waste Report" />
<div class="p-6">
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-bold text-foreground">Waste Report</h1>
        <Button variant="outline" @click="router.get('/kitchen/waste')">Back to Log</Button>
    </div>
    <div class="bg-card rounded-lg border border-border p-4 mb-6">
        <div class="flex flex-wrap items-end gap-4">
            <div class="grid gap-2"><Label class="text-sm">From</Label><Input v-model="dateFrom" type="date" class="w-40" /></div>
            <div class="grid gap-2"><Label class="text-sm">To</Label><Input v-model="dateTo" type="date" class="w-40" /></div>
            <Button variant="outline" @click="applyFilters">Filter</Button>
        </div>
    </div>
    <div class="rounded-lg border border-border">
        <table class="w-full caption-bottom text-sm">
            <thead class="bg-muted/50"><tr><th class="h-12 px-4 text-left font-medium text-muted-foreground">Reason</th><th class="h-12 px-4 text-left font-medium text-muted-foreground">Total Qty</th><th class="h-12 px-4 text-left font-medium text-muted-foreground">Total Cost</th></tr></thead>
            <tbody>
                <tr v-for="r in wasteReport" :key="r.reason" class="border-t border-border hover:bg-muted/50">
                    <td class="p-4 font-medium text-foreground capitalize">{{ r.reason }}</td>
                    <td class="p-4 text-foreground">{{ r.total_quantity }}</td>
                    <td class="p-4 text-foreground">{{ formatMoney(r.total_cost) }}</td>
                </tr>
                <tr class="border-t border-border font-bold"><td class="p-4">Total</td><td></td><td class="p-4">{{ formatMoney(totalCost()) }}</td></tr>
                <tr v-if="wasteReport.length === 0"><td colspan="3" class="p-4 text-center text-muted-foreground">No waste data.</td></tr>
            </tbody>
        </table>
    </div>
</div>
</template>
