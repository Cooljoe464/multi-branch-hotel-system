<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { ArrowLeft } from '@lucide/vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogFooter,
} from '@/components/ui/dialog';

interface CityLedgerAccount {
    id: number;
    company_name: string;
    contact_name: string;
    email: string;
    credit_limit: number;
    balance_owing: number;
    payment_terms_days: number;
    is_active: boolean;
    transactions: any[];
}

const props = defineProps<{ cityLedgerAccount: CityLedgerAccount }>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'City Ledger', href: '/city-ledger' },
            { title: 'Account', href: '#' },
        ],
    },
});

const showChargeModal = ref(false);
const showPayModal = ref(false);
const chargeForm = ref({ amount: '', reference: '', notes: '', folio_id: '' });
const payForm = ref({ amount: '', reference: '', notes: '' });
import { formatCurrency, getCurrencySymbol } from '@/lib/format';
const page = usePage();
const branchSymbol = computed(
    () => (page.props.branch?.current as any)?.currency_symbol || '$',
);
const resolveSymbol = (code?: string) =>
    getCurrencySymbol(code || 'NGN') || branchSymbol.value;
const formatMoney = (cents: number, currencyCode?: string) =>
    formatCurrency(cents, resolveSymbol(currencyCode));

const charge = () => {
    router.post(
        `/city-ledger/${props.cityLedgerAccount.id}/charge`,
        {
            ...chargeForm.value,
            amount: parseInt(chargeForm.value.amount) || 0,
            folio_id: chargeForm.value.folio_id
                ? parseInt(chargeForm.value.folio_id)
                : null,
        },
        {
            onSuccess: () => {
                showChargeModal.value = false;
            },
        },
    );
};
const pay = () => {
    router.post(
        `/city-ledger/${props.cityLedgerAccount.id}/pay`,
        { ...payForm.value, amount: parseInt(payForm.value.amount) || 0 },
        {
            onSuccess: () => {
                showPayModal.value = false;
            },
        },
    );
};

const goBack = () => {
    window.history.back();
};
</script>

<template>
    <Head title="City Ledger Account" />
    <div class="p-6">
        <div class="mb-6 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <Button
                    variant="ghost"
                    size="sm"
                    @click="goBack()"
                    class="text-muted-foreground hover:text-foreground gap-1"
                >
                    <ArrowLeft class="size-4" /> Back
                </Button>
                <div>
                    <h1 class="text-foreground text-2xl font-bold">
                        {{ cityLedgerAccount.company_name }}
                    </h1>
                    <p class="text-muted-foreground">
                        {{ cityLedgerAccount.contact_name }} -
                        {{ cityLedgerAccount.email }}
                    </p>
                </div>
            </div>
            <div class="flex gap-2">
                <Button variant="outline" @click="showChargeModal = true"
                    >Post Charge</Button
                ><Button @click="showPayModal = true">Record Payment</Button>
            </div>
        </div>
        <div class="mb-6 grid grid-cols-1 gap-6 md:grid-cols-3">
            <div class="bg-card border-border rounded-lg border p-4">
                <div class="text-muted-foreground text-sm">Credit Limit</div>
                <div class="text-foreground text-lg font-bold">
                    {{ formatMoney(cityLedgerAccount.credit_limit) }}
                </div>
            </div>
            <div class="bg-card border-border rounded-lg border p-4">
                <div class="text-muted-foreground text-sm">Balance Owing</div>
                <div
                    class="text-foreground text-lg font-bold"
                    :class="{
                        'text-red-600 dark:text-red-400':
                            cityLedgerAccount.balance_owing > 0,
                    }"
                >
                    {{ formatMoney(cityLedgerAccount.balance_owing) }}
                </div>
            </div>
            <div class="bg-card border-border rounded-lg border p-4">
                <div class="text-muted-foreground text-sm">Payment Terms</div>
                <div class="text-foreground text-lg font-bold">
                    {{ cityLedgerAccount.payment_terms_days }} days
                </div>
            </div>
        </div>
        <div class="bg-card border-border rounded-lg border p-4">
            <h2 class="text-foreground mb-2 font-semibold">
                Recent Transactions
            </h2>
            <table class="w-full text-sm">
                <thead class="bg-muted/50">
                    <tr>
                        <th class="text-muted-foreground h-12 px-4 font-medium">
                            Date
                        </th>
                        <th class="text-muted-foreground h-12 px-4 font-medium">
                            Type
                        </th>
                        <th class="text-muted-foreground h-12 px-4 font-medium">
                            Amount
                        </th>
                        <th class="text-muted-foreground h-12 px-4 font-medium">
                            Reference
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="t in cityLedgerAccount.transactions"
                        :key="t.id"
                        class="border-border border-t"
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
                    </tr>
                </tbody>
            </table>
        </div>

        <Dialog :open="showChargeModal" @update:open="showChargeModal = $event"
            ><DialogContent
                ><DialogHeader
                    ><DialogTitle>Post Charge</DialogTitle></DialogHeader
                >
                <form @submit.prevent="charge" class="space-y-4">
                    <div class="grid gap-2">
                        <Label>Amount (cents)</Label
                        ><Input
                            v-model="chargeForm.amount"
                            type="number"
                            min="1"
                            required
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label>Folio ID (optional)</Label
                        ><Input
                            v-model="chargeForm.folio_id"
                            type="number"
                            min="1"
                            placeholder="Leave blank for direct charge"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label>Reference</Label
                        ><Input v-model="chargeForm.reference" />
                    </div>
                    <div class="grid gap-2">
                        <Label>Notes</Label><Input v-model="chargeForm.notes" />
                    </div>
                    <DialogFooter
                        ><div class="flex justify-end gap-2">
                            <Button
                                variant="outline"
                                type="button"
                                @click="showChargeModal = false"
                                >Cancel</Button
                            ><Button type="submit">Post</Button>
                        </div></DialogFooter
                    >
                </form>
            </DialogContent></Dialog
        >

        <Dialog :open="showPayModal" @update:open="showPayModal = $event"
            ><DialogContent
                ><DialogHeader
                    ><DialogTitle>Record Payment</DialogTitle></DialogHeader
                >
                <form @submit.prevent="pay" class="space-y-4">
                    <div class="grid gap-2">
                        <Label>Amount (cents)</Label
                        ><Input
                            v-model="payForm.amount"
                            type="number"
                            min="1"
                            required
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label>Reference</Label
                        ><Input v-model="payForm.reference" />
                    </div>
                    <div class="grid gap-2">
                        <Label>Notes</Label><Input v-model="payForm.notes" />
                    </div>
                    <DialogFooter
                        ><div class="flex justify-end gap-2">
                            <Button
                                variant="outline"
                                type="button"
                                @click="showPayModal = false"
                                >Cancel</Button
                            ><Button type="submit">Record</Button>
                        </div></DialogFooter
                    >
                </form>
            </DialogContent></Dialog
        >
    </div>
</template>
