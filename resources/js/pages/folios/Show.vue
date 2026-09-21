<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ArrowLeft } from '@lucide/vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

interface Transaction {
    id: number;
    type: string;
    category: string;
    description: string;
    amount: number;
    tax_amount: number;
    is_voided: boolean;
    voided_at: string | null;
    created_at: string;
    posted_by: { name: string } | null;
}

interface Dispute {
    id: number;
    status: string;
    reason: string;
    resolution_notes: string | null;
    amount_disputed: number;
    created_at: string;
    resolved_at: string | null;
    reporter: { name: string } | null;
    resolver: { name: string } | null;
}

interface Folio {
    id: number;
    folio_number: string;
    type: string;
    status: string;
    description: string | null;
    guest_name: string | null;
    notes: string | null;
    balance: number;
    is_settled: boolean;
    created_at: string;
    closed_at: string | null;
    parent_folio_id: number | null;
    reservation: {
        id: number;
        confirmation_number: string;
        guest_name: string;
        room_rate: number;
        room: { number: string } | null;
        room_type: { name: string } | null;
        guest: { first_name: string; last_name: string; email: string } | null;
    } | null;
    disputes: Dispute[];
}

interface ChildFolio {
    id: number;
    folio_number: string;
    type: string;
    status: string;
    description: string | null;
    balance: number;
    transactions: Transaction[];
}

interface FolioWindow {
    id: number;
    code: string;
    payer_type: string;
    debits_total: number;
    credits_total: number;
}

const props = defineProps<{
    folio: Folio;
    transactions: Transaction[];
    childFolios: ChildFolio[];
    windows: FolioWindow[];
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }, { title: 'Folios', href: '/folios' }, { title: 'Details', href: '/folios' }] } });

const showCreateChildModal = ref(false);
const showTransferModal = ref(false);
const showChargeModal = ref(false);
const showPaymentModal = ref(false);
const showDisputeModal = ref(false);
const showWindowModal = ref(false);
const showSplitModal = ref(false);
const selectedTransaction = ref<Transaction | null>(null);

const childForm = ref({ description: '', reservation_id: '' });
const transferForm = ref({ target_folio_id: '' });
const chargeForm = ref({ category: 'misc', description: '', amount: 0, tax_rate_bps: 750 });
const paymentForm = ref({ amount: 0, method: 'cash', reference: '' });
const disputeForm = ref({ transaction_id: null as number | null, reason: '' });
const windowForm = ref({ code: '', payer_type: 'guest' });
const splitForm = ref({ legs: [{ window_code: 'room', percent_bps: 5000 }, { window_code: 'incidentals', percent_bps: 5000 }] });

import { formatCurrency as formatCurrencyRaw } from '@/lib/format';
const page = usePage();
const branchSymbol = computed(() => (page.props.branch?.current as any)?.currency_symbol || '$');
const recordCurrencyCode = computed(() => props.folio.currency_code || 'NGN');
const currencySymbol = computed(() => {
    const code = recordCurrencyCode.value;
    const symbols: Record<string, string> = { NGN: '₦', USD: '$', EUR: '€', GBP: '£', CAD: 'C$', AUD: 'A$', SGD: 'S$', INR: '₹', AED: 'د.إ' };
    return symbols[code] || branchSymbol.value;
});
const formatCurrency = (amount: number) => formatCurrencyRaw(amount, currencySymbol.value);

const getStatusBadgeClass = (status: string) => {
    const classes: Record<string, string> = {
        open: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300',
        closed: 'bg-muted text-muted-foreground',
        transferred: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300',
    };
    return classes[status] || 'bg-muted text-muted-foreground';
};

const getTypeBadgeClass = (type: string) => {
    const classes: Record<string, string> = {
        master: 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-300',
        child: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300',
        individual: 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-300',
        staff: 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-300',
        non_guest: 'bg-teal-100 text-teal-800 dark:bg-teal-900 dark:text-teal-300',
    };
    return classes[type] || 'bg-muted text-muted-foreground';
};

const getDisputeStatusBadgeClass = (status: string) => {
    const classes: Record<string, string> = {
        open: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300',
        under_review: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300',
        resolved: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300',
        rejected: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300',
    };
    return classes[status] || 'bg-muted text-muted-foreground';
};

const getCategoryLabel = (category: string) => {
    const labels: Record<string, string> = {
        room_rate: 'Room Rate',
        tax: 'Tax',
        minibar: 'Minibar',
        restaurant: 'Restaurant',
        laundry: 'Laundry',
        spa: 'Spa',
        parking: 'Parking',
        misc: 'Miscellaneous',
        payment: 'Payment',
        refund: 'Refund',
        adjustment: 'Adjustment',
        transfer: 'Transfer',
    };
    return labels[category] || category;
};

const debitsTotal = () => {
    return props.transactions
        .filter((t) => t.type === 'debit' && !t.is_voided)
        .reduce((sum, t) => sum + t.amount, 0);
};

const creditsTotal = () => {
    return props.transactions
        .filter((t) => t.type === 'credit' && !t.is_voided)
        .reduce((sum, t) => sum + t.amount, 0);
};

const openDisputeModal = (transaction?: Transaction) => {
    disputeForm.value = { transaction_id: transaction?.id ?? null, reason: '' };
    showDisputeModal.value = true;
};

const submitCreateChild = () => {
    router.post(`/folios/${props.folio.id}/child`, childForm.value, {
        onSuccess: () => {
            showCreateChildModal.value = false;
            childForm.value = { description: '', reservation_id: '' };
        },
    });
};

const openTransferModal = (transaction: Transaction) => {
    selectedTransaction.value = transaction;
    showTransferModal.value = true;
};

const submitTransfer = () => {
    if (! selectedTransaction.value) return;
    router.post(`/folios/transactions/${selectedTransaction.value.id}/transfer`, transferForm.value, {
        onSuccess: () => {
            showTransferModal.value = false;
            selectedTransaction.value = null;
            transferForm.value = { target_folio_id: '' };
        },
    });
};

const submitCharge = () => {
    router.post(`/folios/${props.folio.id}/charges`, chargeForm.value, {
        onSuccess: () => {
            showChargeModal.value = false;
            chargeForm.value = { category: 'misc', description: '', amount: 0, tax_rate_bps: 750 };
        },
    });
};

const submitPayment = () => {
    router.post(`/folios/${props.folio.id}/payments`, paymentForm.value, {
        onSuccess: () => {
            showPaymentModal.value = false;
            paymentForm.value = { amount: 0, method: 'cash', reference: '' };
        },
    });
};

const submitWindow = () => {
    router.post(`/folios/${props.folio.id}/windows`, windowForm.value, {
        onSuccess: () => {
            showWindowModal.value = false;
            windowForm.value = { code: '', payer_type: 'guest' };
        },
    });
};

const openSplitModal = (transaction: Transaction) => {
    selectedTransaction.value = transaction;
    showSplitModal.value = true;
};

const submitSplit = () => {
    if (! selectedTransaction.value) return;
    router.post(`/transactions/${selectedTransaction.value.id}/split`, { legs: splitForm.value.legs }, {
        onSuccess: () => {
            showSplitModal.value = false;
            selectedTransaction.value = null;
        },
    });
};

const submitDispute = () => {
    router.post(`/folios/${props.folio.id}/disputes`, disputeForm.value, {
        onSuccess: () => {
            showDisputeModal.value = false;
            disputeForm.value = { transaction_id: null, reason: '' };
        },
    });
};

const checkout = () => {
    if (confirm('Checkout this folio? This will settle the balance and close it.')) {
        router.post(`/folios/${props.folio.id}/checkout`);
    }
};

const goBack = () => {
    window.history.back();
};
</script>

<template>
    <div class="p-4 md:p-6">
        <!-- Header -->
        <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-3">
                <Button variant="ghost" size="sm" @click="goBack()" class="gap-1 text-muted-foreground hover:text-foreground">
                    <ArrowLeft class="size-4" /> Back
                </Button>
                <div>
                    <h1 class="text-2xl font-bold text-foreground">{{ folio.folio_number }}</h1>
                    <p v-if="folio.description" class="text-muted-foreground">{{ folio.description }}</p>
                    <p v-if="folio.guest_name" class="text-sm text-muted-foreground">{{ folio.guest_name }}</p>
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button variant="outline" @click="() => window.print()">Print Bill</Button>
                <Button variant="outline" :href="`/folios/${folio.id}/bill`" as="a">View Bill</Button>
                <Button v-if="folio.status === 'open'" @click="showChargeModal = true">Post Charge</Button>
                <Button v-if="folio.status === 'open'" variant="default" @click="showPaymentModal = true">Record Payment</Button>
                <Button v-if="folio.status === 'open' && folio.balance <= 0" variant="default" class="bg-green-600 hover:bg-green-700" @click="checkout">Checkout</Button>
                <Button v-if="folio.status === 'open'" variant="outline" @click="showCreateChildModal = true">Create Child Folio</Button>
            </div>
        </div>

        <!-- Status & Summary -->
        <div class="mb-6 grid grid-cols-1 sm:grid-cols-4 gap-4">
            <div class="bg-card rounded-lg shadow p-4">
                <div class="text-sm text-muted-foreground">Status</div>
                <span class="px-2 py-1 text-xs font-medium rounded-full" :class="getStatusBadgeClass(folio.status)">
                    {{ folio.status }}
                </span>
            </div>
            <div class="bg-card rounded-lg shadow p-4">
                <div class="text-sm text-muted-foreground">Type</div>
                <span class="px-2 py-1 text-xs font-medium rounded-full" :class="getTypeBadgeClass(folio.type)">
                    {{ folio.type.replace('_', ' ') }}
                </span>
            </div>
            <div class="bg-card rounded-lg shadow p-4">
                <div class="text-sm text-muted-foreground">Total Debits</div>
                <div class="text-lg font-bold text-red-600 dark:text-red-400">{{ formatCurrency(debitsTotal()) }}</div>
            </div>
            <div class="bg-card rounded-lg shadow p-4">
                <div class="text-sm text-muted-foreground">Balance</div>
                <div class="text-lg font-bold" :class="folio.balance > 0 ? 'text-red-600 dark:text-red-400' : folio.balance < 0 ? 'text-green-600 dark:text-green-400' : 'text-foreground'">
                    {{ formatCurrency(folio.balance) }}
                </div>
            </div>
        </div>

        <!-- Reservation Info -->
        <div v-if="folio.reservation" class="mb-6 bg-card rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold dark:text-foreground mb-4">Reservation</h2>
            <dl class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div>
                    <dt class="text-sm text-muted-foreground">Guest</dt>
                    <dd class="font-medium dark:text-foreground">{{ folio.reservation.guest_name }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-muted-foreground">Confirmation</dt>
                    <dd class="font-mono text-sm dark:text-foreground">{{ folio.reservation.confirmation_number }}</dd>
                </div>
                <div v-if="folio.reservation.room">
                    <dt class="text-sm text-muted-foreground">Room</dt>
                    <dd class="font-medium dark:text-foreground">{{ folio.reservation.room.number }}</dd>
                </div>
                <div v-if="folio.reservation.room_type">
                    <dt class="text-sm text-muted-foreground">Room Type</dt>
                    <dd class="font-medium dark:text-foreground">{{ folio.reservation.room_type.name }}</dd>
                </div>
            </dl>
        </div>

        <!-- Disputes -->
        <div v-if="folio.disputes && folio.disputes.length > 0" class="mb-6 bg-card rounded-lg shadow overflow-hidden">
            <div class="px-6 py-4 border-b border-border flex items-center justify-between">
                <h2 class="text-lg font-semibold dark:text-foreground">Disputes ({{ folio.disputes.length }})</h2>
            </div>
            <div class="divide-y divide-border">
                <div v-for="dispute in folio.disputes" :key="dispute.id" class="px-6 py-4">
                    <div class="flex items-center justify-between mb-2">
                        <Badge :class="getDisputeStatusBadgeClass(dispute.status)">
                            {{ dispute.status.replace('_', ' ') }}
                        </Badge>
                        <span class="text-sm text-muted-foreground">{{ formatDate(dispute.created_at) }}</span>
                    </div>
                    <p class="text-sm dark:text-foreground">{{ dispute.reason }}</p>
                    <div class="mt-2 text-xs text-muted-foreground">
                        Filed by {{ dispute.reporter?.name ?? 'Unknown' }}
                        <span v-if="dispute.resolver"> | Resolved by {{ dispute.resolver.name }}</span>
                        <span v-if="dispute.resolution_notes"> | {{ dispute.resolution_notes }}</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Windows -->
        <div class="bg-card rounded-lg shadow overflow-hidden mb-6">
            <div class="px-6 py-4 border-b border-border flex items-center justify-between">
                <h2 class="text-lg font-semibold dark:text-foreground">Payer Windows</h2>
                <Button variant="outline" size="sm" @click="showWindowModal = true">Add window</Button>
            </div>
            <div class="divide-y divide-border">
                <div v-for="w in windows" :key="w.id" class="px-6 py-3 flex items-center justify-between">
                    <div>
                        <span class="font-mono font-medium">{{ w.code }}</span>
                        <span class="ml-2 text-xs text-muted-foreground">{{ w.payer_type }}</span>
                    </div>
                    <div class="text-sm">Dr {{ formatCurrency(w.debits_total) }} · Cr {{ formatCurrency(w.credits_total) }}</div>
                </div>
                <div v-if="windows.length === 0" class="px-6 py-4 text-sm text-muted-foreground">
                    No windows yet — charges post to the default room window.
                </div>
            </div>
        </div>

        <!-- Transactions -->
        <div class="bg-card rounded-lg shadow overflow-hidden mb-6">
            <div class="px-6 py-4 border-b border-border">
                <h2 class="text-lg font-semibold dark:text-foreground">Transactions</h2>
            </div>

            <!-- Mobile: Card View -->
            <div class="md:hidden divide-y divide-border">
                <div v-for="tx in transactions" :key="tx.id" class="p-4" :class="{ 'opacity-50': tx.is_voided }">
                    <div class="flex items-start justify-between gap-2 mb-1">
                        <span class="px-2 py-1 text-xs font-medium rounded-full bg-muted text-muted-foreground">
                            {{ getCategoryLabel(tx.category) }}
                        </span>
                        <span class="text-xs text-muted-foreground shrink-0">{{ formatDateTime(tx.created_at) }}</span>
                    </div>
                    <p class="text-sm text-foreground">
                        {{ tx.description }}
                        <span v-if="tx.is_voided" class="text-xs text-red-500 dark:text-red-400 ml-1">(VOIDED)</span>
                    </p>
                    <div class="mt-2 flex items-center justify-between">
                        <span class="text-xs text-muted-foreground">{{ tx.posted_by?.name ?? 'System' }}</span>
                        <span class="text-sm font-medium" :class="tx.type === 'debit' && !tx.is_voided ? 'text-red-600 dark:text-red-400' : tx.type === 'credit' && !tx.is_voided ? 'text-green-600 dark:text-green-400' : 'text-muted-foreground'">
                            {{ tx.type === 'debit' && !tx.is_voided ? '-' : tx.type === 'credit' && !tx.is_voided ? '+' : '' }}{{ (tx.type === 'debit' && !tx.is_voided) || (tx.type === 'credit' && !tx.is_voided) ? formatCurrency(tx.amount) : '-' }}
                        </span>
                    </div>
                    <div v-if="tx.type === 'debit' && !tx.is_voided && folio.status === 'open'" class="mt-2 flex gap-2">
                        <button class="px-2 py-1 text-xs text-indigo-600 dark:text-indigo-400 bg-indigo-50 dark:bg-indigo-900/30 rounded hover:bg-indigo-100 dark:hover:bg-indigo-900/50" @click="openTransferModal(tx)">Transfer</button>
                        <button class="px-2 py-1 text-xs text-yellow-600 dark:text-yellow-400 bg-yellow-50 dark:bg-yellow-900/30 rounded hover:bg-yellow-100 dark:hover:bg-yellow-900/50" @click="openDisputeModal(tx)">Dispute</button>
                        <button class="px-2 py-1 text-xs text-teal-600 dark:text-teal-400 bg-teal-50 dark:bg-teal-900/30 rounded hover:bg-teal-100 dark:hover:bg-teal-900/50" @click="openSplitModal(tx)">Split</button>
                    </div>
                </div>
                <div v-if="transactions.length === 0" class="px-4 py-8 text-center text-muted-foreground">
                    No transactions yet.
                </div>
            </div>

            <!-- Desktop: Table View -->
            <div class="hidden md:block">
                <table class="min-w-full divide-y divide-border">
                    <thead class="bg-muted/50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase">Date</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase">Category</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase">Description</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-muted-foreground uppercase">Debit</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-muted-foreground uppercase">Credit</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase">Posted By</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-muted-foreground uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="tx in transactions" :key="tx.id" class="hover:bg-muted/50" :class="{ 'opacity-50': tx.is_voided }">
                            <td class="px-4 py-3 text-sm text-muted-foreground">{{ formatDateTime(tx.created_at) }}</td>
                            <td class="px-4 py-3">
                                <span class="px-2 py-1 text-xs font-medium rounded-full bg-muted text-muted-foreground">
                                    {{ getCategoryLabel(tx.category) }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-sm text-foreground">
                                {{ tx.description }}
                                <span v-if="tx.is_voided" class="ml-2 text-xs text-red-500 dark:text-red-400">(VOIDED)</span>
                            </td>
                            <td class="px-4 py-3 text-sm text-right font-medium text-red-600 dark:text-red-400">
                                {{ tx.type === 'debit' && !tx.is_voided ? formatCurrency(tx.amount) : '-' }}
                            </td>
                            <td class="px-4 py-3 text-sm text-right font-medium text-green-600 dark:text-green-400">
                                {{ tx.type === 'credit' && !tx.is_voided ? formatCurrency(tx.amount) : '-' }}
                            </td>
                            <td class="px-4 py-3 text-sm text-muted-foreground">
                                {{ tx.posted_by?.name ?? 'System' }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex justify-end gap-1">
                                    <button
                                        v-if="tx.type === 'debit' && !tx.is_voided && folio.status === 'open'"
                                        class="px-2 py-1 text-xs text-indigo-600 dark:text-indigo-400 bg-indigo-50 dark:bg-indigo-900/30 rounded hover:bg-indigo-100 dark:hover:bg-indigo-900/50"
                                        @click="openTransferModal(tx)"
                                    >
                                        Transfer
                                    </button>
                                    <button
                                        v-if="tx.type === 'debit' && !tx.is_voided && folio.status === 'open'"
                                        class="px-2 py-1 text-xs text-teal-600 dark:text-teal-400 bg-teal-50 dark:bg-teal-900/30 rounded hover:bg-teal-100 dark:hover:bg-teal-900/50"
                                        @click="openSplitModal(tx)"
                                    >
                                        Split
                                    </button>
                                    <button
                                        v-if="tx.type === 'debit' && !tx.is_voided && folio.status === 'open'"
                                        class="px-2 py-1 text-xs text-yellow-600 dark:text-yellow-400 bg-yellow-50 dark:bg-yellow-900/30 rounded hover:bg-yellow-100 dark:hover:bg-yellow-900/50"
                                        @click="openDisputeModal(tx)"
                                    >
                                        Dispute
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="transactions.length === 0">
                            <td colspan="7" class="px-4 py-8 text-center text-muted-foreground">
                                No transactions yet.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Child Folios -->
        <div v-if="childFolios.length > 0" class="bg-card rounded-lg shadow overflow-hidden">
            <div class="px-6 py-4 border-b border-border">
                <h2 class="text-lg font-semibold dark:text-foreground">Child Folios ({{ childFolios.length }})</h2>
            </div>
            <div class="divide-y divide-border">
                <div v-for="child in childFolios" :key="child.id" class="px-6 py-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <Link :href="`/folios/${child.id}`" class="font-mono font-medium text-indigo-600 dark:text-indigo-400 hover:text-indigo-500">
                                {{ child.folio_number }}
                            </Link>
                            <span class="ml-2 text-sm text-muted-foreground">{{ child.description }}</span>
                        </div>
                        <div class="text-right">
                            <span class="px-2 py-1 text-xs font-medium rounded-full" :class="getStatusBadgeClass(child.status)">
                                {{ child.status }}
                            </span>
                            <span class="ml-2 font-medium" :class="child.balance > 0 ? 'text-red-600 dark:text-red-400' : 'text-foreground'">
                                {{ formatCurrency(child.balance) }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Post Charge Modal -->
        <Dialog v-model:open="showChargeModal">
            <DialogContent class="sm:max-w-[500px]">
                <DialogHeader>
                    <DialogTitle>Post Charge</DialogTitle>
                </DialogHeader>
                <form @submit.prevent="submitCharge">
                    <div class="space-y-4 py-4">
                        <div class="grid gap-2">
                            <Label>Category</Label>
                            <Select v-model="chargeForm.category">
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="minibar">Minibar</SelectItem>
                                    <SelectItem value="restaurant">Restaurant</SelectItem>
                                    <SelectItem value="laundry">Laundry</SelectItem>
                                    <SelectItem value="spa">Spa</SelectItem>
                                    <SelectItem value="parking">Parking</SelectItem>
                                    <SelectItem value="misc">Miscellaneous</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div class="grid gap-2">
                            <Label for="charge_desc">Description</Label>
                            <Input id="charge_desc" v-model="chargeForm.description" required placeholder="e.g. Minibar - Beer x2" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="charge_amount">Amount (cents)</Label>
                            <Input id="charge_amount" v-model.number="chargeForm.amount" type="number" min="1" required />
                        </div>
                        <div class="grid gap-2">
                            <Label for="charge_tax">Tax Rate (basis points)</Label>
                            <Input id="charge_tax" v-model.number="chargeForm.tax_rate_bps" type="number" min="0" max="10000" placeholder="750 = 7.5%" />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" @click="showChargeModal = false">Cancel</Button>
                        <Button type="submit">Post Charge</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <!-- Record Payment Modal -->
        <Dialog v-model:open="showPaymentModal">
            <DialogContent class="sm:max-w-[500px]">
                <DialogHeader>
                    <DialogTitle>Record Payment</DialogTitle>
                </DialogHeader>
                <form @submit.prevent="submitPayment">
                    <div class="space-y-4 py-4">
                        <div class="grid gap-2">
                            <Label>Payment Method</Label>
                            <Select v-model="paymentForm.method">
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="cash">Cash</SelectItem>
                                    <SelectItem value="card">Card</SelectItem>
                                    <SelectItem value="online">Online</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div class="grid gap-2">
                            <Label for="pay_amount">Amount (cents)</Label>
                            <Input id="pay_amount" v-model.number="paymentForm.amount" type="number" min="1" required />
                        </div>
                        <div class="grid gap-2">
                            <Label for="pay_ref">Reference (optional)</Label>
                            <Input id="pay_ref" v-model="paymentForm.reference" placeholder="e.g. Receipt #12345" />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" @click="showPaymentModal = false">Cancel</Button>
                        <Button type="submit">Record Payment</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <!-- Dispute Modal -->
        <Dialog v-model:open="showDisputeModal">
            <DialogContent class="sm:max-w-[500px]">
                <DialogHeader>
                    <DialogTitle>Initiate Dispute</DialogTitle>
                </DialogHeader>
                <form @submit.prevent="submitDispute">
                    <div class="space-y-4 py-4">
                        <div class="grid gap-2">
                            <Label for="dispute_reason">Reason</Label>
                            <textarea
                                id="dispute_reason"
                                v-model="disputeForm.reason"
                                required
                                rows="3"
                                class="w-full rounded-md border border-border bg-background text-foreground shadow-sm focus:border-primary focus:ring-primary sm:text-sm"
                                placeholder="Describe why this charge is being disputed..."
                            />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" @click="showDisputeModal = false">Cancel</Button>
                        <Button type="submit">Submit Dispute</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <!-- Create Child Folio Modal -->
        <Dialog v-model:open="showCreateChildModal">
            <DialogContent class="sm:max-w-[500px]">
                <DialogHeader>
                    <DialogTitle>Create Child Folio</DialogTitle>
                </DialogHeader>
                <form @submit.prevent="submitCreateChild">
                    <div class="space-y-4 py-4">
                        <div class="grid gap-2">
                            <Label for="child_desc">Description</Label>
                            <Input id="child_desc" v-model="childForm.description" required placeholder="e.g. Corporate bill - Acme Corp" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="child_res">Reservation ID (optional)</Label>
                            <Input id="child_res" v-model="childForm.reservation_id" type="number" />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" @click="showCreateChildModal = false">Cancel</Button>
                        <Button type="submit">Create</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <!-- Transfer Modal -->
        <Dialog v-model:open="showTransferModal">
            <DialogContent class="sm:max-w-[500px]">
                <DialogHeader>
                    <DialogTitle>Transfer Charge</DialogTitle>
                </DialogHeader>
                <div v-if="selectedTransaction" class="mb-4 p-3 bg-muted rounded-md">
                    <p class="text-sm font-medium dark:text-foreground">{{ selectedTransaction.description }}</p>
                    <p class="text-sm text-muted-foreground">{{ formatCurrency(selectedTransaction.amount) }} - {{ getCategoryLabel(selectedTransaction.category) }}</p>
                </div>
                <form @submit.prevent="submitTransfer">
                    <div class="grid gap-2 mb-4">
                        <Label>Target Folio</Label>
                        <Select v-model="transferForm.target_folio_id">
                            <SelectTrigger>
                                <SelectValue placeholder="Select a folio..." />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem v-for="child in childFolios" :key="child.id" :value="String(child.id)">
                                    {{ child.folio_number }} - {{ child.description || 'Child folio' }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" @click="showTransferModal = false">Cancel</Button>
                        <Button type="submit">Transfer</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="showWindowModal">
            <DialogContent class="sm:max-w-[500px]">
                <DialogHeader>
                    <DialogTitle>Add Payer Window</DialogTitle>
                </DialogHeader>
                <form @submit.prevent="submitWindow">
                    <div class="grid gap-2 mb-4">
                        <Label>Code</Label>
                        <Input v-model="windowForm.code" placeholder="e.g. company" required />
                    </div>
                    <div class="grid gap-2 mb-4">
                        <Label>Payer</Label>
                        <Select v-model="windowForm.payer_type">
                            <SelectTrigger>
                                <SelectValue placeholder="Select payer..." />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="guest">Guest</SelectItem>
                                <SelectItem value="company">Company</SelectItem>
                                <SelectItem value="group_master">Group master</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" @click="showWindowModal = false">Cancel</Button>
                        <Button type="submit">Create</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="showSplitModal">
            <DialogContent class="sm:max-w-[500px]">
                <DialogHeader>
                    <DialogTitle>Split Charge</DialogTitle>
                </DialogHeader>
                <div v-if="selectedTransaction" class="mb-4 p-3 bg-muted rounded-md">
                    <p class="text-sm font-medium dark:text-foreground">{{ selectedTransaction.description }}</p>
                    <p class="text-sm text-muted-foreground">{{ formatCurrency(selectedTransaction.amount) }} across windows by percent.</p>
                </div>
                <form @submit.prevent="submitSplit">
                    <div v-for="(leg, i) in splitForm.legs" :key="i" class="grid grid-cols-2 gap-2 mb-2">
                        <Input v-model="leg.window_code" placeholder="window code" required />
                        <Input v-model.number="leg.percent_bps" type="number" min="1" max="10000" placeholder="bps (10000 = 100%)" required />
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" @click="showSplitModal = false">Cancel</Button>
                        <Button type="submit">Split</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
