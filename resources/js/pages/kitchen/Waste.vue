<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import Pagination from '@/components/ui/pagination/Pagination.vue';

interface WasteLog { id: number; reason: string; quantity: number; cost: number; notes: string | null; created_at: string; menu_item?: { name: string }; }
interface MenuItem { id: number; name: string; price: number; }

const props = defineProps<{ wasteLogs: { data: WasteLog[]; current_page: number; last_page: number; total: number; }; menuItems: MenuItem[]; filters?: { from?: string; to?: string }; }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }, { title: 'Kitchen Waste', href: '/kitchen/waste' }] } });

const showModal = ref(false);
const form = ref({ menu_item_id: '', reason: 'expired', quantity: '1', cost: '0', notes: '' });
const dateFrom = ref(props.filters?.from ?? '');
const dateTo = ref(props.filters?.to ?? '');
import { formatCurrency, getCurrencySymbol } from '@/lib/format';
const page = usePage();
const branchSymbol = computed(() => (page.props.branch?.current as any)?.currency_symbol || '$');
const resolveSymbol = (code?: string) => getCurrencySymbol(code || 'NGN') || branchSymbol.value;
const formatMoney = (cents: number, currencyCode?: string) => formatCurrency(cents, resolveSymbol(currencyCode));

const submit = () => { router.post('/kitchen/waste', { ...form.value, quantity: parseInt(form.value.quantity) || 1, cost: parseInt(form.value.cost) || 0 }, { onSuccess: () => { showModal.value = false; form.value = { menu_item_id: '', reason: 'expired', quantity: '1', cost: '0', notes: '' }; } }); };
const goToPage = (page: number) => router.get('/kitchen/waste', { page, from: dateFrom.value, to: dateTo.value }, { preserveState: true, replace: true });
const applyFilters = () => router.get('/kitchen/waste', { from: dateFrom.value, to: dateTo.value }, { preserveState: true, replace: true });
</script>

<template>
    <Head title="Kitchen Waste Log" />
<div class="p-6">
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-bold text-foreground">Kitchen Waste Log</h1>
        <div class="flex gap-2"><Button variant="outline" @click="router.get('/kitchen/waste/report')">View Report</Button><Button @click="showModal = true">Log Waste</Button></div>
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
            <thead class="bg-muted/50"><tr><th class="h-12 px-4 text-left font-medium text-muted-foreground">Item</th><th class="h-12 px-4 text-left font-medium text-muted-foreground">Reason</th><th class="h-12 px-4 text-left font-medium text-muted-foreground">Qty</th><th class="h-12 px-4 text-left font-medium text-muted-foreground">Cost</th><th class="h-12 px-4 text-left font-medium text-muted-foreground">Date</th></tr></thead>
            <tbody>
                <tr v-for="log in wasteLogs.data" :key="log.id" class="border-t border-border hover:bg-muted/50">
                    <td class="p-4 text-foreground">{{ log.menu_item?.name ?? 'N/A' }}</td>
                    <td class="p-4 capitalize text-muted-foreground">{{ log.reason }}</td>
                    <td class="p-4 text-foreground">{{ log.quantity }}</td>
                    <td class="p-4 text-foreground">{{ formatMoney(log.cost) }}</td>
                    <td class="p-4 text-muted-foreground">{{ new Date(log.created_at).toLocaleDateString() }}</td>
                </tr>
                <tr v-if="wasteLogs.data.length === 0"><td colspan="5" class="p-4 text-center text-muted-foreground">No waste logs found.</td></tr>
            </tbody>
        </table>
    </div>
    <div class="mt-4"><Pagination :data="wasteLogs" label="logs" @page-change="goToPage" /></div>

    <Dialog :open="showModal" @update:open="showModal = $event">
        <DialogContent>
            <DialogHeader><DialogTitle>Log Waste</DialogTitle></DialogHeader>
            <form @submit.prevent="submit" class="space-y-4">
                <div class="grid gap-2"><Label>Menu Item</Label><Select v-model="form.menu_item_id"><SelectTrigger><SelectValue placeholder="Select item" /></SelectTrigger><SelectContent><SelectItem v-for="m in menuItems" :key="m.id" :value="String(m.id)">{{ m.name }}</SelectItem></SelectContent></Select></div>
                <div class="grid gap-2"><Label>Reason</Label><Select v-model="form.reason"><SelectTrigger><SelectValue /></SelectTrigger><SelectContent><SelectItem value="expired">Expired</SelectItem><SelectItem value="burned">Burned</SelectItem><SelectItem value="returned">Returned</SelectItem><SelectItem value="overproduced">Overproduced</SelectItem></SelectContent></Select></div>
                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2"><Label>Quantity</Label><Input v-model="form.quantity" type="number" min="1" required /></div>
                    <div class="grid gap-2"><Label>Cost (cents)</Label><Input v-model="form.cost" type="number" min="0" required /></div>
                </div>
                <div class="grid gap-2"><Label>Notes</Label><Input v-model="form.notes" /></div>
                <DialogFooter><div class="flex justify-end gap-2"><Button variant="outline" type="button" @click="showModal = false">Cancel</Button><Button type="submit">Log</Button></div></DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</div>
</template>
