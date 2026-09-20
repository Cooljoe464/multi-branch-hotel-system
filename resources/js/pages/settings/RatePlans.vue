<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import Pagination from '@/components/ui/pagination/Pagination.vue';
import { formatCurrency as formatCurrencyRaw, getCurrencySymbol } from '@/lib/format';

interface RatePlan { id: number; name: string; code: string; type: string; rate_multiplier: number; min_rate: number | null; max_rate: number | null; is_negotiable: boolean; is_active: boolean; valid_from: string; valid_to: string | null; }

const props = defineProps<{
    ratePlans: { data: RatePlan[]; current_page: number; last_page: number; per_page: number; total: number; };
    filters: { type?: string; };
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }, { title: 'Rate Plans', href: '/rate-plans' }] } });

const page = usePage();
const branchSymbol = computed(() => (page.props.branch?.current as any)?.currency_symbol || '$');
const resolveSymbol = (code?: string) => getCurrencySymbol(code || 'NGN') || branchSymbol.value;
const formatCurrency = (amount: number, currencyCode?: string) => formatCurrencyRaw(amount, resolveSymbol(currencyCode));

const showModal = ref(false);
const editing = ref<RatePlan | null>(null);
const form = ref({ name: '', code: '', type: 'bar', rate_multiplier: '1.00', min_rate: '', max_rate: '', is_negotiable: false, valid_from: new Date().toISOString().split('T')[0], valid_to: '' });

const typeBadge: Record<string, string> = { bar: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300', corporate: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300', package: 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-300', promotional: 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-300' };

const submit = () => {
    const data = { ...form.value, rate_multiplier: parseFloat(form.value.rate_multiplier) || 1, min_rate: form.value.min_rate ? parseInt(form.value.min_rate) : null, max_rate: form.value.max_rate ? parseInt(form.value.max_rate) : null, is_negotiable: form.value.is_negotiable, valid_to: form.value.valid_to || null };
    if (editing.value) { router.put(`/rate-plans/${editing.value.id}`, data, { onSuccess: () => { showModal.value = false; editing.value = null; } }); }
    else { router.post('/rate-plans', data, { onSuccess: () => { showModal.value = false; form.value = { name: '', code: '', type: 'bar', rate_multiplier: '1.00', min_rate: '', max_rate: '', is_negotiable: false, valid_from: new Date().toISOString().split('T')[0], valid_to: '' }; } }); }
};

const openEdit = (rp: RatePlan) => { editing.value = rp; form.value = { name: rp.name, code: rp.code, type: rp.type, rate_multiplier: String(rp.rate_multiplier), min_rate: rp.min_rate ? String(rp.min_rate) : '', max_rate: rp.max_rate ? String(rp.max_rate) : '', is_negotiable: rp.is_negotiable, valid_from: rp.valid_from, valid_to: rp.valid_to || '' }; showModal.value = true; };
const deleteItem = (rp: RatePlan) => { if (confirm(`Delete ${rp.name}?`)) router.delete(`/rate-plans/${rp.id}`); };
const goToPage = (page: number) => router.get('/rate-plans', { page }, { preserveState: true, replace: true });
</script>

<template>
<div class="p-6">
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-bold text-foreground">Rate Plans</h1>
        <Button @click="editing = null; showModal = true">Add Rate Plan</Button>
    </div>
    <div class="rounded-lg border border-border">
        <table class="w-full caption-bottom text-sm">
            <thead class="bg-muted/50"><tr>
                <th class="h-12 px-4 text-left font-medium text-muted-foreground">Name</th>
                <th class="h-12 px-4 text-left font-medium text-muted-foreground">Code</th>
                <th class="h-12 px-4 text-left font-medium text-muted-foreground">Type</th>
                <th class="h-12 px-4 text-left font-medium text-muted-foreground">Multiplier</th>
                <th class="h-12 px-4 text-left font-medium text-muted-foreground">Min Rate</th>
                <th class="h-12 px-4 text-left font-medium text-muted-foreground">Max Rate</th>
                <th class="h-12 px-4 text-left font-medium text-muted-foreground">Negotiable</th>
                <th class="h-12 px-4 text-left font-medium text-muted-foreground">Valid From</th>
                <th class="h-12 px-4 text-left font-medium text-muted-foreground">Active</th>
                <th class="h-12 px-4 text-right font-medium text-muted-foreground">Actions</th>
            </tr></thead>
            <tbody>
                <tr v-for="rp in ratePlans.data" :key="rp.id" class="border-t border-border hover:bg-muted/50">
                    <td class="p-4 font-medium text-foreground">{{ rp.name }}</td>
                    <td class="p-4 text-muted-foreground">{{ rp.code }}</td>
                    <td class="p-4"><Badge :class="typeBadge[rp.type]" variant="outline" class="capitalize">{{ rp.type }}</Badge></td>
                    <td class="p-4 text-foreground">{{ rp.rate_multiplier }}x</td>
                    <td class="p-4 text-muted-foreground">{{ rp.min_rate != null ? formatCurrency(rp.min_rate) : '-' }}</td>
                    <td class="p-4 text-muted-foreground">{{ rp.max_rate != null ? formatCurrency(rp.max_rate) : '-' }}</td>
                    <td class="p-4">{{ rp.is_negotiable ? '✓' : '-' }}</td>
                    <td class="p-4 text-muted-foreground">{{ rp.valid_from }}</td>
                    <td class="p-4"><Badge :class="rp.is_active ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300' : 'bg-muted text-muted-foreground'" variant="outline">{{ rp.is_active ? 'Active' : 'Inactive' }}</Badge></td>
                    <td class="p-4 text-right"><div class="flex justify-end gap-1"><Button variant="outline" size="sm" @click="openEdit(rp)">Edit</Button><Button variant="destructive" size="sm" @click="deleteItem(rp)">×</Button></div></td>
                </tr>
                <tr v-if="ratePlans.data.length === 0"><td colspan="10" class="p-4 text-center text-muted-foreground">No rate plans found.</td></tr>
            </tbody>
        </table>
    </div>
    <div class="mt-4"><Pagination :data="ratePlans" label="rate plans" @page-change="goToPage" /></div>
    <Dialog :open="showModal" @update:open="showModal = $event">
        <DialogContent class="max-w-lg">
            <DialogHeader><DialogTitle>{{ editing ? 'Edit' : 'Add' }} Rate Plan</DialogTitle></DialogHeader>
            <form @submit.prevent="submit" class="space-y-4">
                <div class="grid gap-2"><Label>Name</Label><Input v-model="form.name" required /></div>
                <div class="grid gap-2"><Label>Code</Label><Input v-model="form.code" required /></div>
                <div class="grid gap-2"><Label>Type</Label><Select v-model="form.type"><SelectTrigger><SelectValue /></SelectTrigger><SelectContent><SelectItem value="bar">BAR</SelectItem><SelectItem value="corporate">Corporate</SelectItem><SelectItem value="package">Package</SelectItem><SelectItem value="promotional">Promotional</SelectItem></SelectContent></Select></div>
                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2"><Label>Rate Multiplier</Label><Input v-model="form.rate_multiplier" type="number" step="0.01" min="0.01" required /></div>
                    <div class="grid gap-2"><Label>Valid From</Label><Input v-model="form.valid_from" type="date" required /></div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2"><Label>Min Rate (cents, optional)</Label><Input v-model="form.min_rate" type="number" min="0" placeholder="No minimum" /></div>
                    <div class="grid gap-2"><Label>Max Rate (cents, optional)</Label><Input v-model="form.max_rate" type="number" min="0" placeholder="No maximum" /></div>
                </div>
                <div class="flex items-center gap-2"><Checkbox id="negotiable" v-model:checked="form.is_negotiable" /><Label for="negotiable">Allow negotiated rates</Label></div>
                <div class="grid gap-2"><Label>Valid To (optional)</Label><Input v-model="form.valid_to" type="date" /></div>
                <DialogFooter><div class="flex justify-end gap-2"><Button variant="outline" type="button" @click="showModal = false">Cancel</Button><Button type="submit">{{ editing ? 'Save' : 'Create' }}</Button></div></DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</div>
</template>
