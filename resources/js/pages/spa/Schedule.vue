<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ref } from 'vue';

interface SpaAppointment {
    id: number;
    guest_name: string;
    scheduled_at: string;
    duration_minutes: number;
    status: string;
    service: { name: string };
    treatment_room?: { name: string } | null;
}

interface SpaTherapistWithAppointments {
    id: number;
    name: string;
    specialties: string[] | null;
    appointments: SpaAppointment[];
}

const props = defineProps<{
    branch: { id: number; name: string; code: string };
    date: string;
    therapists: SpaTherapistWithAppointments[];
    rooms: any[];
    services: any[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Spa & Wellness', href: '#' },
            { title: 'Schedule', href: '#' },
        ],
    },
});

const selectedDate = ref(props.date);

const changeDate = (newDate: string) => {
    selectedDate.value = newDate;
    router.get(
        `/branches/${props.branch.id}/spa/schedule`,
        { date: newDate },
        { preserveState: true },
    );
};
</script>

<template>
    <div class="p-6">
        <Head title="Spa Therapist Schedule" />

        <div class="mb-6 flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-foreground">Therapist Schedule & Allocation</h1>
                <p class="text-sm text-muted-foreground">{{ branch.name }} ({{ branch.code }})</p>
            </div>
            <div class="flex items-center gap-3">
                <input
                    type="date"
                    :value="selectedDate"
                    class="rounded-md border border-input bg-background px-3 py-2 text-sm"
                    @change="(e: any) => changeDate(e.target.value)"
                />
                <a :href="`/branches/${branch.id}/spa/appointments`">
                    <Button variant="outline">List View</Button>
                </a>
            </div>
        </div>

        <!-- Schedule Grid by Therapist -->
        <div class="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-3">
            <div
                v-for="therapist in therapists"
                :key="therapist.id"
                class="rounded-lg border border-border bg-card p-5 shadow-sm"
            >
                <div class="border-b border-border pb-3">
                    <h3 class="text-lg font-semibold text-foreground">{{ therapist.name }}</h3>
                    <p class="text-xs text-muted-foreground">
                        {{ therapist.specialties?.join(', ') || 'General Therapist' }}
                    </p>
                </div>

                <div class="mt-4 space-y-3">
                    <div
                        v-for="app in therapist.appointments"
                        :key="app.id"
                        class="rounded border border-border/80 bg-muted/40 p-3 text-sm"
                    >
                        <div class="flex items-center justify-between">
                            <span class="font-medium text-foreground">
                                {{ new Date(app.scheduled_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) }}
                                ({{ app.duration_minutes }}m)
                            </span>
                            <Badge variant="outline" class="text-xs capitalize">
                                {{ app.status }}
                            </Badge>
                        </div>
                        <div class="mt-1 font-semibold text-foreground">{{ app.guest_name }}</div>
                        <div class="text-xs text-muted-foreground">
                            {{ app.service.name }} &bull; {{ app.treatment_room?.name || 'Any Room' }}
                        </div>
                    </div>

                    <div
                        v-if="therapist.appointments.length === 0"
                        class="py-6 text-center text-sm text-muted-foreground"
                    >
                        No appointments scheduled for this date.
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
