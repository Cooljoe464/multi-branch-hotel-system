<script setup lang="ts">
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { ArrowLeft } from '@lucide/vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';

interface GroupLedger {
    id: number;
    business_date: string;
    total_room_revenue: number;
    total_pos_revenue: number;
    total_tax: number;
    total_payments: number;
    net_revenue: number;
    currency_code: string;
    exchange_rate_to_group: number;
}

const props = defineProps<{
    groupLedger: GroupLedger;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Analytics', href: '/analytics' },
            { title: 'Group Ledger', href: '/group-ledgers' },
            { title: 'Entry', href: '#' },
        ],
    },
});

const showEditModal = ref(false);
const form = ref({
    total_room_revenue: String(props.groupLedger.total_room_revenue),
    total_pos_revenue: String(props.groupLedger.total_pos_revenue),
    total_tax: String(props.groupLedger.total_tax),
    total_payments: String(props.groupLedger.total_payments),
    net_revenue: String(props.groupLedger.net_revenue),
    currency_code: props.groupLedger.currency_code,
    exchange_rate_to_group: String(props.groupLedger.exchange_rate_to_group),
});

import { formatCurrency, getCurrencySymbol } from '@/lib/format';
const page = usePage();
const branchSymbol = computed(() => (page.props.branch?.current as any)?.currency_symbol || '$');
const resolveSymbol = (code?: string) => getCurrencySymbol(code || 'NGN') || branchSymbol.value;
const formatMoney = (cents: number, currencyCode?: string) => formatCurrency(cents, resolveSymbol(currencyCode));

const update = () => {
    router.put(`/group-ledgers/${props.groupLedger.id}`, {
        total_room_revenue: parseFloat(form.value.total_room_revenue) || 0,
        total_pos_revenue: parseFloat(form.value.total_pos_revenue) || 0,
        total_tax: parseFloat(form.value.total_tax) || 0,
        total_payments: parseFloat(form.value.total_payments) || 0,
        net_revenue: parseFloat(form.value.net_revenue) || 0,
        currency_code: form.value.currency_code,
        exchange_rate_to_group: parseFloat(form.value.exchange_rate_to_group) || 1,
    }, {
        onSuccess: () => { showEditModal.value = false; },
    });
};

const deleteEntry = () => {
    if (confirm('Delete this group ledger entry?')) {
        router.delete(`/group-ledgers/${props.groupLedger.id}`);
    }
};

const goBack = () => {
    window.history.back();
};
</script>

<template>
    <div class="p-6">
        <div class="mb-6 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <Button variant="ghost" size="sm" @click="goBack()" class="gap-1 text-muted-foreground hover:text-foreground">
                    <ArrowLeft class="size-4" /> Back
                </Button>
                <div>
                <h1 class="text-2xl font-bold text-foreground">Group Ledger - {{ groupLedger.business_date }}</h1>
                <p class="text-muted-foreground">{{ groupLedger.currency_code }}</p>
            </div>
            <div class="flex gap-2">
                <Button variant="outline" @click="showEditModal = true">Edit</Button>
                <Button variant="destructive" @click="deleteEntry">Delete</Button>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
            <div class="bg-card rounded-lg border border-border p-4">
                <div class="text-sm text-muted-foreground">Room Revenue</div>
                <div class="text-lg font-bold text-foreground">{{ formatMoney(groupLedger.total_room_revenue) }}</div>
            </div>
            <div class="bg-card rounded-lg border border-border p-4">
                <div class="text-sm text-muted-foreground">POS Revenue</div>
                <div class="text-lg font-bold text-foreground">{{ formatMoney(groupLedger.total_pos_revenue) }}</div>
            </div>
            <div class="bg-card rounded-lg border border-border p-4">
                <div class="text-sm text-muted-foreground">Tax</div>
                <div class="text-lg font-bold text-foreground">{{ formatMoney(groupLedger.total_tax) }}</div>
            </div>
            <div class="bg-card rounded-lg border border-border p-4">
                <div class="text-sm text-muted-foreground">Payments</div>
                <div class="text-lg font-bold text-foreground">{{ formatMoney(groupLedger.total_payments) }}</div>
            </div>
            <div class="bg-card rounded-lg border border-border p-4">
                <div class="text-sm text-muted-foreground">Net Revenue</div>
                <div class="text-lg font-bold text-foreground">{{ formatMoney(groupLedger.net_revenue) }}</div>
            </div>
            <div class="bg-card rounded-lg border border-border p-4">
                <div class="text-sm text-muted-foreground">Exchange Rate</div>
                <div class="text-lg font-bold text-foreground">{{ groupLedger.exchange_rate_to_group }}</div>
            </div>
        </div>

        <Dialog :open="showEditModal" @update:open="showEditModal = $event">
            <DialogContent class="max-w-lg">
                <DialogHeader>
                    <DialogTitle>Edit Group Ledger Entry</DialogTitle>
                </DialogHeader>
                <form @submit.prevent="update" class="space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div class="grid gap-2">
                            <Label>Room Revenue (cents)</Label>
                            <Input v-model="form.total_room_revenue" type="number" min="0" required />
                        </div>
                        <div class="grid gap-2">
                            <Label>POS Revenue (cents)</Label>
                            <Input v-model="form.total_pos_revenue" type="number" min="0" required />
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="grid gap-2">
                            <Label>Tax (cents)</Label>
                            <Input v-model="form.total_tax" type="number" min="0" required />
                        </div>
                        <div class="grid gap-2">
                            <Label>Payments (cents)</Label>
                            <Input v-model="form.total_payments" type="number" min="0" required />
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="grid gap-2">
                            <Label>Net Revenue (cents)</Label>
                            <Input v-model="form.net_revenue" type="number" min="0" required />
                        </div>
                        <div class="grid gap-2">
                            <Label>Currency</Label>
                            <Input v-model="form.currency_code" required />
                        </div>
                    </div>
                    <div class="grid gap-2">
                        <Label>Exchange Rate</Label>
                        <Input v-model="form.exchange_rate_to_group" type="number" step="0.000001" min="0" required />
                    </div>
                    <DialogFooter>
                        <div class="flex justify-end gap-2">
                            <Button variant="outline" type="button" @click="showEditModal = false">Cancel</Button>
                            <Button type="submit">Save</Button>
                        </div>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>