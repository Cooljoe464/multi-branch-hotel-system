<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, router, Link, usePage } from '@inertiajs/vue3';
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
import { getCurrencySymbol } from '@/lib/format';

const page = usePage();
const branchSymbol = computed(
    () => (page.props.branch?.current as any)?.currency_symbol || '$',
);
const resolveSymbol = (code?: string) =>
    getCurrencySymbol(code || 'NGN') || branchSymbol.value;
const currencySymbol = computed(() => branchSymbol.value);

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
    tickets: {
        data: MaintenanceTicket[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
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

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Maintenance', href: '/maintenance' },
        ],
    },
});

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

const statusOptions = [
    { label: 'Open', value: 'open' },
    { label: 'In Progress', value: 'in_progress' },
    { label: 'Completed', value: 'completed' },
];

const categoryOptions = [
    { label: 'Plumbing', value: 'plumbing' },
    { label: 'Electrical', value: 'electrical' },
    { label: 'HVAC', value: 'hvac' },
    { label: 'Furniture', value: 'furniture' },
    { label: 'Appliance', value: 'appliance' },
    { label: 'Structural', value: 'structural' },
    { label: 'Other', value: 'other' },
];

const priorityOptions = [
    { label: 'Low', value: 'low' },
    { label: 'Normal', value: 'normal' },
    { label: 'High', value: 'high' },
    { label: 'Urgent', value: 'urgent' },
];

const userOptions = computed(() =>
    props.users.map((u) => ({ label: u.name, value: String(u.id) })),
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
        open: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300',
        in_progress:
            'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300',
        completed:
            'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300',
    };
    return classes[status] || 'bg-muted text-muted-foreground';
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
    if (!selectedTicket.value) return;
    router.put(
        `/maintenance/${selectedTicket.value.id}`,
        {
            assigned_to: assignForm.value.assigned_to || null,
        },
        {
            onSuccess: () => {
                showAssignModal.value = false;
                selectedTicket.value = null;
            },
        },
    );
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
    if (!selectedTicket.value) return;
    router.post(
        `/maintenance/${selectedTicket.value.id}/complete`,
        {
            resolution_notes: completeForm.value.resolution_notes || null,
            actual_cost: completeForm.value.actual_cost
                ? Number(completeForm.value.actual_cost)
                : null,
        },
        {
            onSuccess: () => {
                showCompleteModal.value = false;
                selectedTicket.value = null;
            },
        },
    );
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

const goToPage = (page: number) => {
    router.get(
        '/maintenance',
        { ...filterForm.value, page },
        {
            preserveState: true,
            replace: true,
        },
    );
};
</script>

<template>
    <Head title="Maintenance" />
    <div class="p-4 md:p-6">
        <div
            class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
        >
            <h1 class="text-foreground text-2xl font-bold">Maintenance</h1>
            <Button as-child>
                <Link href="/maintenance/create">New Ticket</Link>
            </Button>
        </div>

        <!-- Stats -->
        <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div class="bg-card rounded-lg p-4 shadow">
                <div class="text-muted-foreground text-sm">Open Tickets</div>
                <div
                    class="text-2xl font-bold text-yellow-600 dark:text-yellow-400"
                >
                    {{ stats.open }}
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
                <div class="text-muted-foreground text-sm">Locked Rooms</div>
                <div class="text-2xl font-bold text-red-600 dark:text-red-400">
                    {{ stats.locked_rooms }}
                </div>
            </div>
        </div>

        <!-- Locked Rooms -->
        <div
            v-if="lockedRooms.length > 0"
            class="mb-6 rounded-lg border border-red-200 bg-red-50 p-4 dark:border-red-800 dark:bg-red-900/20"
        >
            <h3 class="mb-2 text-sm font-medium text-red-800 dark:text-red-300">
                Locked Rooms
            </h3>
            <div class="flex flex-wrap gap-2">
                <Badge
                    v-for="ticket in lockedRooms"
                    :key="ticket.id"
                    class="bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300"
                    variant="outline"
                >
                    Room {{ ticket.room?.number }}
                    <button
                        class="text-muted-foreground hover:text-foreground ml-1"
                        aria-label="Unlock room"
                        @click="unlockRoom(ticket)"
                    >
                        &times;
                    </button>
                </Badge>
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
                v-model="filterForm.category"
                @update:model-value="applyFilters"
                aria-label="Filter by category"
            >
                <SelectTrigger class="w-full sm:w-[180px]">
                    <SelectValue placeholder="All Categories" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="option in categoryOptions"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </SelectItem>
                </SelectContent>
            </Select>
            <Select
                v-model="filterForm.priority"
                @update:model-value="applyFilters"
                aria-label="Filter by priority"
            >
                <SelectTrigger class="w-full sm:w-[180px]">
                    <SelectValue placeholder="All Priorities" />
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

        <!-- Mobile: Card View -->
        <div class="space-y-3 md:hidden">
            <div
                v-for="ticket in tickets.data"
                :key="ticket.id"
                class="bg-card rounded-lg border p-4"
            >
                <div class="mb-2 flex items-start justify-between gap-2">
                    <div>
                        <Link
                            :href="`/maintenance/${ticket.id}`"
                            class="text-foreground text-sm font-medium hover:underline"
                        >
                            {{ ticket.ticket_number }}
                        </Link>
                        <div class="text-muted-foreground text-xs">
                            {{ ticket.title }}
                        </div>
                    </div>
                    <Badge
                        :class="getPriorityBadgeClass(ticket.priority)"
                        variant="outline"
                    >
                        {{ ticket.priority }}
                    </Badge>
                </div>
                <div class="text-muted-foreground mb-1 text-sm">
                    {{ getCategoryLabel(ticket.category) }}
                    <span v-if="ticket.room">
                        • Room {{ ticket.room.number }}</span
                    >
                </div>
                <div class="mb-1 flex items-center gap-2">
                    <span class="text-muted-foreground text-xs">Status:</span>
                    <Badge
                        :class="getStatusBadgeClass(ticket.status)"
                        variant="outline"
                    >
                        {{ ticket.status.replace('_', ' ') }}
                    </Badge>
                </div>
                <div class="text-muted-foreground mb-3 text-sm">
                    {{ ticket.assignee?.name || 'Unassigned' }}
                </div>
                <div class="flex flex-wrap gap-1">
                    <Button
                        v-if="ticket.status === 'open'"
                        size="sm"
                        @click="startTicket(ticket)"
                        >Start</Button
                    >
                    <Button
                        v-if="ticket.status === 'in_progress'"
                        size="sm"
                        variant="default"
                        class="bg-green-600 text-white hover:bg-green-700 dark:bg-green-700 dark:hover:bg-green-800"
                        @click="openComplete(ticket)"
                        >Complete</Button
                    >
                    <Button
                        v-if="!ticket.is_room_locked && ticket.room"
                        size="sm"
                        variant="destructive"
                        @click="lockRoom(ticket)"
                        >Lock</Button
                    >
                    <Button
                        v-if="ticket.is_room_locked"
                        size="sm"
                        variant="default"
                        class="bg-green-600 text-white hover:bg-green-700 dark:bg-green-700 dark:hover:bg-green-800"
                        @click="unlockRoom(ticket)"
                        >Unlock</Button
                    >
                    <Button
                        size="sm"
                        variant="outline"
                        @click="openAssign(ticket)"
                        >Assign</Button
                    >
                    <Button
                        size="sm"
                        variant="destructive"
                        aria-label="Delete ticket"
                        @click="deleteTicket(ticket)"
                        >×</Button
                    >
                </div>
            </div>
            <div
                v-if="tickets.data.length === 0"
                class="bg-card text-muted-foreground rounded-lg border p-8 text-center"
            >
                No tickets found.
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
                            Ticket
                        </th>
                        <th
                            class="text-muted-foreground h-10 px-2 text-left align-middle font-medium"
                        >
                            Room
                        </th>
                        <th
                            class="text-muted-foreground h-10 px-2 text-left align-middle font-medium"
                        >
                            Category
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
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="[&_tr:last-child]:border-0">
                    <tr
                        v-for="ticket in tickets.data"
                        :key="ticket.id"
                        class="hover:bg-muted/50 border-b transition-colors"
                    >
                        <td class="p-2 align-middle">
                            <Link
                                :href="`/maintenance/${ticket.id}`"
                                class="text-foreground text-sm font-medium hover:underline"
                            >
                                {{ ticket.ticket_number }}
                            </Link>
                            <div class="text-muted-foreground text-xs">
                                {{ ticket.title }}
                            </div>
                        </td>
                        <td class="p-2 align-middle">
                            {{ ticket.room?.number || 'N/A' }}
                        </td>
                        <td class="p-2 align-middle">
                            {{ getCategoryLabel(ticket.category) }}
                        </td>
                        <td class="p-2 align-middle">
                            <Badge
                                :class="getPriorityBadgeClass(ticket.priority)"
                                variant="outline"
                            >
                                {{ ticket.priority }}
                            </Badge>
                        </td>
                        <td class="p-2 align-middle">
                            <Badge
                                :class="getStatusBadgeClass(ticket.status)"
                                variant="outline"
                            >
                                {{ ticket.status.replace('_', ' ') }}
                            </Badge>
                        </td>
                        <td class="p-2 align-middle">
                            {{ ticket.assignee?.name || 'Unassigned' }}
                        </td>
                        <td class="p-2 align-middle">
                            <div class="flex justify-end gap-1">
                                <Button
                                    v-if="ticket.status === 'open'"
                                    size="sm"
                                    @click="startTicket(ticket)"
                                >
                                    Start
                                </Button>
                                <Button
                                    v-if="ticket.status === 'in_progress'"
                                    size="sm"
                                    variant="default"
                                    class="bg-green-600 text-white hover:bg-green-700 dark:bg-green-700 dark:hover:bg-green-800"
                                    @click="openComplete(ticket)"
                                >
                                    Complete
                                </Button>
                                <Button
                                    v-if="!ticket.is_room_locked && ticket.room"
                                    size="sm"
                                    variant="destructive"
                                    @click="lockRoom(ticket)"
                                >
                                    Lock
                                </Button>
                                <Button
                                    v-if="ticket.is_room_locked"
                                    size="sm"
                                    variant="default"
                                    class="bg-green-600 text-white hover:bg-green-700 dark:bg-green-700 dark:hover:bg-green-800"
                                    @click="unlockRoom(ticket)"
                                >
                                    Unlock
                                </Button>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    @click="openAssign(ticket)"
                                >
                                    Assign
                                </Button>
                                <Button
                                    size="sm"
                                    variant="destructive"
                                    aria-label="Delete ticket"
                                    @click="deleteTicket(ticket)"
                                >
                                    ×
                                </Button>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="tickets.data.length === 0">
                        <td
                            colspan="7"
                            class="text-muted-foreground p-2 text-center align-middle"
                        >
                            No tickets found.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            <Pagination
                :data="tickets"
                label="tickets"
                @page-change="goToPage"
            />
        </div>

        <!-- Assign Modal -->
        <Dialog :open="showAssignModal" @update:open="showAssignModal = false">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Assign Ticket</DialogTitle>
                </DialogHeader>
                <div class="grid gap-4 py-4">
                    <div class="grid gap-2">
                        <Label>Assign To</Label>
                        <Select
                            v-model="assignForm.assigned_to"
                            aria-label="Assign to user"
                        >
                            <SelectTrigger>
                                <SelectValue placeholder="Unassigned" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="option in userOptions"
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
                    <Button variant="outline" @click="showAssignModal = false">
                        Cancel
                    </Button>
                    <Button @click="submitAssign"> Assign </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <!-- Complete Modal -->
        <Dialog
            :open="showCompleteModal"
            @update:open="showCompleteModal = false"
        >
            <DialogContent class="max-w-lg">
                <DialogHeader>
                    <DialogTitle
                        >Complete Ticket
                        {{ selectedTicket?.ticket_number }}</DialogTitle
                    >
                </DialogHeader>
                <div class="grid gap-4 py-4">
                    <div class="grid gap-2">
                        <Label for="resolution-notes">Resolution Notes</Label>
                        <textarea
                            id="resolution-notes"
                            v-model="completeForm.resolution_notes"
                            rows="3"
                            placeholder="Describe the work performed..."
                            class="border-input bg-background ring-offset-background placeholder:text-muted-foreground focus-visible:ring-ring flex min-h-[80px] w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="actual-cost"
                            >Actual Cost ({{ currencySymbol }})</Label
                        >
                        <Input
                            id="actual-cost"
                            v-model="completeForm.actual_cost"
                            type="number"
                        />
                    </div>
                </div>
                <DialogFooter>
                    <Button
                        variant="outline"
                        @click="showCompleteModal = false"
                    >
                        Cancel
                    </Button>
                    <Button
                        class="bg-green-600 text-white hover:bg-green-700 dark:bg-green-700 dark:hover:bg-green-800"
                        @click="submitComplete"
                    >
                        Complete Ticket
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
