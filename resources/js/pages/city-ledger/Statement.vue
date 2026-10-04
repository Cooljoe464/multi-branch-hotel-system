<script setup lang="ts">
import { computed } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

interface CityLedgerAccount {
    id: number;
    company_name: string;
    balance_owing: number;
}
interface Transaction {
    id: number;
    type: string;
    amount: number;
    reference: string | null;
    notes: string | null;
    created_at: string;
}

const props = defineProps<{
    cityLedgerAccount: CityLedgerAccount;
    transactions: Transaction[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'City Ledger', href: '/city-ledger' },
            { title: 'Statement', href: '#' },
        ],
    },
});

import { formatCurrency, getCurrencySymbol } from '@/lib/format';
const page = usePage();
const branchSymbol = computed(
    () => (page.props.branch?.current as any)?.currency_symbol || '$',
);
const resolveSymbol = (code?: string) =>
    getCurrencySymbol(code || 'NGN') || branchSymbol.value;
const formatMoney = (cents: number, currencyCode?: string) =>
    formatCurrency(cents, resolveSymbol(currencyCode));
</script>

<template>
    <Head title="City Ledger Statement" />
    <div class="p-6">
        <div class="mb-6 flex items-center justify-between">
            <div>
                <h1 class="text-foreground text-2xl font-bold">
                    Statement - {{ cityLedgerAccount.company_name }}
                </h1>
                <p class="text-muted-foreground">
                    Balance Owing:
                    {{ formatMoney(cityLedgerAccount.balance_owing) }}
                </p>
            </div>
            <Button
                variant="outline"
                @click="router.get(`/city-ledger/${cityLedgerAccount.id}`)"
                >Back</Button
            >
        </div>
        <div class="border-border rounded-lg border">
            <table class="w-full caption-bottom text-sm">
                <thead class="bg-muted/50">
                    <tr>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Date
                        </th>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Type
                        </th>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Amount
                        </th>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Reference
                        </th>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Notes
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="t in transactions"
                        :key="t.id"
                        class="border-border hover:bg-muted/50 border-t"
                    >
                        <td class="text-muted-foreground p-4">
                            {{ new Date(t.created_at).toLocaleDateString() }}
                        </td>
                        <td class="p-4">
                            <Badge variant="outline" class="capitalize">{{
                                t.type
                            }}</Badge>
                        </td>
                        <td class="text-foreground p-4">
                            {{ formatMoney(t.amount) }}
                        </td>
                        <td class="text-muted-foreground p-4">
                            {{ t.reference || '-' }}
                        </td>
                        <td class="text-muted-foreground p-4">
                            {{ t.notes || '-' }}
                        </td>
                    </tr>
                    <tr v-if="transactions.length === 0">
                        <td
                            colspan="5"
                            class="text-muted-foreground p-4 text-center"
                        >
                            No transactions.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
