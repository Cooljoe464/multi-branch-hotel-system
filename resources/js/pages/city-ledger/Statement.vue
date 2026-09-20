<script setup lang="ts">
import { computed } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

interface CityLedgerAccount { id: number; company_name: string; balance_owing: number; }
interface Transaction { id: number; type: string; amount: number; reference: string | null; notes: string | null; created_at: string; }

const props = defineProps<{ cityLedgerAccount: CityLedgerAccount; transactions: Transaction[]; }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'City Ledger', href: '/city-ledger' }, { title: 'Statement', href: '#' }] } });

import { formatCurrency, getCurrencySymbol } from '@/lib/format';
const page = usePage();
const branchSymbol = computed(() => (page.props.branch?.current as any)?.currency_symbol || '$');
const resolveSymbol = (code?: string) => getCurrencySymbol(code || 'NGN') || branchSymbol.value;
const formatMoney = (cents: number, currencyCode?: string) => formatCurrency(cents, resolveSymbol(currencyCode));
</script>

<template>
    <Head title="City Ledger Statement" />
<div class="p-6">
    <div class="mb-6 flex items-center justify-between">
        <div><h1 class="text-2xl font-bold text-foreground">Statement - {{ cityLedgerAccount.company_name }}</h1><p class="text-muted-foreground">Balance Owing: {{ formatMoney(cityLedgerAccount.balance_owing) }}</p></div>
        <Button variant="outline" @click="router.get(`/city-ledger/${cityLedgerAccount.id}`)">Back</Button>
    </div>
    <div class="rounded-lg border border-border">
        <table class="w-full caption-bottom text-sm">
            <thead class="bg-muted/50"><tr><th class="h-12 px-4 text-left font-medium text-muted-foreground">Date</th><th class="h-12 px-4 text-left font-medium text-muted-foreground">Type</th><th class="h-12 px-4 text-left font-medium text-muted-foreground">Amount</th><th class="h-12 px-4 text-left font-medium text-muted-foreground">Reference</th><th class="h-12 px-4 text-left font-medium text-muted-foreground">Notes</th></tr></thead>
            <tbody>
                <tr v-for="t in transactions" :key="t.id" class="border-t border-border hover:bg-muted/50">
                    <td class="p-4 text-muted-foreground">{{ new Date(t.created_at).toLocaleDateString() }}</td>
                    <td class="p-4"><Badge variant="outline" class="capitalize">{{ t.type }}</Badge></td>
                    <td class="p-4 text-foreground">{{ formatMoney(t.amount) }}</td>
                    <td class="p-4 text-muted-foreground">{{ t.reference || '-' }}</td>
                    <td class="p-4 text-muted-foreground">{{ t.notes || '-' }}</td>
                </tr>
                <tr v-if="transactions.length === 0"><td colspan="5" class="p-4 text-center text-muted-foreground">No transactions.</td></tr>
            </tbody>
        </table>
    </div>
</div>
</template>
