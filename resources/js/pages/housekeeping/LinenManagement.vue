<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ref } from 'vue';

interface LinenItem {
    id: number;
    name: string;
    category: string;
    par_level: number;
    current_clean: number;
    current_dirty: number;
    current_in_laundry: number;
    unit_cost_minor: number;
}

interface LaundryBatch {
    id: number;
    batch_number: string;
    vendor_type: string;
    vendor_name: string | null;
    status: string;
    item_counts: Record<number, number>;
    dispatched_at: string;
    returned_at: string | null;
    dispatcher?: { name: string };
    receiver?: { name: string } | null;
}

const props = defineProps<{
    branch: { id: number; name: string; code: string };
    items: LinenItem[];
    summary: Record<string, {
        total_clean: number;
        total_dirty: number;
        total_in_laundry: number;
        total_par: number;
        items_below_par: number;
    }>;
    alerts: LinenItem[];
    batches: LaundryBatch[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Housekeeping', href: '#' },
            { title: 'Linen Management', href: '#' },
        ],
    },
});

const showDispatchModal = ref(false);
const showDiscardModal = ref(false);

const dispatchForm = useForm({
    item_counts: {} as Record<number, number>,
    vendor_type: 'internal',
    vendor_name: '',
});

const discardForm = useForm({
    linen_item_id: props.items[0]?.id || 0,
    quantity: 1,
    reason: 'torn',
    from_pool: 'dirty',
    notes: '',
});

const openDispatch = () => {
    dispatchForm.item_counts = {};
    props.items.forEach((item) => {
        if (item.current_dirty > 0) {
            dispatchForm.item_counts[item.id] = item.current_dirty;
        }
    });
    showDispatchModal.value = true;
};

const submitDispatch = () => {
    dispatchForm.post(`/branches/${props.branch.id}/housekeeping/linen/dispatch`, {
        onSuccess: () => {
            showDispatchModal.value = false;
        },
    });
};

const submitDiscard = () => {
    discardForm.post(`/branches/${props.branch.id}/housekeeping/linen/discard`, {
        onSuccess: () => {
            showDiscardModal.value = false;
            discardForm.reset();
        },
    });
};

const receiveBatch = (batchId: number) => {
    router.post(`/branches/${props.branch.id}/housekeeping/linen/batches/${batchId}/receive`);
};
</script>

<template>
    <div class="p-6">
        <Head title="Linen Management" />

        <div class="mb-6 flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-foreground">Linen & Laundry Tracking</h1>
                <p class="text-sm text-muted-foreground">{{ branch.name }} ({{ branch.code }})</p>
            </div>
            <div class="flex gap-2">
                <Button variant="outline" @click="showDiscardModal = true">Record Discard / Loss</Button>
                <Button @click="openDispatch">Dispatch to Laundry</Button>
            </div>
        </div>

        <!-- Par Level Alerts Banner -->
        <div v-if="alerts.length > 0" class="mb-6 rounded-lg border border-red-300 bg-red-50 p-4 dark:border-red-800 dark:bg-red-950/40">
            <h2 class="text-sm font-semibold text-red-800 dark:text-red-200">Par Level Alerts: Items Below Safety Stock</h2>
            <div class="mt-2 flex flex-wrap gap-2">
                <Badge v-for="item in alerts" :key="item.id" variant="destructive">
                    {{ item.name }}: {{ item.current_clean + item.current_in_laundry }} / {{ item.par_level }} Par
                </Badge>
            </div>
        </div>

        <!-- Category Summary Cards -->
        <div class="mb-8 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div
                v-for="(stat, cat) in summary"
                :key="cat"
                class="rounded-lg border border-border bg-card p-4 shadow-sm"
            >
                <h3 class="text-xs font-semibold uppercase text-muted-foreground">{{ cat.replace('_', ' ') }}</h3>
                <div class="mt-2 flex items-baseline justify-between">
                    <span class="text-2xl font-bold text-foreground">{{ stat.total_clean }}</span>
                    <span class="text-xs text-muted-foreground">Clean in circulation</span>
                </div>
                <div class="mt-2 flex justify-between text-xs text-muted-foreground">
                    <span>Dirty: {{ stat.total_dirty }}</span>
                    <span>In Wash: {{ stat.total_in_laundry }}</span>
                    <span>Par: {{ stat.total_par }}</span>
                </div>
            </div>
        </div>

        <!-- Linen Items Table -->
        <div class="rounded-lg border border-border bg-card">
            <div class="border-b border-border p-4">
                <h2 class="text-base font-semibold text-foreground">Linen Inventory & Par Levels</h2>
            </div>
            <table class="w-full caption-bottom text-sm">
                <thead class="bg-muted/50">
                    <tr class="border-b border-border text-left">
                        <th class="h-12 px-4 font-medium text-muted-foreground">Item Name</th>
                        <th class="h-12 px-4 font-medium text-muted-foreground">Category</th>
                        <th class="h-12 px-4 font-medium text-muted-foreground">Par Level</th>
                        <th class="h-12 px-4 font-medium text-muted-foreground">Clean (Available)</th>
                        <th class="h-12 px-4 font-medium text-muted-foreground">Dirty (Hamper)</th>
                        <th class="h-12 px-4 font-medium text-muted-foreground">In Laundry</th>
                        <th class="h-12 px-4 font-medium text-muted-foreground">Unit Cost</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="item in items" :key="item.id" class="border-b border-border/50 hover:bg-muted/20">
                        <td class="p-4 font-semibold">{{ item.name }}</td>
                        <td class="p-4 capitalize text-muted-foreground">{{ item.category.replace('_', ' ') }}</td>
                        <td class="p-4 font-medium">{{ item.par_level }}</td>
                        <td class="p-4 font-bold text-emerald-600 dark:text-emerald-400">{{ item.current_clean }}</td>
                        <td class="p-4 text-amber-600 dark:text-amber-400">{{ item.current_dirty }}</td>
                        <td class="p-4 text-blue-600 dark:text-blue-400">{{ item.current_in_laundry }}</td>
                        <td class="p-4 text-muted-foreground">₦{{ (item.unit_cost_minor / 100).toFixed(2) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Dispatched Batches -->
        <div class="mt-8 rounded-lg border border-border bg-card">
            <div class="border-b border-border p-4">
                <h2 class="text-base font-semibold text-foreground">Recent Laundry Batches</h2>
            </div>
            <table class="w-full caption-bottom text-sm">
                <thead class="bg-muted/50">
                    <tr class="border-b border-border text-left">
                        <th class="h-12 px-4 font-medium text-muted-foreground">Batch #</th>
                        <th class="h-12 px-4 font-medium text-muted-foreground">Vendor</th>
                        <th class="h-12 px-4 font-medium text-muted-foreground">Dispatched At</th>
                        <th class="h-12 px-4 font-medium text-muted-foreground">Dispatched By</th>
                        <th class="h-12 px-4 font-medium text-muted-foreground">Status</th>
                        <th class="h-12 px-4 font-medium text-muted-foreground">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="batch in batches" :key="batch.id" class="border-b border-border/50 hover:bg-muted/20">
                        <td class="p-4 font-mono font-medium">{{ batch.batch_number }}</td>
                        <td class="p-4 capitalize">{{ batch.vendor_type }} {{ batch.vendor_name ? `(${batch.vendor_name})` : '' }}</td>
                        <td class="p-4">{{ new Date(batch.dispatched_at).toLocaleString() }}</td>
                        <td class="p-4">{{ batch.dispatcher?.name }}</td>
                        <td class="p-4">
                            <Badge :variant="batch.status === 'returned' ? 'default' : 'secondary'" class="capitalize">
                                {{ batch.status }}
                            </Badge>
                        </td>
                        <td class="p-4">
                            <Button
                                v-if="batch.status === 'sent' || batch.status === 'in_wash'"
                                size="sm"
                                variant="outline"
                                @click="receiveBatch(batch.id)"
                            >
                                Receive Batch
                            </Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Dispatch Modal -->
        <div v-if="showDispatchModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div class="w-full max-w-lg rounded-lg border border-border bg-background p-6 shadow-xl">
                <h3 class="text-lg font-semibold">Dispatch Laundry Batch</h3>
                <form @submit.prevent="submitDispatch" class="mt-4 space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="text-sm font-medium">Vendor Type</label>
                            <select v-model="dispatchForm.vendor_type" class="mt-1 w-full rounded-md border border-input bg-background px-3 py-2 text-sm">
                                <option value="internal">Internal In-House Laundry</option>
                                <option value="outsourced">External Outsourced Partner</option>
                            </select>
                        </div>
                        <div v-if="dispatchForm.vendor_type === 'outsourced'">
                            <label class="text-sm font-medium">Vendor Name</label>
                            <input v-model="dispatchForm.vendor_name" type="text" class="mt-1 w-full rounded-md border border-input bg-background px-3 py-2 text-sm" placeholder="e.g. CleanPro Drycleaners" />
                        </div>
                    </div>

                    <div>
                        <label class="text-sm font-medium">Items to Send (from Dirty Hamper)</label>
                        <div class="mt-2 max-h-60 space-y-2 overflow-y-auto">
                            <div v-for="item in items" :key="item.id" class="flex items-center justify-between gap-4">
                                <span class="text-sm">{{ item.name }} (Dirty: {{ item.current_dirty }})</span>
                                <input
                                    v-model.number="dispatchForm.item_counts[item.id]"
                                    type="number"
                                    min="0"
                                    :max="item.current_dirty"
                                    class="w-24 rounded-md border border-input bg-background px-3 py-1 text-sm text-right"
                                />
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end gap-2 pt-2">
                        <Button type="button" variant="outline" @click="showDispatchModal = false">Cancel</Button>
                        <Button type="submit" :disabled="dispatchForm.processing">Dispatch Batch</Button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Discard Modal -->
        <div v-if="showDiscardModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div class="w-full max-w-md rounded-lg border border-border bg-background p-6 shadow-xl">
                <h3 class="text-lg font-semibold">Record Linen Discard / Stained Loss</h3>
                <form @submit.prevent="submitDiscard" class="mt-4 space-y-4">
                    <div>
                        <label class="text-sm font-medium">Linen Item</label>
                        <select v-model="discardForm.linen_item_id" class="mt-1 w-full rounded-md border border-input bg-background px-3 py-2 text-sm">
                            <option v-for="item in items" :key="item.id" :value="item.id">{{ item.name }}</option>
                        </select>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="text-sm font-medium">Quantity</label>
                            <input v-model.number="discardForm.quantity" type="number" min="1" required class="mt-1 w-full rounded-md border border-input bg-background px-3 py-2 text-sm" />
                        </div>
                        <div>
                            <label class="text-sm font-medium">Reason</label>
                            <select v-model="discardForm.reason" class="mt-1 w-full rounded-md border border-input bg-background px-3 py-2 text-sm">
                                <option value="torn">Torn / Ripped</option>
                                <option value="stained">Permanent Stain</option>
                                <option value="burn">Burn Mark</option>
                                <option value="worn_out">Worn Out / Threadbare</option>
                                <option value="missing">Missing / Stolen</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="text-sm font-medium">Deduct From</label>
                        <select v-model="discardForm.from_pool" class="mt-1 w-full rounded-md border border-input bg-background px-3 py-2 text-sm">
                            <option value="dirty">Dirty Hamper Pool</option>
                            <option value="clean">Clean Storage Pool</option>
                        </select>
                    </div>

                    <div>
                        <label class="text-sm font-medium">Notes</label>
                        <textarea v-model="discardForm.notes" rows="2" class="mt-1 w-full rounded-md border border-input bg-background px-3 py-2 text-sm" placeholder="Optional explanation"></textarea>
                    </div>

                    <div class="flex justify-end gap-2 pt-2">
                        <Button type="button" variant="outline" @click="showDiscardModal = false">Cancel</Button>
                        <Button type="submit" variant="destructive" :disabled="discardForm.processing">Record Discard</Button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
