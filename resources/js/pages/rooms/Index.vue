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
import { Checkbox } from '@/components/ui/checkbox';
import Pagination from '@/components/ui/pagination/Pagination.vue';

interface RoomType {
    id: number;
    name: string;
    code: string;
    base_rate: number;
}

interface Room {
    id: number;
    number: string;
    floor: string;
    wing: string;
    status: string;
    room_type: RoomType;
    is_accessible: boolean;
    is_smoking: boolean;
    is_active: boolean;
}

const props = defineProps<{
    rooms: {
        data: Room[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    roomTypes: RoomType[];
    floors: string[];
    filters: {
        status?: string;
        floor?: string;
    };
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }, { title: 'Rooms', href: '/rooms' }] } });

const showCreateModal = ref(false);
const selectedRoom = ref<Room | null>(null);
const showEditModal = ref(false);
const showStatusModal = ref(false);

const form = ref({
    room_type_id: '',
    number: '',
    floor: '',
    wing: '',
    is_accessible: false,
    is_smoking: false,
    notes: '',
});

const editForm = ref({
    room_type_id: '',
    floor: '',
    wing: '',
    is_accessible: false,
    is_smoking: false,
    is_active: true,
    notes: '',
});

const statusForm = ref({
    status: '',
});

const filterForm = ref({
    status: props.filters.status || '',
    floor: props.filters.floor || '',
});

const statusBadgeVariant: Record<string, string> = {
    available: 'bg-green-100 text-green-800 border-green-300 dark:bg-green-900 dark:text-green-300 dark:border-green-700',
    occupied: 'bg-red-100 text-red-800 border-red-300 dark:bg-red-900 dark:text-red-300 dark:border-red-700',
    dirty: 'bg-yellow-100 text-yellow-800 border-yellow-300 dark:bg-yellow-900 dark:text-yellow-300 dark:border-yellow-700',
    out_of_order: 'bg-muted text-muted-foreground border-border',
};

const submitCreate = () => {
    router.post('/rooms', form.value, {
        onSuccess: () => {
            showCreateModal.value = false;
            form.value = {
                room_type_id: '',
                number: '',
                floor: '',
                wing: '',
                is_accessible: false,
                is_smoking: false,
                notes: '',
            };
        },
    });
};

const openEdit = (room: Room) => {
    selectedRoom.value = room;
    editForm.value = {
        room_type_id: room.room_type.id,
        floor: room.floor,
        wing: room.wing,
        is_accessible: room.is_accessible,
        is_smoking: room.is_smoking,
        is_active: room.is_active,
        notes: '',
    };
    showEditModal.value = true;
};

const submitEdit = () => {
    if (! selectedRoom.value) return;
    router.put(`/rooms/${selectedRoom.value.id}`, editForm.value, {
        onSuccess: () => {
            showEditModal.value = false;
            selectedRoom.value = null;
        },
    });
};

const openStatus = (room: Room) => {
    selectedRoom.value = room;
    statusForm.value.status = room.status;
    showStatusModal.value = true;
};

const submitStatus = () => {
    if (! selectedRoom.value) return;
    router.patch(`/rooms/${selectedRoom.value.id}/status`, statusForm.value, {
        onSuccess: () => {
            showStatusModal.value = false;
            selectedRoom.value = null;
        },
    });
};

const deleteRoom = (room: Room) => {
    if (confirm(`Delete room ${room.number}?`)) {
        router.delete(`/rooms/${room.id}`);
    }
};

const applyFilters = () => {
    router.get('/rooms', filterForm.value, {
        preserveState: true,
        replace: true,
    });
};

const goToPage = (page: number) => {
    router.get('/rooms', { ...filterForm.value, page }, {
        preserveState: true,
        replace: true,
    });
};
</script>

<template>
        <div class="p-4 md:p-6">
            <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <h1 class="text-2xl font-bold text-foreground">Rooms</h1>
                <Button @click="showCreateModal = true">
                    Add Room
                </Button>
            </div>

            <!-- Filters -->
            <div class="mb-4 flex flex-col gap-4 sm:flex-row">
                <Select v-model="filterForm.status" class="sm:max-w-xs" aria-label="Filter by status" @update:model-value="applyFilters">
                    <SelectTrigger>
                        <SelectValue placeholder="All Statuses" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="available">Available</SelectItem>
                        <SelectItem value="occupied">Occupied</SelectItem>
                        <SelectItem value="dirty">Dirty</SelectItem>
                        <SelectItem value="out_of_order">Out of Order</SelectItem>
                    </SelectContent>
                </Select>
                <Select v-model="filterForm.floor" class="sm:max-w-xs" aria-label="Filter by floor" @update:model-value="applyFilters">
                    <SelectTrigger>
                        <SelectValue placeholder="All Floors" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem v-for="floor in floors" :key="floor" :value="floor">
                            Floor {{ floor }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <!-- Room Grid -->
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                <div
                    v-for="room in rooms.data"
                    :key="room.id"
                    class="bg-card rounded-lg shadow border border-border p-4 hover:shadow-md transition-shadow"
                >
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-lg font-bold text-foreground">{{ room.number }}</span>
                        <Badge :class="statusBadgeVariant[room.status] ?? 'bg-muted text-muted-foreground border-border'" variant="outline">
                            {{ room.status.replace('_', ' ') }}
                        </Badge>
                    </div>
                    <div class="text-sm text-muted-foreground mb-2">
                        {{ room.room_type.name }}
                    </div>
                    <div class="text-xs text-muted-foreground mb-3">
                        Floor {{ room.floor }}{{ room.wing ? ` - ${room.wing}` : '' }}
                        <span v-if="room.is_accessible" class="ml-2">♿</span>
                        <span v-if="room.is_smoking" class="ml-2">🚬</span>
                    </div>
                    <div class="flex gap-1">
                        <Button variant="outline" size="sm" class="flex-1" @click="openStatus(room)">
                            Status
                        </Button>
                        <Button variant="outline" size="sm" class="flex-1" @click="openEdit(room)">
                            Edit
                        </Button>
                        <Button variant="destructive" size="sm" aria-label="Delete room" @click="deleteRoom(room)">
                            ×
                        </Button>
                    </div>
                </div>
            </div>

            <div class="mt-4">
                <Pagination :data="rooms" label="rooms" @page-change="goToPage" />
            </div>

            <!-- Create Modal -->
            <Dialog :open="showCreateModal" @update:open="showCreateModal = $event">
                <DialogContent class="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Add New Room</DialogTitle>
                    </DialogHeader>
                    <form id="create-room-form" @submit.prevent="submitCreate">
                        <div class="space-y-4">
                            <div class="grid gap-2">
                                <Label>Room Type</Label>
                                <Select v-model="form.room_type_id" aria-label="Room Type">
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select type" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem v-for="type in roomTypes" :key="type.id" :value="String(type.id)">
                                            {{ type.name }} - ${{ type.base_rate }}/night
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div class="grid gap-2">
                                <Label for="room-number">Room Number</Label>
                                <Input id="room-number" v-model="form.number" type="text" required />
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div class="grid gap-2">
                                    <Label for="room-floor">Floor</Label>
                                    <Input id="room-floor" v-model="form.floor" type="text" />
                                </div>
                                <div class="grid gap-2">
                                    <Label for="room-wing">Wing</Label>
                                    <Input id="room-wing" v-model="form.wing" type="text" />
                                </div>
                            </div>
                            <div class="flex gap-4">
                                <label class="flex items-center gap-2">
                                    <Checkbox :checked="form.is_accessible" @update:checked="form.is_accessible = $event" />
                                    <span class="text-sm text-foreground">Accessible</span>
                                </label>
                                <label class="flex items-center gap-2">
                                    <Checkbox :checked="form.is_smoking" @update:checked="form.is_smoking = $event" />
                                    <span class="text-sm text-foreground">Smoking</span>
                                </label>
                            </div>
                        </div>
                    </form>
                    <DialogFooter>
                        <div class="flex justify-end gap-2">
                            <Button variant="outline" @click="showCreateModal = false">
                                Cancel
                            </Button>
                            <Button type="submit" form="create-room-form">
                                Create Room
                            </Button>
                        </div>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <!-- Edit Modal -->
            <Dialog :open="showEditModal" @update:open="showEditModal = $event">
                <DialogContent class="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Edit Room {{ selectedRoom?.number }}</DialogTitle>
                    </DialogHeader>
                    <form id="edit-room-form" @submit.prevent="submitEdit">
                        <div class="space-y-4">
                            <div class="grid gap-2">
                                <Label>Room Type</Label>
                                <Select :model-value="String(editForm.room_type_id)" @update:model-value="editForm.room_type_id = $event" aria-label="Room Type">
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select type" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem v-for="type in roomTypes" :key="type.id" :value="String(type.id)">
                                            {{ type.name }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div class="grid gap-2">
                                    <Label for="edit-floor">Floor</Label>
                                    <Input id="edit-floor" v-model="editForm.floor" type="text" />
                                </div>
                                <div class="grid gap-2">
                                    <Label for="edit-wing">Wing</Label>
                                    <Input id="edit-wing" v-model="editForm.wing" type="text" />
                                </div>
                            </div>
                            <div class="flex gap-4">
                                <label class="flex items-center gap-2">
                                    <Checkbox :checked="editForm.is_accessible" @update:checked="editForm.is_accessible = $event" />
                                    <span class="text-sm text-foreground">Accessible</span>
                                </label>
                                <label class="flex items-center gap-2">
                                    <Checkbox :checked="editForm.is_smoking" @update:checked="editForm.is_smoking = $event" />
                                    <span class="text-sm text-foreground">Smoking</span>
                                </label>
                                <label class="flex items-center gap-2">
                                    <Checkbox :checked="editForm.is_active" @update:checked="editForm.is_active = $event" />
                                    <span class="text-sm text-foreground">Active</span>
                                </label>
                            </div>
                        </div>
                    </form>
                    <DialogFooter>
                        <div class="flex justify-end gap-2">
                            <Button variant="outline" @click="showEditModal = false">
                                Cancel
                            </Button>
                            <Button type="submit" form="edit-room-form">
                                Save Changes
                            </Button>
                        </div>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <!-- Status Modal -->
            <Dialog :open="showStatusModal" @update:open="showStatusModal = $event">
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            Room {{ selectedRoom?.number }} Status
                        </DialogTitle>
                    </DialogHeader>
                    <form id="status-room-form" @submit.prevent="submitStatus">
                        <div class="space-y-2">
                            <label
                                v-for="status in ['available', 'occupied', 'dirty', 'out_of_order']"
                                :key="status"
                                class="flex items-center gap-3 p-3 border border-border rounded-lg cursor-pointer hover:bg-accent"
                                :class="{ 'bg-accent border-accent-foreground': statusForm.status === status }"
                            >
                                <input
                                    v-model="statusForm.status"
                                    type="radio"
                                    :value="status"
                                    class="text-foreground"
                                >
                                <span class="text-sm font-medium capitalize text-foreground">{{ status.replace('_', ' ') }}</span>
                            </label>
                        </div>
                    </form>
                    <DialogFooter>
                        <div class="flex justify-end gap-2">
                            <Button variant="outline" @click="showStatusModal = false">
                                Cancel
                            </Button>
                            <Button type="submit" form="status-room-form">
                                Update Status
                            </Button>
                        </div>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
</template>
