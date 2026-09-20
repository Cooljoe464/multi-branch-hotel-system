<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { ArrowLeft } from '@lucide/vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';

interface CityLedgerAccount { id: number; company_name: string; contact_name: string; email: string; credit_limit: number; balance_owing: number; is_active: boolean; transactions: any[]; }

const props = defineProps<{ cityLedgerAccount: CityLedgerAccount; }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'City Ledger', href: '/city-ledger' }, { title: 'Account', href: '#' }] } });

const showChargeModal = ref(false);
const showPayModal = ref(false);
const chargeForm = ref({ amount: '', reference: '', notes: '', folio_id: '' });
const payForm = ref({ amount: '', reference: '', notes: '' });
import { formatCurrency, getCurrencySymbol } from '@/lib/format';
const page = usePage();
const branchSymbol = computed(() => (page.props.branch?.current as any)?.currency_symbol || '$');
const resolveSymbol = (code?: string) => getCurrencySymbol(code || 'NGN') || branchSymbol.value;
const formatMoney = (cents: number, currencyCode?: string) => formatCurrency(cents, resolveSymbol(currencyCode));

const charge = () => { router.post(`/city-ledger/${props.cityLedgerAccount.id}/charge`, { ...chargeForm.value, amount: parseInt(chargeForm.value.amount) || 0, folio_id: chargeForm.value.folio_id ? parseInt(chargeForm.value.folio_id) : null }, { onSuccess: () => { showChargeModal.value = false; } }); };
const pay = () => { router.post(`/city-ledger/${props.cityLedgerAccount.id}/pay`, { ...payForm.value, amount: parseInt(payForm.value.amount) || 0 }, { onSuccess: () => { showPayModal.value = false; } }); };

const goBack = () => {
    window.history.back();
};
</script>

<template>
    <Head title="City Ledger Account" />
<div class="p-6">
    <div class="mb-6 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <Button variant="ghost" size="sm" @click="goBack()" class="gap-1 text-muted-foreground hover:text-foreground">
                <ArrowLeft class="size-4" /> Back
            </Button>
            <div><h1 class="text-2xl font-bold text-foreground">{{ cityLedgerAccount.company_name }}</h1><p class="text-muted-foreground">{{ cityLedgerAccount.contact_name }} - {{ cityLedgerAccount.email }}</p></div>
        </div>
        <div class="flex gap-2"><Button variant="outline" @click="showChargeModal = true">Post Charge</Button><Button @click="showPayModal = true">Record Payment</Button></div>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
        <div class="bg-card rounded-lg border border-border p-4"><div class="text-sm text-muted-foreground">Credit Limit</div><div class="text-lg font-bold text-foreground">{{ formatMoney(cityLedgerAccount.credit_limit) }}</div></div>
        <div class="bg-card rounded-lg border border-border p-4"><div class="text-sm text-muted-foreground">Balance Owing</div><div class="text-lg font-bold text-foreground" :class="{ 'text-red-600 dark:text-red-400': cityLedgerAccount.balance_owing > 0 }">{{ formatMoney(cityLedgerAccount.balance_owing) }}</div></div>
        <div class="bg-card rounded-lg border border-border p-4"><div class="text-sm text-muted-foreground">Payment Terms</div><div class="text-lg font-bold text-foreground">{{ cityLedgerAccount.payment_terms_days }} days</div></div>
    </div>
    <div class="bg-card rounded-lg border border-border p-4">
        <h2 class="font-semibold text-foreground mb-2">Recent Transactions</h2>
        <table class="w-full text-sm"><thead class="bg-muted/50"><tr><th class="h-12 px-4 font-medium text-muted-foreground">Date</th><th class="h-12 px-4 font-medium text-muted-foreground">Type</th><th class="h-12 px-4 font-medium text-muted-foreground">Amount</th><th class="h-12 px-4 font-medium text-muted-foreground">Reference</th></tr></thead>
        <tbody><tr v-for="t in cityLedgerAccount.transactions" :key="t.id" class="border-t border-border">
            <td class="p-4 text-muted-foreground">{{ new Date(t.created_at).toLocaleDateString() }}</td>
            <td class="p-4"><Badge variant="outline" class="capitalize">{{ t.type }}</Badge></td>
            <td class="p-4 text-foreground">{{ formatMoney(t.amount) }}</td>
            <td class="p-4 text-muted-foreground">{{ t.reference || '-' }}</td>
        </tr></tbody></table>
    </div>

    <Dialog :open="showChargeModal" @update:open="showChargeModal = $event"><DialogContent><DialogHeader><DialogTitle>Post Charge</DialogTitle></DialogHeader>
        <form @submit.prevent="charge" class="space-y-4"><div class="grid gap-2"><Label>Amount (cents)</Label><Input v-model="chargeForm.amount" type="number" min="1" required /></div><div class="grid gap-2"><Label>Folio ID (optional)</Label><Input v-model="chargeForm.folio_id" type="number" min="1" placeholder="Leave blank for direct charge" /></div><div class="grid gap-2"><Label>Reference</Label><Input v-model="chargeForm.reference" /></div><div class="grid gap-2"><Label>Notes</Label><Input v-model="chargeForm.notes" /></div><DialogFooter><div class="flex justify-end gap-2"><Button variant="outline" type="button" @click="showChargeModal = false">Cancel</Button><Button type="submit">Post</Button></div></DialogFooter></form>
    </DialogContent></Dialog>

    <Dialog :open="showPayModal" @update:open="showPayModal = $event"><DialogContent><DialogHeader><DialogTitle>Record Payment</DialogTitle></DialogHeader>
        <form @submit.prevent="pay" class="space-y-4"><div class="grid gap-2"><Label>Amount (cents)</Label><Input v-model="payForm.amount" type="number" min="1" required /></div><div class="grid gap-2"><Label>Reference</Label><Input v-model="payForm.reference" /></div><div class="grid gap-2"><Label>Notes</Label><Input v-model="payForm.notes" /></div><DialogFooter><div class="flex justify-end gap-2"><Button variant="outline" type="button" @click="showPayModal = false">Cancel</Button><Button type="submit">Record</Button></div></DialogFooter></form>
    </DialogContent></Dialog>
</div>
</template>
