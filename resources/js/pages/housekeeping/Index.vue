<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, router, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';

interface Task {
    id: number;
    type: string;
    priority: string;
    status: string;
    description: string;
    notes: string | null;
    estimated_minutes: number | null;
    actual_minutes: number | null;
    started_at: string | null;
    completed_at: string | null;
    room: {
        id: number;
        number: string;
        floor: string;
    } | null;
    assignee: {
        id: number;
        name: string;
    } | null;
    created_at: string;
}

interface Housekeeper {
    id: number;
    name: string;
}

const props = defineProps<{
    tasks: Task[];
    housekeepers: Housekeeper[];
    stats: {
        pending: number;
        in_progress: number;
        completed_today: number;
    };
    filters: {
        status?: string;
        type?: string;
        assigned_to?: number;
    };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Housekeeping', href: '/housekeeping' },
];

const showCreateModal = ref(false);
const selectedTask = ref<Task | null>(null);
const showAssignModal = ref(false);

const form = ref({
    room_id: '',
    type: 'cleaning',
    priority: 'normal',
    description: '',
    assigned_to: '',
    estimated_minutes: 30,
});

const assignForm = ref({
    assigned_to: '',
});

const filterForm = ref({
    status: props.filters.status || '',
    type: props.filters.type || '',
    assigned_to: props.filters.assigned_to || '',
});

const filteredTasks = computed(() => {
    let result = [...props.tasks];
    if (filterForm.value.status) {
        result = result.filter(t => t.status === filterForm.value.status);
    }
    if (filterForm.value.type) {
        result = result.filter(t => t.type === filterForm.value.type);
    }
    if (filterForm.value.assigned_to) {
        result = result.filter(t => t.assignee?.id === Number(filterForm.value.assigned_to));
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
        pending: 'bg-yellow-100 text-yellow-800',
        in_progress: 'bg-blue-100 text-blue-800',
        completed: 'bg-green-100 text-green-800',
    };
    return classes[status] || 'bg-gray-100 text-gray-800';
};

const getTypeLabel = (type: string) => {
    return type.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase());
};

const submitCreate = () => {
    router.post('/housekeeping', form.value, {
        onSuccess: () => {
            showCreateModal.value = false;
            form.value = {
                room_id: '',
                type: 'cleaning',
                priority: 'normal',
                description: '',
                assigned_to: '',
                estimated_minutes: 30,
            };
        },
    });
};

const openAssign = (task: Task) => {
    selectedTask.value = task;
    assignForm.value.assigned_to = task.assignee?.id?.toString() || '';
    showAssignModal.value = true;
};

const submitAssign = () => {
    if (! selectedTask.value) return;
    router.put(`/housekeeping/${selectedTask.value.id}`, {
        assigned_to: assignForm.value.assigned_to || null,
    }, {
        onSuccess: () => {
            showAssignModal.value = false;
            selectedTask.value = null;
        },
    });
};

const startTask = (task: Task) => {
    router.post(`/housekeeping/${task.id}/start`);
};

const completeTask = (task: Task) => {
    const notes = prompt('Completion notes (optional):');
    if (notes !== null) {
        router.post(`/housekeeping/${task.id}/complete`, { notes });
    }
};

const deleteTask = (task: Task) => {
    if (confirm('Delete this task?')) {
        router.delete(`/housekeeping/${task.id}`);
    }
};

const applyFilters = () => {
    router.get('/housekeeping', filterForm.value, {
        preserveState: true,
        replace: true,
    });
};
</script>

<template>
    <AppLayout title="Housekeeping" :breadcrumbs="breadcrumbs">
        <div class="p-6">
            <div class="mb-6 flex items-center justify-between">
                <h1 class="text-2xl font-bold text-gray-900">Housekeeping</h1>
                <button
                    class="inline-flex items-center px-4 py-2 bg-gray-900 text-white rounded-md hover:bg-gray-800"
                    @click="showCreateModal = true"
                >
                    Add Task
                </button>
            </div>

            <!-- Stats -->
            <div class="mb-6 grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="bg-white rounded-lg shadow p-4">
                    <div class="text-sm text-gray-500">Pending</div>
                    <div class="text-2xl font-bold text-yellow-600">{{ stats.pending }}</div>
                </div>
                <div class="bg-white rounded-lg shadow p-4">
                    <div class="text-sm text-gray-500">In Progress</div>
                    <div class="text-2xl font-bold text-blue-600">{{ stats.in_progress }}</div>
                </div>
                <div class="bg-white rounded-lg shadow p-4">
                    <div class="text-sm text-gray-500">Completed Today</div>
                    <div class="text-2xl font-bold text-green-600">{{ stats.completed_today }}</div>
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
                    <option value="pending">Pending</option>
                    <option value="in_progress">In Progress</option>
                    <option value="completed">Completed</option>
                </select>
                <select
                    v-model="filterForm.type"
                    class="rounded-md border-gray-300 text-sm"
                    @change="applyFilters"
                >
                    <option value="">All Types</option>
                    <option value="cleaning">Cleaning</option>
                    <option value="deep_clean">Deep Clean</option>
                    <option value="turnover">Turnover</option>
                    <option value="inspection">Inspection</option>
                    <option value="laundry">Laundry</option>
                    <option value="maintenance_request">Maintenance Request</option>
                </select>
                <select
                    v-model="filterForm.assigned_to"
                    class="rounded-md border-gray-300 text-sm"
                    @change="applyFilters"
                >
                    <option value="">All Housekeepers</option>
                    <option v-for="hk in housekeepers" :key="hk.id" :value="hk.id">
                        {{ hk.name }}
                    </option>
                </select>
            </div>

            <!-- Tasks Table -->
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Room</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Priority</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Assigned To</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Est. Time</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <tr v-for="task in filteredTasks" :key="task.id" class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-sm font-medium text-gray-900">
                                {{ task.room?.number || 'N/A' }}
                                <div class="text-xs text-gray-500">Floor {{ task.room?.floor }}</div>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600">
                                {{ getTypeLabel(task.type) }}
                            </td>
                            <td class="px-4 py-3">
                                <span
                                    class="px-2 py-1 text-xs font-medium rounded-full"
                                    :class="getPriorityBadgeClass(task.priority)"
                                >
                                    {{ task.priority }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <span
                                    class="px-2 py-1 text-xs font-medium rounded-full"
                                    :class="getStatusBadgeClass(task.status)"
                                >
                                    {{ task.status.replace('_', ' ') }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600">
                                {{ task.assignee?.name || 'Unassigned' }}
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600">
                                {{ task.estimated_minutes ? `${task.estimated_minutes} min` : '-' }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex justify-end gap-1">
                                    <button
                                        v-if="task.status === 'pending'"
                                        class="px-2 py-1 text-xs text-blue-700 bg-blue-50 rounded hover:bg-blue-100"
                                        @click="startTask(task)"
                                    >
                                        Start
                                    </button>
                                    <button
                                        v-if="task.status === 'in_progress'"
                                        class="px-2 py-1 text-xs text-green-700 bg-green-50 rounded hover:bg-green-100"
                                        @click="completeTask(task)"
                                    >
                                        Complete
                                    </button>
                                    <button
                                        class="px-2 py-1 text-xs text-gray-700 bg-gray-100 rounded hover:bg-gray-200"
                                        @click="openAssign(task)"
                                    >
                                        Assign
                                    </button>
                                    <button
                                        class="px-2 py-1 text-xs text-red-700 bg-red-50 rounded hover:bg-red-100"
                                        @click="deleteTask(task)"
                                    >
                                        ×
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="filteredTasks.length === 0">
                            <td colspan="7" class="px-4 py-8 text-center text-gray-500">
                                No tasks found.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Create Modal -->
            <div
                v-if="showCreateModal"
                class="fixed inset-0 z-50 overflow-y-auto"
                @click.self="showCreateModal = false"
            >
                <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                    <div class="relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl sm:my-8 sm:w-full sm:max-w-lg sm:p-6">
                        <h3 class="text-lg font-semibold mb-4">Create Housekeeping Task</h3>
                        <form @submit.prevent="submitCreate">
                            <div class="space-y-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Room ID</label>
                                    <input v-model="form.room_id" type="number" class="mt-1 block w-full rounded-md border-gray-300" required>
                                </div>
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Type</label>
                                        <select v-model="form.type" class="mt-1 block w-full rounded-md border-gray-300">
                                            <option value="cleaning">Cleaning</option>
                                            <option value="deep_clean">Deep Clean</option>
                                            <option value="turnover">Turnover</option>
                                            <option value="inspection">Inspection</option>
                                            <option value="laundry">Laundry</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Priority</label>
                                        <select v-model="form.priority" class="mt-1 block w-full rounded-md border-gray-300">
                                            <option value="low">Low</option>
                                            <option value="normal">Normal</option>
                                            <option value="high">High</option>
                                            <option value="urgent">Urgent</option>
                                        </select>
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Description</label>
                                    <textarea v-model="form.description" rows="2" class="mt-1 block w-full rounded-md border-gray-300"></textarea>
                                </div>
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Assign To</label>
                                        <select v-model="form.assigned_to" class="mt-1 block w-full rounded-md border-gray-300">
                                            <option value="">Unassigned</option>
                                            <option v-for="hk in housekeepers" :key="hk.id" :value="hk.id">
                                                {{ hk.name }}
                                            </option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Est. Minutes</label>
                                        <input v-model="form.estimated_minutes" type="number" min="5" max="480" class="mt-1 block w-full rounded-md border-gray-300">
                                    </div>
                                </div>
                            </div>
                            <div class="mt-6 flex justify-end gap-2">
                                <button
                                    type="button"
                                    class="px-4 py-2 text-sm text-gray-700 bg-gray-100 rounded-md hover:bg-gray-200"
                                    @click="showCreateModal = false"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    class="px-4 py-2 text-sm text-white bg-gray-900 rounded-md hover:bg-gray-800"
                                >
                                    Create Task
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Assign Modal -->
            <div
                v-if="showAssignModal && selectedTask"
                class="fixed inset-0 z-50 overflow-y-auto"
                @click.self="showAssignModal = false"
            >
                <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                    <div class="relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl sm:my-8 sm:w-full sm:max-w-sm sm:p-6">
                        <h3 class="text-lg font-semibold mb-4">Assign Task</h3>
                        <form @submit.prevent="submitAssign">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Housekeeper</label>
                                <select v-model="assignForm.assigned_to" class="mt-1 block w-full rounded-md border-gray-300">
                                    <option value="">Unassigned</option>
                                    <option v-for="hk in housekeepers" :key="hk.id" :value="hk.id">
                                        {{ hk.name }}
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
        </div>
    </AppLayout>
</template>
