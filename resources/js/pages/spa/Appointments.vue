<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ref } from 'vue';

interface SpaServiceOption {
    id: number;
    name: string;
    category: string;
    duration_minutes: number;
    price_minor: number;
}

interface SpaRoomOption {
    id: number;
    name: string;
    room_type: string;
}

interface SpaTherapistOption {
    id: number;
    name: string;
    specialties: string[] | null;
}

interface SpaAppointment {
    id: number;
    guest_name: string;
    scheduled_at: string;
    duration_minutes: number;
    price_minor: number;
    status: string;
    notes: string | null;
    service: SpaServiceOption;
    treatment_room?: SpaRoomOption | null;
    therapist?: SpaTherapistOption | null;
}

const props = defineProps<{
    branch: { id: number; name: string; code: string };
    appointments: {
        data: SpaAppointment[];
        current_page: number;
        last_page: number;
        total: number;
    };
    services: SpaServiceOption[];
    rooms: SpaRoomOption[];
    therapists: SpaTherapistOption[];
    filters: { date?: string; status?: string };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Spa & Wellness', href: '#' },
            { title: 'Appointments', href: '#' },
        ],
    },
});

const showBookModal = ref(false);
const bookForm = useForm({
    guest_name: '',
    spa_service_id: props.services[0]?.id || 0,
    spa_treatment_room_id: null as number | null,
    spa_therapist_id: null as number | null,
    scheduled_at: '',
    notes: '',
});

const submitBooking = () => {
    bookForm.post(`/branches/${props.branch.id}/spa/book`, {
        onSuccess: () => {
            showBookModal.value = false;
            bookForm.reset();
        },
    });
};

const completeAppointment = (id: number) => {
    router.post(`/branches/${props.branch.id}/spa/appointments/${id}/complete`);
};

const cancelAppointment = (id: number) => {
    if (confirm('Are you sure you want to cancel this appointment?')) {
        router.post(`/branches/${props.branch.id}/spa/appointments/${id}/cancel`);
    }
};

const statusColors: Record<string, 'default' | 'secondary' | 'outline' | 'destructive'> = {
    scheduled: 'outline',
    in_progress: 'secondary',
    completed: 'default',
    cancelled: 'destructive',
    no_show: 'destructive',
};
</script>

<template>
    <div class="p-6">
        <Head title="Spa Appointments" />

        <div class="mb-6 flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-foreground">Spa & Wellness Appointments</h1>
                <p class="text-sm text-muted-foreground">{{ branch.name }} ({{ branch.code }})</p>
            </div>
            <div class="flex gap-2">
                <a :href="`/branches/${branch.id}/spa/schedule`">
                    <Button variant="outline">Therapist Schedule</Button>
                </a>
                <Button @click="showBookModal = true">Book Appointment</Button>
            </div>
        </div>

        <div class="rounded-lg border border-border bg-card">
            <table class="w-full caption-bottom text-sm">
                <thead class="bg-muted/50">
                    <tr class="border-b border-border text-left">
                        <th class="h-12 px-4 font-medium text-muted-foreground">Time</th>
                        <th class="h-12 px-4 font-medium text-muted-foreground">Guest</th>
                        <th class="h-12 px-4 font-medium text-muted-foreground">Service</th>
                        <th class="h-12 px-4 font-medium text-muted-foreground">Duration</th>
                        <th class="h-12 px-4 font-medium text-muted-foreground">Therapist</th>
                        <th class="h-12 px-4 font-medium text-muted-foreground">Room</th>
                        <th class="h-12 px-4 font-medium text-muted-foreground">Price</th>
                        <th class="h-12 px-4 font-medium text-muted-foreground">Status</th>
                        <th class="h-12 px-4 font-medium text-muted-foreground">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="app in appointments.data" :key="app.id" class="border-b border-border/50 hover:bg-muted/20">
                        <td class="p-4 font-medium">{{ new Date(app.scheduled_at).toLocaleString() }}</td>
                        <td class="p-4 font-semibold">{{ app.guest_name }}</td>
                        <td class="p-4">{{ app.service.name }}</td>
                        <td class="p-4">{{ app.duration_minutes }} min</td>
                        <td class="p-4">{{ app.therapist?.name || 'Unassigned' }}</td>
                        <td class="p-4">{{ app.treatment_room?.name || '—' }}</td>
                        <td class="p-4 font-medium">₦{{ (app.price_minor / 100).toLocaleString() }}</td>
                        <td class="p-4">
                            <Badge :variant="statusColors[app.status] || 'outline'" class="capitalize">
                                {{ app.status.replace('_', ' ') }}
                            </Badge>
                        </td>
                        <td class="p-4">
                            <div class="flex gap-2">
                                <Button
                                    v-if="app.status === 'scheduled'"
                                    size="sm"
                                    variant="outline"
                                    @click="completeAppointment(app.id)"
                                >
                                    Complete
                                </Button>
                                <Button
                                    v-if="app.status === 'scheduled'"
                                    size="sm"
                                    variant="ghost"
                                    class="text-destructive"
                                    @click="cancelAppointment(app.id)"
                                >
                                    Cancel
                                </Button>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="appointments.data.length === 0">
                        <td colspan="9" class="p-8 text-center text-muted-foreground">No spa appointments booked.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Book Modal -->
        <div v-if="showBookModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div class="w-full max-w-lg rounded-lg border border-border bg-background p-6 shadow-xl">
                <h3 class="text-lg font-semibold">Book Spa Treatment</h3>
                <form @submit.prevent="submitBooking" class="mt-4 space-y-4">
                    <div>
                        <label class="text-sm font-medium">Guest Name</label>
                        <input v-model="bookForm.guest_name" type="text" required class="mt-1 w-full rounded-md border border-input bg-background px-3 py-2 text-sm" />
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="text-sm font-medium">Treatment / Service</label>
                            <select v-model="bookForm.spa_service_id" required class="mt-1 w-full rounded-md border border-input bg-background px-3 py-2 text-sm">
                                <option v-for="s in services" :key="s.id" :value="s.id">
                                    {{ s.name }} ({{ s.duration_minutes }}m - ₦{{ (s.price_minor / 100).toLocaleString() }})
                                </option>
                            </select>
                        </div>
                        <div>
                            <label class="text-sm font-medium">Date & Time</label>
                            <input v-model="bookForm.scheduled_at" type="datetime-local" required class="mt-1 w-full rounded-md border border-input bg-background px-3 py-2 text-sm" />
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="text-sm font-medium">Assigned Therapist</label>
                            <select v-model="bookForm.spa_therapist_id" class="mt-1 w-full rounded-md border border-input bg-background px-3 py-2 text-sm">
                                <option :value="null">Any Available Therapist</option>
                                <option v-for="t in therapists" :key="t.id" :value="t.id">{{ t.name }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-sm font-medium">Treatment Room</label>
                            <select v-model="bookForm.spa_treatment_room_id" class="mt-1 w-full rounded-md border border-input bg-background px-3 py-2 text-sm">
                                <option :value="null">Any Available Room</option>
                                <option v-for="r in rooms" :key="r.id" :value="r.id">{{ r.name }} ({{ r.room_type }})</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="text-sm font-medium">Notes / Preferences</label>
                        <textarea v-model="bookForm.notes" rows="2" class="mt-1 w-full rounded-md border border-input bg-background px-3 py-2 text-sm" placeholder="e.g. Medium pressure, allergies..."></textarea>
                    </div>

                    <div class="flex justify-end gap-2 pt-2">
                        <Button type="button" variant="outline" @click="showBookModal = false">Cancel</Button>
                        <Button type="submit" :disabled="bookForm.processing">Confirm Booking</Button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
