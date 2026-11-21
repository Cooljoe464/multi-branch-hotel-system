<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ref } from 'vue';

interface GeneratorRun {
    id: number;
    started_at: string;
    stopped_at: string | null;
    start_meter_hours: number;
    end_meter_hours: number | null;
    run_hours: number | null;
    fuel_consumed_liters: number | null;
    reason: string;
    operator?: { name: string };
    generator?: { name: string };
}

interface Generator {
    id: number;
    name: string;
    model: string | null;
    kva_rating: number;
    fuel_capacity_liters: number;
    liters_per_hour_rated: number;
    current_meter_hours: number;
    status: string;
    last_serviced_at: string | null;
    runs?: GeneratorRun[];
}

const props = defineProps<{
    branch: { id: number; name: string; code: string };
    generators: Generator[];
    activeRuns: GeneratorRun[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Facilities', href: '#' },
            { title: 'Generators', href: '#' },
        ],
    },
});

const startForm = useForm({
    reason: 'Power Outage',
});

const stopForm = useForm({
    end_meter_hours: 0,
    fuel_consumed_liters: null as number | null,
});

const selectedGen = ref<Generator | null>(null);
const selectedRun = ref<GeneratorRun | null>(null);

const openStartModal = (gen: Generator) => {
    selectedGen.value = gen;
    startForm.reset();
};

const openStopModal = (run: GeneratorRun) => {
    selectedRun.value = run;
    stopForm.end_meter_hours = run.start_meter_hours;
    stopForm.fuel_consumed_liters = null;
};

const submitStart = () => {
    if (!selectedGen.value) return;
    startForm.post(`/branches/${props.branch.id}/facilities/generators/${selectedGen.value.id}/start`, {
        onSuccess: () => {
            selectedGen.value = null;
        },
    });
};

const submitStop = () => {
    if (!selectedRun.value) return;
    stopForm.post(`/branches/${props.branch.id}/facilities/generator-runs/${selectedRun.value.id}/stop`, {
        onSuccess: () => {
            selectedRun.value = null;
        },
    });
};
</script>

<template>
    <div class="p-6">
        <Head title="Generators & Energy" />

        <div class="mb-6 flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-foreground">Generators & Energy Management</h1>
                <p class="text-sm text-muted-foreground">{{ branch.name }} ({{ branch.code }})</p>
            </div>
            <div class="flex gap-2">
                <a :href="`/branches/${branch.id}/facilities/fuel`">
                    <Button variant="outline">Fuel Tracking</Button>
                </a>
                <a :href="`/branches/${branch.id}/facilities/meters`">
                    <Button variant="outline">Utility Meters</Button>
                </a>
            </div>
        </div>

        <!-- Active Runs Banner -->
        <div v-if="activeRuns.length > 0" class="mb-6 rounded-lg border border-amber-300 bg-amber-50 p-4 dark:border-amber-700 dark:bg-amber-950/40">
            <h2 class="text-sm font-semibold text-amber-800 dark:text-amber-200">Active Generator Runs (Running Now)</h2>
            <div class="mt-2 space-y-2">
                <div v-for="run in activeRuns" :key="run.id" class="flex items-center justify-between text-sm">
                    <div>
                        <span class="font-medium text-amber-900 dark:text-amber-100">{{ run.generator?.name }}</span>
                        — Started at {{ new Date(run.started_at).toLocaleTimeString() }} ({{ run.reason }}) by {{ run.operator?.name }}
                    </div>
                    <Button size="sm" variant="destructive" @click="openStopModal(run)">
                        Stop Run
                    </Button>
                </div>
            </div>
        </div>

        <!-- Generators Grid -->
        <div class="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-3">
            <div
                v-for="gen in generators"
                :key="gen.id"
                class="rounded-lg border border-border bg-card p-5 shadow-sm"
            >
                <div class="flex items-start justify-between">
                    <div>
                        <h3 class="text-lg font-semibold text-foreground">{{ gen.name }}</h3>
                        <p class="text-xs text-muted-foreground">{{ gen.model || 'Standard Unit' }} &bull; {{ gen.kva_rating }} kVA</p>
                    </div>
                    <Badge :variant="gen.status === 'running' ? 'default' : 'secondary'">
                        {{ gen.status }}
                    </Badge>
                </div>

                <div class="mt-4 space-y-1 text-sm text-muted-foreground">
                    <div class="flex justify-between">
                        <span>Current Meter:</span>
                        <span class="font-medium text-foreground">{{ gen.current_meter_hours }} hrs</span>
                    </div>
                    <div class="flex justify-between">
                        <span>Fuel Capacity:</span>
                        <span class="font-medium text-foreground">{{ gen.fuel_capacity_liters }} L</span>
                    </div>
                    <div class="flex justify-between">
                        <span>Rated Consumption:</span>
                        <span class="font-medium text-foreground">{{ gen.liters_per_hour_rated }} L/hr</span>
                    </div>
                </div>

                <div class="mt-5 flex justify-end">
                    <Button
                        v-if="gen.status === 'idle'"
                        size="sm"
                        @click="openStartModal(gen)"
                    >
                        Start Run
                    </Button>
                </div>
            </div>
        </div>

        <!-- Start Run Dialog -->
        <div v-if="selectedGen" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div class="w-full max-w-md rounded-lg border border-border bg-background p-6 shadow-xl">
                <h3 class="text-lg font-semibold">Start Generator: {{ selectedGen.name }}</h3>
                <form @submit.prevent="submitStart" class="mt-4 space-y-4">
                    <div>
                        <label class="text-sm font-medium">Reason for Run</label>
                        <input
                            v-model="startForm.reason"
                            type="text"
                            required
                            class="mt-1 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                            placeholder="e.g. Power Outage, Regular Testing"
                        />
                    </div>
                    <div class="flex justify-end gap-2">
                        <Button type="button" variant="outline" @click="selectedGen = null">Cancel</Button>
                        <Button type="submit" :disabled="startForm.processing">Start Generator</Button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Stop Run Dialog -->
        <div v-if="selectedRun" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div class="w-full max-w-md rounded-lg border border-border bg-background p-6 shadow-xl">
                <h3 class="text-lg font-semibold">Stop Generator Run</h3>
                <form @submit.prevent="submitStop" class="mt-4 space-y-4">
                    <div>
                        <label class="text-sm font-medium">End Meter Hours (Start was {{ selectedRun.start_meter_hours }})</label>
                        <input
                            v-model.number="stopForm.end_meter_hours"
                            type="number"
                            step="0.1"
                            :min="selectedRun.start_meter_hours"
                            required
                            class="mt-1 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                        />
                    </div>
                    <div>
                        <label class="text-sm font-medium">Fuel Consumed (Liters, optional)</label>
                        <input
                            v-model.number="stopForm.fuel_consumed_liters"
                            type="number"
                            step="0.1"
                            min="0"
                            class="mt-1 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                            placeholder="Optional"
                        />
                    </div>
                    <div class="flex justify-end gap-2">
                        <Button type="button" variant="outline" @click="selectedRun = null">Cancel</Button>
                        <Button type="submit" variant="destructive" :disabled="stopForm.processing">Stop Run</Button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
