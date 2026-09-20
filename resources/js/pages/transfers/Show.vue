<script setup lang="ts">
import { computed } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ArrowLeft } from '@lucide/vue';

interface TransferItem { name: string; quantity: number; cost: number; }
interface Transfer { id: number; status: string; from_branch: { name: string }; to_branch: { name: string }; items: TransferItem[]; notes: string | null; approved_at: string | null; shipped_at: string | null; received_at: string | null; requester: { name: string } | null; }

const props = defineProps<{ transfer: Transfer; }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }, { title: 'Transfers', href: '/transfers' }, { title: 'Details', href: '#' }] } });

const statusBadge: Record<string, string> = { pending: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300', approved: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300', in_transit: 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-300', received: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300' };

const approve = () => router.post(`/transfers/${props.transfer.id}/approve`);
const ship = () => router.post(`/transfers/${props.transfer.id}/ship`);
const receive = () => router.post(`/transfers/${props.transfer.id}/receive`);
import { formatCurrency, getCurrencySymbol } from '@/lib/format';
const page = usePage();
const branchSymbol = computed(() => (page.props.branch?.current as any)?.currency_symbol || '$');
const resolveSymbol = (code?: string) => getCurrencySymbol(code || 'NGN') || branchSymbol.value;
const formatMoney = (cents: number, currencyCode?: string) => formatCurrency(cents, resolveSymbol(currencyCode));
const totalValue = () => props.transfer.items.reduce((sum, item) => sum + (item.cost * item.quantity), 0);

const goBack = () => {
    window.history.back();
};
</script>

<template>
    <Head title="Transfer Details" />
<div class="p-6">
    <div class="mb-6 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <Button variant="ghost" size="sm" @click="goBack()" class="gap-1 text-muted-foreground hover:text-foreground">
                <ArrowLeft class="size-4" /> Back
            </Button>
            <div><h1 class="text-2xl font-bold text-foreground">Transfer #{{ transfer.id }}</h1><p class="text-muted-foreground">From {{ transfer.from_branch.name }} to {{ transfer.to_branch.name }}</p></div>
        </div>
        <Badge :class="statusBadge[transfer.status]" variant="outline" class="capitalize text-lg">{{ transfer.status.replace('_', ' ') }}</Badge>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div class="bg-card rounded-lg border border-border p-4">
            <h2 class="font-semibold text-foreground mb-2">Details</h2>
            <dl class="space-y-1 text-sm"><dt class="text-muted-foreground">Requested by</dt><dd class="text-foreground">{{ transfer.requester?.name ?? 'N/A' }}</dd>
            <dt class="text-muted-foreground">Notes</dt><dd class="text-foreground">{{ transfer.notes || 'None' }}</dd></dl>
        </div>
        <div class="bg-card rounded-lg border border-border p-4">
            <h2 class="font-semibold text-foreground mb-2">Actions</h2>
            <div class="flex gap-2">
                <Button v-if="transfer.status === 'pending'" @click="approve">Approve</Button>
                <Button v-if="transfer.status === 'approved'" @click="ship">Ship</Button>
                <Button v-if="transfer.status === 'in_transit'" @click="receive">Receive</Button>
            </div>
        </div>
    </div>
    <div class="mt-6 bg-card rounded-lg border border-border p-4">
        <h2 class="font-semibold text-foreground mb-2">Items</h2>
        <table class="w-full text-sm"><thead class="bg-muted/50"><tr><th class="h-12 px-4 font-medium text-muted-foreground">Item</th><th class="h-12 px-4 font-medium text-muted-foreground">Qty</th><th class="h-12 px-4 font-medium text-muted-foreground">Unit Cost</th><th class="h-12 px-4 font-medium text-muted-foreground">Total</th></tr></thead>
        <tbody>
            <tr v-for="(item, i) in transfer.items" :key="i" class="border-t border-border"><td class="p-4 text-foreground">{{ item.name }}</td><td class="p-4 text-foreground">{{ item.quantity }}</td><td class="p-4 text-muted-foreground">{{ formatMoney(item.cost) }}</td><td class="p-4 text-foreground">{{ formatMoney(item.cost * item.quantity) }}</td></tr>
            <tr class="border-t border-border font-bold"><td class="p-4 text-foreground" colspan="3">Total Transfer Value</td><td class="p-4 text-foreground">{{ formatMoney(totalValue()) }}</td></tr>
        </tbody></table>
    </div>
</div>
</template>
