<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, router, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';

interface User {
    id: number;
    name: string;
}

interface Room {
    id: number;
    number: string;
    floor: string;
}

interface MaintenanceTicket {
    id: number;
    ticket_number: string;
    category: string;
    priority: string;
    status: string;
    title: string;
    description: string;
    resolution_notes: string | null;
    is_room_locked: boolean;
    estimated_cost: number | null;
    actual_cost: number | null;
    room: Room | null;
    reporter: User;
    assignee: User | null;
    created_at: string;
    started_at: string | null;
    completed_at: string | null;
}

const props = defineProps<{
    tickets: MaintenanceTicket[];
    lockedRooms: MaintenanceTicket[];
    users: User[];
    stats: {
        open: number;
        in_progress: number;
        locked_rooms: number;
    };
    filters: {
        status?: string;
        category?: string;
        priority?: string;
    };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Maintenance', href: '/maintenance' },
];

const selectedTicket = ref<MaintenanceTicket | null>(null);
const showAssignModal = ref(false);
const showCompleteModal = ref(false);

const assignForm = ref({
    assigned_to: '',
});

const completeForm = ref({
    resolution_notes: '',
    actual_cost: '',
});

const filterForm = ref({
    status: props.filters.status || '',
    category: props.filters.category || '',
    priority: props.filters.priority || '',
});

const filteredTickets = computed(() => {
    let result = [...props.tickets];
    if (filterForm.value.status) {
        result = result.filter(t => t.status === filterForm.value.status);
    }
    if (filterForm.value.category) {
        result = result.filter(t => t.category === filterForm.value.category);
    }
    if (filterForm.value.priority) {
        result = result.filter(t => t.priority === filterForm.value.priority);
    }
    return result;
});

const getPriorityBadgeClass = (priority: string) => {
    const classes: Record<string, string> = {
        low: 'bg-gray-100 text-gray-800',
        normal: 'bg-blue-100 text-blue-800',
        high: 'bg-orange-100 text-orange-800',
        urgent: 'bg-red-100 text-red-800',
    };
    return classes[priority] || 'bg-gray-100 text-gray-800';
};

const getStatusBadgeClass = (status: string) => {
    const classes: Record<string, string> = {
        open: 'bg-yellow-100 text-yellow-800',
        in_progress: 'bg-blue-100 text-blue-800',
        completed: 'bg-green-100 text-green-800',
    };
    return classes[status] || 'bg-gray-100 text-gray-800';
};

const getCategoryLabel = (category: string) => {
    return category.charAt(0).toUpperCase() + category.slice(1);
};

const openAssign = (ticket: MaintenanceTicket) => {
    selectedTicket.value = ticket;
    assignForm.value.assigned_to = ticket.assignee?.id?.toString() || '';
    showAssignModal.value = true;
};

const submitAssign = () => {
    if (! selectedTicket.value) return;
    router.put(`/maintenance/${selectedTicket.value.id}`, {
        assigned_to: assignForm.value.assigned_to || null,
    }, {
        onSuccess: () => {
            showAssignModal.value = false;
            selectedTicket.value = null;
        },
    });
};

const openComplete = (ticket: MaintenanceTicket) => {
    selectedTicket.value = ticket;
    completeForm.value = {
        resolution_notes: '',
        actual_cost: '',
    };
    showCompleteModal.value = true;
};

const submitComplete = () => {
    if (! selectedTicket.value) return;
    router.post(`/maintenance/${selectedTicket.value.id}/complete`, {
        resolution_notes: completeForm.value.resolution_notes || null,
        actual_cost: completeForm.value.actual_cost ? Number(completeForm.value.actual_cost) : null,
    }, {
        onSuccess: () => {
            showCompleteModal.value = false;
            selectedTicket.value = null;
        },
    });
};

const startTicket = (ticket: MaintenanceTicket) => {
    router.post(`/maintenance/${ticket.id}/start`);
};

const lockRoom = (ticket: MaintenanceTicket) => {
    router.post(`/maintenance/${ticket.id}/lock-room`);
};

const unlockRoom = (ticket: MaintenanceTicket) => {
    router.post(`/maintenance/${ticket.id}/unlock-room`);
};

const deleteTicket = (ticket: MaintenanceTicket) => {
    if (confirm(`Delete ticket ${ticket.ticket_number}?`)) {
        router.delete(`/maintenance/${ticket.id}`);
    }
};

const applyFilters = () => {
    router.get('/maintenance', filterForm.value, {
        preserveState: true,
        replace: true,
    });
};
</script>

<template>
    <AppLayout title="Maintenance" :breadcrumbs="breadcrumbs">
        <div class="p-6">
            <div class="mb-6 flex items-center justify-between">
                <h1 class="text-2xl font-bold text-gray-900">Maintenance</h1>
                <Link
                    href="/maintenance/create"
                    class="inline-flex items-center px-4 py-2 bg-gray-900 text-white rounded-md hover:bg-gray-800"
                >
                    New Ticket
                </Link>
            </div>

            <!-- Stats -->
            <div class="mb-6 grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="bg-white rounded-lg shadow p-4">
                    <div class="text-sm text-gray-500">Open Tickets</div>
                    <div class="text-2xl font-bold text-yellow-600">{{ stats.open }}</div>
                </div>
                <div class="bg-white rounded-lg shadow p-4">
                    <div class="text-sm text-gray-500">In Progress</div>
                    <div class="text-2xl font-bold text-blue-600">{{ stats.in_progress }}</div>
                </div>
                <div class="bg-white rounded-lg shadow p-4">
                    <div class="text-sm text-gray-500">Locked Rooms</div>
                    <div class="text-2xl font-bold text-red-600">{{ stats.locked_rooms }}</div>
                </div>
            </div>

            <!-- Locked Rooms -->
            <div v-if="lockedRooms.length > 0" class="mb-6 bg-red-50 border border-red-200 rounded-lg p-4">
                <h3 class="text-sm font-medium text-red-800 mb-2">Locked Rooms</h3>
                <div class="flex flex-wrap gap-2">
                    <span
                        v-for="ticket in lockedRooms"
                        :key="ticket.id"
                        class="inline-flex items-center gap-1 px-2 py-1 bg-red-100 text-red-700 rounded text-sm"
                    >
                        Room {{ ticket.room?.number }}
                        <button
                            class="text-red-500 hover:text-red-700"
                            @click="unlockRoom(ticket)"
                        >
                            ×
                        </button>
                    </span>
                </div>
            </div>

            <!-- Filters -->
            <div class="mb-4 flex gap-4">
                <select
                    v-model="filterForm.status"
                    class="rounded-md border-gray-300 text-sm"
                    @change="applyFilters"
                >
                    <option value="">All Statuses</option>
                    <option value="open">Open</option>
                    <option value="in_progress">In Progress</option>
                    <option value="completed">Completed</option>
                </select>
                <select
                    v-model="filterForm.category"
                    class="rounded-md border-gray-300 text-sm"
                    @change="applyFilters"
                >
                    <option value="">All Categories</option>
                    <option value="plumbing">Plumbing</option>
                    <option value="electrical">Electrical</option>
                    <option value="hvac">HVAC</option>
                    <option value="furniture">Furniture</option>
                    <option value="appliance">Appliance</option>
                    <option value="structural">Structural</option>
                    <option value="other">Other</option>
                </select>
                <select
                    v-model="filterForm.priority"
                    class="rounded-md border-gray-300 text-sm"
                    @change="applyFilters"
                >
                    <option value="">All Priorities</option>
                    <option value="low">Low</option>
                    <option value="normal">Normal</option>
                    <option value="high">High</option>
                    <option value="urgent">Urgent</option>
                </select>
            </div>

            <!-- Tickets Table -->
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Ticket</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Room</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Category</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Priority</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Assigned To</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <tr v-for="ticket in filteredTickets" :key="ticket.id" class="hover:bg-gray-50">
                            <td class="px-4 py-3">
                                <a :href="`/maintenance/${ticket.id}`" class="text-sm font-medium text-gray-900 hover:underline">
                                    {{ ticket.ticket_number }}
                                </a>
                                <div class="text-xs text-gray-500">{{ ticket.title }}</div>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600">
                                {{ ticket.room?.number || 'N/A' }}
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600">
                                {{ getCategoryLabel(ticket.category) }}
                            </td>
                            <td class="px-4 py-3">
                                <span
                                    class="px-2 py-1 text-xs font-medium rounded-full"
                                    :class="getPriorityBadgeClass(ticket.priority)"
                                >
                                    {{ ticket.priority }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <span
                                    class="px-2 py-1 text-xs font-medium rounded-full"
                                    :class="getStatusBadgeClass(ticket.status)"
                                >
                                    {{ ticket.status.replace('_', ' ') }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600">
                                {{ ticket.assignee?.name || 'Unassigned' }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex justify-end gap-1">
                                    <button
                                        v-if="ticket.status === 'open'"
                                        class="px-2 py-1 text-xs text-blue-700 bg-blue-50 rounded hover:bg-blue-100"
                                        @click="startTicket(ticket)"
                                    >
                                        Start
                                    </button>
                                    <button
                                        v-if="ticket.status === 'in_progress'"
                                        class="px-2 py-1 text-xs text-green-700 bg-green-50 rounded hover:bg-green-100"
                                        @click="openComplete(ticket)"
                                    >
                                        Complete
                                    </button>
                                    <button
                                        v-if="!ticket.is_room_locked && ticket.room"
                                        class="px-2 py-1 text-xs text-red-700 bg-red-50 rounded hover:bg-red-100"
                                        @click="lockRoom(ticket)"
                                    >
                                        Lock
                                    </button>
                                    <button
                                        v-if="ticket.is_room_locked"
                                        class="px-2 py-1 text-xs text-green-700 bg-green-50 rounded hover:bg-green-100"
                                        @click="unlockRoom(ticket)"
                                    >
                                        Unlock
                                    </button>
                                    <button
                                        class="px-2 py-1 text-xs text-gray-700 bg-gray-100 rounded hover:bg-gray-200"
                                        @click="openAssign(ticket)"
                                    >
                                        Assign
                                    </button>
                                    <button
                                        class="px-2 py-1 text-xs text-red-700 bg-red-50 rounded hover:bg-red-100"
                                        @click="deleteTicket(ticket)"
                                    >
                                        ×
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="filteredTickets.length === 0">
                            <td colspan="7" class="px-4 py-8 text-center text-gray-500">
                                No tickets found.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Assign Modal -->
            <div
                v-if="showAssignModal && selectedTicket"
                class="fixed inset-0 z-50 overflow-y-auto"
                @click.self="showAssignModal = false"
            >
                <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                    <div class="relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl sm:my-8 sm:w-full sm:max-w-sm sm:p-6">
                        <h3 class="text-lg font-semibold mb-4">Assign Ticket</h3>
                        <form @submit.prevent="submitAssign">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Assign To</label>
                                <select v-model="assignForm.assigned_to" class="mt-1 block w-full rounded-md border-gray-300">
                                    <option value="">Unassigned</option>
                                    <option v-for="user in users" :key="user.id" :value="user.id">
                                        {{ user.name }}
                                    </option>
                                </select>
                            </div>
                            <div class="mt-6 flex justify-end gap-2">
                                <button
                                    type="button"
                                    class="px-4 py-2 text-sm text-gray-700 bg-gray-100 rounded-md hover:bg-gray-200"
                                    @click="showAssignModal = false"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    class="px-4 py-2 text-sm text-white bg-gray-900 rounded-md hover:bg-gray-800"
                                >
                                    Assign
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Complete Modal -->
            <div
                v-if="showCompleteModal && selectedTicket"
                class="fixed inset-0 z-50 overflow-y-auto"
                @click.self="showCompleteModal = false"
            >
                <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                    <div class="relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl sm:my-8 sm:w-full sm:max-w-lg sm:p-6">
                        <h3 class="text-lg font-semibold mb-4">Complete Ticket {{ selectedTicket.ticket_number }}</h3>
                        <form @submit.prevent="submitComplete">
                            <div class="space-y-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Resolution Notes</label>
                                    <textarea
                                        v-model="completeForm.resolution_notes"
                                        rows="3"
                                        class="mt-1 block w-full rounded-md border-gray-300"
                                        placeholder="Describe the work performed..."
                                    ></textarea>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Actual Cost ($)</label>
                                    <input
                                        v-model="completeForm.actual_cost"
                                        type="number"
                                        min="0"
                                        class="mt-1 block w-full rounded-md border-gray-300"
                                    >
                                </div>
                            </div>
                            <div class="mt-6 flex justify-end gap-2">
                                <button
                                    type="button"
                                    class="px-4 py-2 text-sm text-gray-700 bg-gray-100 rounded-md hover:bg-gray-200"
                                    @click="showCompleteModal = false"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    class="px-4 py-2 text-sm text-white bg-green-600 rounded-md hover:bg-green-700"
                                >
                                    Complete Ticket
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
