<script setup lang="ts">
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import Pagination from '@/components/ui/pagination/Pagination.vue';

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
    groupLedgers: {
        data: GroupLedger[];
        current_page: number;
        last_page: number;
        total: number;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Analytics', href: '/analytics' },
            { title: 'Group Ledger', href: '/group-ledgers' },
        ],
    },
});

const showModal = ref(false);
const branchCurrencyCode = computed(() => (page.props.branch?.current as any)?.currency_code || 'NGN');
const form = ref({
    business_date: new Date().toISOString().split('T')[0],
    total_room_revenue: '0',
    total_pos_revenue: '0',
    total_tax: '0',
    total_payments: '0',
    net_revenue: '0',
    currency_code: branchCurrencyCode.value,
    exchange_rate_to_group: '1.000000',
});

import { formatCurrency, getCurrencySymbol } from '@/lib/format';
const page = usePage();
const branchSymbol = computed(() => (page.props.branch?.current as any)?.currency_symbol || '$');
const resolveSymbol = (code?: string) => getCurrencySymbol(code || 'NGN') || branchSymbol.value;
const formatMoney = (cents: number, currencyCode?: string) => formatCurrency(cents, resolveSymbol(currencyCode));

const submit = () => {
    router.post('/group-ledgers', {
        ...form.value,
        total_room_revenue: parseFloat(form.value.total_room_revenue) || 0,
        total_pos_revenue: parseFloat(form.value.total_pos_revenue) || 0,
        total_tax: parseFloat(form.value.total_tax) || 0,
        total_payments: parseFloat(form.value.total_payments) || 0,
        net_revenue: parseFloat(form.value.net_revenue) || 0,
        exchange_rate_to_group: parseFloat(form.value.exchange_rate_to_group) || 1,
    }, {
        onSuccess: () => {
            showModal.value = false;
            form.value = {
                business_date: new Date().toISOString().split('T')[0],
                total_room_revenue: '0',
                total_pos_revenue: '0',
                total_tax: '0',
                total_payments: '0',
                net_revenue: '0',
                currency_code: branchCurrencyCode.value,
                exchange_rate_to_group: '1.000000',
            };
        },
    });
};

const deleteItem = (id: number) => {
    if (confirm('Delete this group ledger entry?')) {
        router.delete(`/group-ledgers/${id}`);
    }
};

const goToPage = (page: number) => {
    router.get('/group-ledgers', { page }, { preserveState: true, replace: true });
};
</script>

<template>
    <div class="p-6">
        <div class="mb-6 flex items-center justify-between">
            <h1 class="text-2xl font-bold text-foreground">Group Ledger</h1>
            <Button @click="showModal = true">Add Entry</Button>
        </div>
        <div class="rounded-lg border border-border">
            <table class="w-full caption-bottom text-sm">
                <thead class="bg-muted/50">
                    <tr>
                        <th class="h-12 px-4 text-left font-medium text-muted-foreground">Date</th>
                        <th class="h-12 px-4 text-right font-medium text-muted-foreground">Room Revenue</th>
                        <th class="h-12 px-4 text-right font-medium text-muted-foreground">POS Revenue</th>
                        <th class="h-12 px-4 text-right font-medium text-muted-foreground">Tax</th>
                        <th class="h-12 px-4 text-right font-medium text-muted-foreground">Net Revenue</th>
                        <th class="h-12 px-4 text-right font-medium text-muted-foreground">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="entry in groupLedgers.data" :key="entry.id" class="border-t border-border hover:bg-muted/50">
                        <td class="p-4 font-medium text-foreground">{{ entry.business_date }}</td>
                        <td class="p-4 text-right text-foreground">{{ formatMoney(entry.total_room_revenue) }}</td>
                        <td class="p-4 text-right text-foreground">{{ formatMoney(entry.total_pos_revenue) }}</td>
                        <td class="p-4 text-right text-foreground">{{ formatMoney(entry.total_tax) }}</td>
                        <td class="p-4 text-right text-foreground">{{ formatMoney(entry.net_revenue) }}</td>
                        <td class="p-4 text-right">
                            <div class="flex justify-end gap-1">
                                <Button variant="outline" size="sm" @click="router.get(`/group-ledgers/${entry.id}`)">View</Button>
                                <Button variant="destructive" size="sm" @click="deleteItem(entry.id)">×</Button>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="groupLedgers.data.length === 0">
                        <td colspan="6" class="p-4 text-center text-muted-foreground">No group ledger entries found.</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="mt-4">
            <Pagination :data="groupLedgers" label="entries" @page-change="goToPage" />
        </div>

        <Dialog :open="showModal" @update:open="showModal = $event">
            <DialogContent class="max-w-lg">
                <DialogHeader>
                    <DialogTitle>Add Group Ledger Entry</DialogTitle>
                </DialogHeader>
                <form @submit.prevent="submit" class="space-y-4">
                    <div class="grid gap-2">
                        <Label>Business Date</Label>
                        <Input v-model="form.business_date" type="date" required />
                    </div>
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
                            <Button variant="outline" type="button" @click="showModal = false">Cancel</Button>
                            <Button type="submit">Create</Button>
                        </div>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>