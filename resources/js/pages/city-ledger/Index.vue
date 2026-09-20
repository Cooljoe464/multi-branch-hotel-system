<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import Pagination from '@/components/ui/pagination/Pagination.vue';

interface CityLedgerAccount { id: number; company_name: string; contact_name: string; email: string; credit_limit: number; balance_owing: number; is_active: boolean; }

const props = defineProps<{ cityLedgerAccounts: { data: CityLedgerAccount[]; current_page: number; last_page: number; total: number; }; }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }, { title: 'City Ledger', href: '/city-ledger' }] } });

const showModal = ref(false);
const form = ref({ company_name: '', contact_name: '', email: '', phone: '', credit_limit: '500000', payment_terms_days: '30' });
import { formatCurrency, getCurrencySymbol } from '@/lib/format';
const page = usePage();
const branchSymbol = computed(() => (page.props.branch?.current as any)?.currency_symbol || '$');
const resolveSymbol = (code?: string) => getCurrencySymbol(code || 'NGN') || branchSymbol.value;
const formatMoney = (cents: number, currencyCode?: string) => formatCurrency(cents, resolveSymbol(currencyCode));

const submit = () => { router.post('/city-ledger', { ...form.value, credit_limit: parseInt(form.value.credit_limit) || 0, payment_terms_days: parseInt(form.value.payment_terms_days) || 30 }, { onSuccess: () => { showModal.value = false; form.value = { company_name: '', contact_name: '', email: '', phone: '', credit_limit: '500000', payment_terms_days: '30' }; } }); };
const goToPage = (page: number) => router.get('/city-ledger', { page }, { preserveState: true, replace: true });
</script>

<template>
    <Head title="City Ledger" />
<div class="p-6">
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-bold text-foreground">City Ledger</h1>
        <Button @click="showModal = true">Add Account</Button>
    </div>
    <div class="rounded-lg border border-border">
        <table class="w-full caption-bottom text-sm">
            <thead class="bg-muted/50"><tr>
                <th class="h-12 px-4 text-left font-medium text-muted-foreground">Company</th>
                <th class="h-12 px-4 text-left font-medium text-muted-foreground">Contact</th>
                <th class="h-12 px-4 text-left font-medium text-muted-foreground">Credit Limit</th>
                <th class="h-12 px-4 text-left font-medium text-muted-foreground">Balance Owing</th>
                <th class="h-12 px-4 text-right font-medium text-muted-foreground">Actions</th>
            </tr></thead>
            <tbody>
                <tr v-for="a in cityLedgerAccounts.data" :key="a.id" class="border-t border-border hover:bg-muted/50">
                    <td class="p-4 font-medium text-foreground">{{ a.company_name }}</td>
                    <td class="p-4 text-muted-foreground">{{ a.contact_name }}</td>
                    <td class="p-4 text-foreground">{{ formatMoney(a.credit_limit) }}</td>
                    <td class="p-4 text-foreground">{{ formatMoney(a.balance_owing) }}</td>
                    <td class="p-4 text-right"><div class="flex justify-end gap-1">
                        <Button variant="outline" size="sm" @click="router.get(`/city-ledger/${a.id}`)">View</Button>
                        <Button variant="outline" size="sm" @click="router.get(`/city-ledger/${a.id}/statement`)">Statement</Button>
                    </div></td>
                </tr>
                <tr v-if="cityLedgerAccounts.data.length === 0"><td colspan="5" class="p-4 text-center text-muted-foreground">No accounts.</td></tr>
            </tbody>
        </table>
    </div>
    <div class="mt-4"><Pagination :data="cityLedgerAccounts" label="accounts" @page-change="goToPage" /></div>

    <Dialog :open="showModal" @update:open="showModal = $event">
        <DialogContent class="max-w-lg">
            <DialogHeader><DialogTitle>Add City Ledger Account</DialogTitle></DialogHeader>
            <form @submit.prevent="submit" class="space-y-4">
                <div class="grid gap-2"><Label>Company Name</Label><Input v-model="form.company_name" required /></div>
                <div class="grid grid-cols-2 gap-4"><div class="grid gap-2"><Label>Contact Name</Label><Input v-model="form.contact_name" required /></div><div class="grid gap-2"><Label>Email</Label><Input v-model="form.email" type="email" required /></div></div>
                <div class="grid gap-2"><Label>Phone</Label><Input v-model="form.phone" /></div>
                <div class="grid grid-cols-2 gap-4"><div class="grid gap-2"><Label>Credit Limit (cents)</Label><Input v-model="form.credit_limit" type="number" min="0" required /></div><div class="grid gap-2"><Label>Payment Terms (days)</Label><Input v-model="form.payment_terms_days" type="number" min="1" required /></div></div>
                <DialogFooter><div class="flex justify-end gap-2"><Button variant="outline" type="button" @click="showModal = false">Cancel</Button><Button type="submit">Create</Button></div></DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</div>
</template>
