<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import Pagination from '@/components/ui/pagination/Pagination.vue';
import { ref } from 'vue';

interface FuelLog {
    id: number;
    log_type: string;
    liters: number;
    cost_per_liter_minor: number;
    total_cost_minor: number;
    supplier_name: string | null;
    invoice_number: string | null;
    dipstick_level_cm: number | null;
    logged_at: string;
    generator?: { name: string } | null;
    author?: { name: string };
}

interface GeneratorOption {
    id: number;
    name: string;
    kva_rating: number;
}

const props = defineProps<{
    branch: { id: number; name: string; code: string };
    fuelLogs: {
        data: FuelLog[];
        current_page: number;
        last_page: number;
        total: number;
    };
    generators: GeneratorOption[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Facilities', href: '#' },
            { title: 'Fuel Tracking', href: '#' },
        ],
    },
});

const showModal = ref(false);

const form = useForm({
    generator_id: null as number | null,
    log_type: 'delivery',
    liters: 0,
    cost_per_liter_minor: 1000,
    supplier_name: '',
    invoice_number: '',
    dipstick_level_cm: null as number | null,
});

const submitLog = () => {
    form.post(`/branches/${props.branch.id}/facilities/fuel/log`, {
        onSuccess: () => {
            showModal.value = false;
            form.reset();
        },
    });
};
</script>

<template>
    <div class="p-6">
        <Head title="Fuel Tracking" />

        <div class="mb-6 flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-foreground">Diesel & Fuel Tracking</h1>
                <p class="text-sm text-muted-foreground">{{ branch.name }} ({{ branch.code }})</p>
            </div>
            <div class="flex gap-2">
                <a :href="`/branches/${branch.id}/facilities/generators`">
                    <Button variant="outline">Back to Generators</Button>
                </a>
                <Button @click="showModal = true">Record Fuel Log</Button>
            </div>
        </div>

        <div class="rounded-lg border border-border bg-card">
            <table class="w-full caption-bottom text-sm">
                <thead class="bg-muted/50">
                    <tr class="border-b border-border text-left">
                        <th class="h-12 px-4 font-medium text-muted-foreground">Date</th>
                        <th class="h-12 px-4 font-medium text-muted-foreground">Type</th>
                        <th class="h-12 px-4 font-medium text-muted-foreground">Liters</th>
                        <th class="h-12 px-4 font-medium text-muted-foreground">Cost / Liter</th>
                        <th class="h-12 px-4 font-medium text-muted-foreground">Total Cost</th>
                        <th class="h-12 px-4 font-medium text-muted-foreground">Target / Tank</th>
                        <th class="h-12 px-4 font-medium text-muted-foreground">Dipstick</th>
                        <th class="h-12 px-4 font-medium text-muted-foreground">Logged By</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="log in fuelLogs.data" :key="log.id" class="border-b border-border/50 hover:bg-muted/20">
                        <td class="p-4">{{ new Date(log.logged_at).toLocaleDateString() }}</td>
                        <td class="p-4">
                            <Badge :variant="log.log_type === 'delivery' ? 'default' : 'secondary'">
                                {{ log.log_type }}
                            </Badge>
                        </td>
                        <td class="p-4 font-semibold">{{ log.liters }} L</td>
                        <td class="p-4">₦{{ (log.cost_per_liter_minor / 100).toFixed(2) }}</td>
                        <td class="p-4 font-medium">₦{{ (log.total_cost_minor / 100).toLocaleString() }}</td>
                        <td class="p-4">{{ log.generator?.name || 'Main Storage' }}</td>
                        <td class="p-4">{{ log.dipstick_level_cm ? `${log.dipstick_level_cm} cm` : '—' }}</td>
                        <td class="p-4 text-xs text-muted-foreground">{{ log.author?.name || 'System' }}</td>
                    </tr>
                    <tr v-if="fuelLogs.data.length === 0">
                        <td colspan="8" class="p-8 text-center text-muted-foreground">No fuel logs recorded yet.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Log Fuel Modal -->
        <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div class="w-full max-w-lg rounded-lg border border-border bg-background p-6 shadow-xl">
                <h3 class="text-lg font-semibold">Record Fuel Delivery / Consumption</h3>
                <form @submit.prevent="submitLog" class="mt-4 space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="text-sm font-medium">Log Type</label>
                            <select v-model="form.log_type" class="mt-1 w-full rounded-md border border-input bg-background px-3 py-2 text-sm">
                                <option value="delivery">Delivery (Purchase)</option>
                                <option value="consumption">Direct Consumption</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-sm font-medium">Destination Generator</label>
                            <select v-model="form.generator_id" class="mt-1 w-full rounded-md border border-input bg-background px-3 py-2 text-sm">
                                <option :value="null">Main Fuel Depot / Storage</option>
                                <option v-for="g in generators" :key="g.id" :value="g.id">{{ g.name }}</option>
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="text-sm font-medium">Quantity (Liters)</label>
                            <input v-model.number="form.liters" type="number" step="0.1" required class="mt-1 w-full rounded-md border border-input bg-background px-3 py-2 text-sm" />
                        </div>
                        <div>
                            <label class="text-sm font-medium">Cost / Liter (₦)</label>
                            <input v-model.number="form.cost_per_liter_minor" type="number" step="1" required class="mt-1 w-full rounded-md border border-input bg-background px-3 py-2 text-sm" />
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="text-sm font-medium">Supplier Name</label>
                            <input v-model="form.supplier_name" type="text" class="mt-1 w-full rounded-md border border-input bg-background px-3 py-2 text-sm" placeholder="e.g. TotalEnergies" />
                        </div>
                        <div>
                            <label class="text-sm font-medium">Invoice Number</label>
                            <input v-model="form.invoice_number" type="text" class="mt-1 w-full rounded-md border border-input bg-background px-3 py-2 text-sm" />
                        </div>
                    </div>

                    <div>
                        <label class="text-sm font-medium">Dipstick Level (cm)</label>
                        <input v-model.number="form.dipstick_level_cm" type="number" step="0.1" class="mt-1 w-full rounded-md border border-input bg-background px-3 py-2 text-sm" placeholder="Optional physical measurement" />
                    </div>

                    <div class="flex justify-end gap-2 pt-2">
                        <Button type="button" variant="outline" @click="showModal = false">Cancel</Button>
                        <Button type="submit" :disabled="form.processing">Save Fuel Log</Button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
