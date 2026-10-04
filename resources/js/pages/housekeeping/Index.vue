<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, router, Link } from '@inertiajs/vue3';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogFooter,
} from '@/components/ui/dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import Pagination from '@/components/ui/pagination/Pagination.vue';

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
    tasks: {
        data: Task[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
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

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Housekeeping', href: '/housekeeping' },
        ],
    },
});

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
    assigned_to: props.filters.assigned_to
        ? String(props.filters.assigned_to)
        : '',
});

const statusOptions = [
    { label: 'Pending', value: 'pending' },
    { label: 'In Progress', value: 'in_progress' },
    { label: 'Completed', value: 'completed' },
];

const typeOptions = [
    { label: 'Cleaning', value: 'cleaning' },
    { label: 'Deep Clean', value: 'deep_clean' },
    { label: 'Turnover', value: 'turnover' },
    { label: 'Inspection', value: 'inspection' },
    { label: 'Laundry', value: 'laundry' },
    { label: 'Maintenance Request', value: 'maintenance_request' },
];

const createTypeOptions = [
    { label: 'Cleaning', value: 'cleaning' },
    { label: 'Deep Clean', value: 'deep_clean' },
    { label: 'Turnover', value: 'turnover' },
    { label: 'Inspection', value: 'inspection' },
    { label: 'Laundry', value: 'laundry' },
];

const priorityOptions = [
    { label: 'Low', value: 'low' },
    { label: 'Normal', value: 'normal' },
    { label: 'High', value: 'high' },
    { label: 'Urgent', value: 'urgent' },
];

const housekeeperOptions = computed(() =>
    props.housekeepers.map((hk) => ({ label: hk.name, value: String(hk.id) })),
);

const getPriorityBadgeClass = (priority: string) => {
    const classes: Record<string, string> = {
        low: 'bg-muted text-muted-foreground',
        normal: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300',
        high: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300',
        urgent: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300',
    };
    return classes[priority] || 'bg-muted text-muted-foreground';
};

const getStatusBadgeClass = (status: string) => {
    const classes: Record<string, string> = {
        pending:
            'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300',
        in_progress:
            'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300',
        completed:
            'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300',
    };
    return classes[status] || 'bg-muted text-muted-foreground';
};

const getTypeLabel = (type: string) => {
    return type.replace('_', ' ').replace(/\b\w/g, (l) => l.toUpperCase());
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
    if (!selectedTask.value) return;
    router.put(
        `/housekeeping/${selectedTask.value.id}`,
        {
            assigned_to: assignForm.value.assigned_to || null,
        },
        {
            onSuccess: () => {
                showAssignModal.value = false;
                selectedTask.value = null;
            },
        },
    );
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

const goToPage = (page: number) => {
    router.get(
        '/housekeeping',
        { ...filterForm.value, page },
        {
            preserveState: true,
            replace: true,
        },
    );
};
</script>

<template>
    <Head title="Housekeeping" />
    <div class="p-4 md:p-6">
        <div
            class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
        >
            <h1 class="text-foreground text-2xl font-bold">Housekeeping</h1>
            <Button variant="secondary" @click="showCreateModal = true">
                Add Task
            </Button>
        </div>

        <!-- Stats -->
        <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div class="bg-card rounded-lg p-4 shadow">
                <div class="text-muted-foreground text-sm">Pending</div>
                <div
                    class="text-2xl font-bold text-yellow-600 dark:text-yellow-400"
                >
                    {{ stats.pending }}
                </div>
            </div>
            <div class="bg-card rounded-lg p-4 shadow">
                <div class="text-muted-foreground text-sm">In Progress</div>
                <div
                    class="text-2xl font-bold text-blue-600 dark:text-blue-400"
                >
                    {{ stats.in_progress }}
                </div>
            </div>
            <div class="bg-card rounded-lg p-4 shadow">
                <div class="text-muted-foreground text-sm">Completed Today</div>
                <div
                    class="text-2xl font-bold text-green-600 dark:text-green-400"
                >
                    {{ stats.completed_today }}
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="mb-4 flex flex-col gap-3 sm:flex-row">
            <Select
                v-model="filterForm.status"
                @update:model-value="applyFilters"
                aria-label="Filter by status"
            >
                <SelectTrigger class="w-full sm:w-[180px]">
                    <SelectValue placeholder="All Statuses" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="option in statusOptions"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </SelectItem>
                </SelectContent>
            </Select>
            <Select
                v-model="filterForm.type"
                @update:model-value="applyFilters"
                aria-label="Filter by task type"
            >
                <SelectTrigger class="w-full sm:w-[180px]">
                    <SelectValue placeholder="All Types" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="option in typeOptions"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </SelectItem>
                </SelectContent>
            </Select>
            <Select
                v-model="filterForm.assigned_to"
                @update:model-value="applyFilters"
                aria-label="Filter by housekeeper"
            >
                <SelectTrigger class="w-full sm:w-[180px]">
                    <SelectValue placeholder="All Housekeepers" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="option in housekeeperOptions"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </SelectItem>
                </SelectContent>
            </Select>
        </div>

        <!-- Mobile: Card View -->
        <div class="space-y-3 md:hidden">
            <div
                v-for="task in tasks.data"
                :key="task.id"
                class="bg-card rounded-lg border p-4"
            >
                <div class="mb-2 flex items-start justify-between gap-2">
                    <div>
                        <span class="text-foreground text-sm font-medium">{{
                            task.room?.number || 'N/A'
                        }}</span>
                        <span class="text-muted-foreground ml-1 text-xs"
                            >Floor {{ task.room?.floor }}</span
                        >
                    </div>
                    <Badge
                        :class="getPriorityBadgeClass(task.priority)"
                        variant="outline"
                    >
                        {{ task.priority }}
                    </Badge>
                </div>
                <div class="text-muted-foreground mb-2 text-sm">
                    {{ getTypeLabel(task.type) }}
                </div>
                <div class="mb-1 flex items-center gap-2">
                    <span class="text-muted-foreground text-xs">Status:</span>
                    <Badge
                        :class="getStatusBadgeClass(task.status)"
                        variant="outline"
                    >
                        {{ task.status.replace('_', ' ') }}
                    </Badge>
                </div>
                <div class="text-muted-foreground mb-3 text-sm">
                    {{ task.assignee?.name || 'Unassigned' }}
                    <span v-if="task.estimated_minutes" class="ml-1"
                        >• {{ task.estimated_minutes }} min</span
                    >
                </div>
                <div class="flex gap-1">
                    <Button
                        v-if="task.status === 'pending'"
                        size="sm"
                        @click="startTask(task)"
                        >Start</Button
                    >
                    <Button
                        v-if="task.status === 'in_progress'"
                        size="sm"
                        variant="default"
                        @click="completeTask(task)"
                        >Complete</Button
                    >
                    <Button
                        size="sm"
                        variant="outline"
                        @click="openAssign(task)"
                        >Assign</Button
                    >
                    <Button
                        size="sm"
                        variant="destructive"
                        aria-label="Delete task"
                        @click="deleteTask(task)"
                        >×</Button
                    >
                </div>
            </div>
            <div
                v-if="tasks.data.length === 0"
                class="bg-card text-muted-foreground rounded-lg border p-8 text-center"
            >
                No tasks found.
            </div>
        </div>

        <!-- Desktop: Table View -->
        <div class="hidden overflow-x-auto rounded-md border md:block">
            <table class="w-full caption-bottom text-sm">
                <thead class="bg-muted/50 border-b [&_tr]:border-b">
                    <tr>
                        <th
                            class="text-muted-foreground h-10 px-2 text-left align-middle font-medium"
                        >
                            Room
                        </th>
                        <th
                            class="text-muted-foreground h-10 px-2 text-left align-middle font-medium"
                        >
                            Type
                        </th>
                        <th
                            class="text-muted-foreground h-10 px-2 text-left align-middle font-medium"
                        >
                            Priority
                        </th>
                        <th
                            class="text-muted-foreground h-10 px-2 text-left align-middle font-medium"
                        >
                            Status
                        </th>
                        <th
                            class="text-muted-foreground h-10 px-2 text-left align-middle font-medium"
                        >
                            Assigned To
                        </th>
                        <th
                            class="text-muted-foreground h-10 px-2 text-left align-middle font-medium"
                        >
                            Est. Time
                        </th>
                        <th
                            class="text-muted-foreground h-10 px-2 text-left align-middle font-medium"
                        >
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="[&_tr:last-child]:border-0">
                    <tr
                        v-for="task in tasks.data"
                        :key="task.id"
                        class="hover:bg-muted/50 border-b transition-colors"
                    >
                        <td class="p-2 align-middle">
                            <span class="text-foreground font-medium">{{
                                task.room?.number || 'N/A'
                            }}</span>
                            <div class="text-muted-foreground text-xs">
                                Floor {{ task.room?.floor }}
                            </div>
                        </td>
                        <td class="p-2 align-middle">
                            {{ getTypeLabel(task.type) }}
                        </td>
                        <td class="p-2 align-middle">
                            <Badge
                                :class="getPriorityBadgeClass(task.priority)"
                                variant="outline"
                            >
                                {{ task.priority }}
                            </Badge>
                        </td>
                        <td class="p-2 align-middle">
                            <Badge
                                :class="getStatusBadgeClass(task.status)"
                                variant="outline"
                            >
                                {{ task.status.replace('_', ' ') }}
                            </Badge>
                        </td>
                        <td class="p-2 align-middle">
                            {{ task.assignee?.name || 'Unassigned' }}
                        </td>
                        <td class="p-2 align-middle">
                            {{
                                task.estimated_minutes
                                    ? `${task.estimated_minutes} min`
                                    : '-'
                            }}
                        </td>
                        <td class="p-2 align-middle">
                            <div class="flex justify-end gap-1">
                                <Button
                                    v-if="task.status === 'pending'"
                                    size="sm"
                                    @click="startTask(task)"
                                >
                                    Start
                                </Button>
                                <Button
                                    v-if="task.status === 'in_progress'"
                                    size="sm"
                                    variant="default"
                                    @click="completeTask(task)"
                                >
                                    Complete
                                </Button>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    @click="openAssign(task)"
                                >
                                    Assign
                                </Button>
                                <Button
                                    size="sm"
                                    variant="destructive"
                                    aria-label="Delete task"
                                    @click="deleteTask(task)"
                                >
                                    ×
                                </Button>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="tasks.data.length === 0">
                        <td
                            colspan="7"
                            class="text-muted-foreground p-2 text-center align-middle"
                        >
                            No tasks found.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            <Pagination :data="tasks" label="tasks" @page-change="goToPage" />
        </div>

        <!-- Create Modal -->
        <Dialog v-model:open="showCreateModal">
            <DialogContent class="sm:max-w-[600px]">
                <DialogHeader>
                    <DialogTitle>Create Housekeeping Task</DialogTitle>
                </DialogHeader>
                <form @submit.prevent="submitCreate">
                    <div class="space-y-4 py-4">
                        <div class="grid gap-2">
                            <Label for="room_id">Room ID</Label>
                            <Input
                                id="room_id"
                                v-model="form.room_id"
                                type="number"
                                required
                            />
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div class="grid gap-2">
                                <Label>Type</Label>
                                <Select
                                    v-model="form.type"
                                    aria-label="Task type"
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="option in createTypeOptions"
                                            :key="option.value"
                                            :value="option.value"
                                        >
                                            {{ option.label }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div class="grid gap-2">
                                <Label>Priority</Label>
                                <Select
                                    v-model="form.priority"
                                    aria-label="Priority"
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="option in priorityOptions"
                                            :key="option.value"
                                            :value="option.value"
                                        >
                                            {{ option.label }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                        <div class="grid gap-2">
                            <Label for="description">Description</Label>
                            <textarea
                                id="description"
                                v-model="form.description"
                                :rows="2"
                                class="border-input bg-background ring-offset-background placeholder:text-muted-foreground focus-visible:ring-ring flex w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                            />
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div class="grid gap-2">
                                <Label>Assign To</Label>
                                <Select
                                    v-model="form.assigned_to"
                                    aria-label="Assign to housekeeper"
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Unassigned" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="option in housekeeperOptions"
                                            :key="option.value"
                                            :value="option.value"
                                        >
                                            {{ option.label }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div class="grid gap-2">
                                <Label for="estimated_minutes"
                                    >Est. Minutes</Label
                                >
                                <Input
                                    id="estimated_minutes"
                                    v-model="form.estimated_minutes"
                                    type="number"
                                />
                            </div>
                        </div>
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="showCreateModal = false"
                        >
                            Cancel
                        </Button>
                        <Button type="submit" variant="secondary">
                            Create Task
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <!-- Assign Modal -->
        <Dialog v-model:open="showAssignModal">
            <DialogContent class="sm:max-w-[400px]">
                <DialogHeader>
                    <DialogTitle>Assign Task</DialogTitle>
                </DialogHeader>
                <form @submit.prevent="submitAssign">
                    <div class="py-4">
                        <div class="grid gap-2">
                            <Label>Housekeeper</Label>
                            <Select
                                v-model="assignForm.assigned_to"
                                aria-label="Housekeeper"
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Unassigned" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="option in housekeeperOptions"
                                        :key="option.value"
                                        :value="option.value"
                                    >
                                        {{ option.label }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="showAssignModal = false"
                        >
                            Cancel
                        </Button>
                        <Button type="submit" variant="secondary">
                            Assign
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
