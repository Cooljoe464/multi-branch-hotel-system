<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ref } from 'vue';

interface UtilityMeter {
    id: number;
    name: string;
    meter_number: string;
    type: string;
    tariff_rate_minor: number;
    current_reading: number;
    updated_at: string;
}

const props = defineProps<{
    branch: { id: number; name: string; code: string };
    meters: UtilityMeter[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Facilities', href: '#' },
            { title: 'Utility Meters', href: '#' },
        ],
    },
});

const selectedMeter = ref<UtilityMeter | null>(null);
const form = useForm({
    current_reading: 0,
});

const openUpdateModal = (meter: UtilityMeter) => {
    selectedMeter.value = meter;
    form.current_reading = meter.current_reading;
};

const submitUpdate = () => {
    if (!selectedMeter.value) return;
    form.post(`/branches/${props.branch.id}/facilities/meters/${selectedMeter.value.id}`, {
        onSuccess: () => {
            selectedMeter.value = null;
        },
    });
};
</script>

<template>
    <div class="p-6">
        <Head title="Utility Meters" />

        <div class="mb-6 flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-foreground">Utility & Energy Meters</h1>
                <p class="text-sm text-muted-foreground">{{ branch.name }} ({{ branch.code }})</p>
            </div>
            <a :href="`/branches/${branch.id}/facilities/generators`">
                <Button variant="outline">Back to Generators</Button>
            </a>
        </div>

        <div class="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-3">
            <div
                v-for="meter in meters"
                :key="meter.id"
                class="rounded-lg border border-border bg-card p-5 shadow-sm"
            >
                <div class="flex items-start justify-between">
                    <div>
                        <h3 class="text-lg font-semibold text-foreground">{{ meter.name }}</h3>
                        <p class="text-xs text-muted-foreground">Meter #{{ meter.meter_number }}</p>
                    </div>
                    <Badge variant="outline" class="capitalize">
                        {{ meter.type }}
                    </Badge>
                </div>

                <div class="mt-4 space-y-2 text-sm text-muted-foreground">
                    <div class="flex justify-between">
                        <span>Current Reading:</span>
                        <span class="text-base font-bold text-foreground">{{ meter.current_reading.toLocaleString() }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span>Tariff Rate:</span>
                        <span class="font-medium text-foreground">₦{{ (meter.tariff_rate_minor / 100).toFixed(2) }} / unit</span>
                    </div>
                    <div class="flex justify-between text-xs">
                        <span>Last Updated:</span>
                        <span>{{ new Date(meter.updated_at).toLocaleDateString() }}</span>
                    </div>
                </div>

                <div class="mt-5 flex justify-end">
                    <Button size="sm" @click="openUpdateModal(meter)">
                        Update Reading
                    </Button>
                </div>
            </div>
            <div v-if="meters.length === 0" class="col-span-full rounded-lg border border-dashed border-border p-8 text-center text-muted-foreground">
                No utility meters configured for this branch.
            </div>
        </div>

        <!-- Update Reading Modal -->
        <div v-if="selectedMeter" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div class="w-full max-w-md rounded-lg border border-border bg-background p-6 shadow-xl">
                <h3 class="text-lg font-semibold">Update Meter: {{ selectedMeter.name }}</h3>
                <form @submit.prevent="submitUpdate" class="mt-4 space-y-4">
                    <div>
                        <label class="text-sm font-medium">New Meter Reading (must be &ge; {{ selectedMeter.current_reading }})</label>
                        <input
                            v-model.number="form.current_reading"
                            type="number"
                            step="0.01"
                            :min="selectedMeter.current_reading"
                            required
                            class="mt-1 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                        />
                    </div>
                    <div class="flex justify-end gap-2">
                        <Button type="button" variant="outline" @click="selectedMeter = null">Cancel</Button>
                        <Button type="submit" :disabled="form.processing">Save Reading</Button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
