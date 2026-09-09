<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, router, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';

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
    rooms: Room[];
    roomTypes: RoomType[];
    floors: string[];
    filters: {
        status?: string;
        floor?: string;
    };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Rooms', href: '/rooms' },
];

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

const filteredRooms = computed(() => {
    let result = [...props.rooms];
    if (filterForm.value.status) {
        result = result.filter(r => r.status === filterForm.value.status);
    }
    if (filterForm.value.floor) {
        result = result.filter(r => r.floor === filterForm.value.floor);
    }
    return result;
});

const getStatusBadgeClass = (status: string) => {
    const classes: Record<string, string> = {
        available: 'bg-green-100 text-green-800',
        occupied: 'bg-red-100 text-red-800',
        dirty: 'bg-yellow-100 text-yellow-800',
        out_of_order: 'bg-gray-100 text-gray-800',
    };
    return classes[status] || 'bg-gray-100 text-gray-800';
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
</script>

<template>
    <AppLayout title="Rooms" :breadcrumbs="breadcrumbs">
        <div class="p-6">
            <div class="mb-6 flex items-center justify-between">
                <h1 class="text-2xl font-bold text-gray-900">Rooms</h1>
                <button
                    class="inline-flex items-center px-4 py-2 bg-gray-900 text-white rounded-md hover:bg-gray-800"
                    @click="showCreateModal = true"
                >
                    Add Room
                </button>
            </div>

            <!-- Filters -->
            <div class="mb-4 flex gap-4">
                <select
                    v-model="filterForm.status"
                    class="rounded-md border-gray-300 text-sm"
                    @change="applyFilters"
                >
                    <option value="">All Statuses</option>
                    <option value="available">Available</option>
                    <option value="occupied">Occupied</option>
                    <option value="dirty">Dirty</option>
                    <option value="out_of_order">Out of Order</option>
                </select>
                <select
                    v-model="filterForm.floor"
                    class="rounded-md border-gray-300 text-sm"
                    @change="applyFilters"
                >
                    <option value="">All Floors</option>
                    <option v-for="floor in floors" :key="floor" :value="floor">
                        Floor {{ floor }}
                    </option>
                </select>
            </div>

            <!-- Room Grid -->
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                <div
                    v-for="room in filteredRooms"
                    :key="room.id"
                    class="bg-white rounded-lg shadow border p-4 hover:shadow-md transition-shadow"
                >
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-lg font-bold">{{ room.number }}</span>
                        <span
                            class="px-2 py-1 text-xs font-medium rounded-full"
                            :class="getStatusBadgeClass(room.status)"
                        >
                            {{ room.status.replace('_', ' ') }}
                        </span>
                    </div>
                    <div class="text-sm text-gray-600 mb-2">
                        {{ room.room_type.name }}
                    </div>
                    <div class="text-xs text-gray-500 mb-3">
                        Floor {{ room.floor }}{{ room.wing ? ` - ${room.wing}` : '' }}
                        <span v-if="room.is_accessible" class="ml-2">♿</span>
                        <span v-if="room.is_smoking" class="ml-2">🚬</span>
                    </div>
                    <div class="flex gap-1">
                        <button
                            class="flex-1 px-2 py-1 text-xs text-gray-700 bg-gray-100 rounded hover:bg-gray-200"
                            @click="openStatus(room)"
                        >
                            Status
                        </button>
                        <button
                            class="flex-1 px-2 py-1 text-xs text-gray-700 bg-gray-100 rounded hover:bg-gray-200"
                            @click="openEdit(room)"
                        >
                            Edit
                        </button>
                        <button
                            class="px-2 py-1 text-xs text-red-700 bg-red-50 rounded hover:bg-red-100"
                            @click="deleteRoom(room)"
                        >
                            ×
                        </button>
                    </div>
                </div>
            </div>

            <!-- Create Modal -->
            <div
                v-if="showCreateModal"
                class="fixed inset-0 z-50 overflow-y-auto"
                @click.self="showCreateModal = false"
            >
                <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                    <div class="relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl sm:my-8 sm:w-full sm:max-w-lg sm:p-6">
                        <h3 class="text-lg font-semibold mb-4">Add New Room</h3>
                        <form @submit.prevent="submitCreate">
                            <div class="space-y-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Room Type</label>
                                    <select v-model="form.room_type_id" class="mt-1 block w-full rounded-md border-gray-300" required>
                                        <option value="">Select type</option>
                                        <option v-for="type in roomTypes" :key="type.id" :value="type.id">
                                            {{ type.name }} - ${{ type.base_rate }}/night
                                        </option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Room Number</label>
                                    <input v-model="form.number" type="text" class="mt-1 block w-full rounded-md border-gray-300" required>
                                </div>
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Floor</label>
                                        <input v-model="form.floor" type="text" class="mt-1 block w-full rounded-md border-gray-300">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Wing</label>
                                        <input v-model="form.wing" type="text" class="mt-1 block w-full rounded-md border-gray-300">
                                    </div>
                                </div>
                                <div class="flex gap-4">
                                    <label class="flex items-center gap-2">
                                        <input v-model="form.is_accessible" type="checkbox" class="rounded border-gray-300">
                                        <span class="text-sm">Accessible</span>
                                    </label>
                                    <label class="flex items-center gap-2">
                                        <input v-model="form.is_smoking" type="checkbox" class="rounded border-gray-300">
                                        <span class="text-sm">Smoking</span>
                                    </label>
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
                                    Create Room
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Edit Modal -->
            <div
                v-if="showEditModal && selectedRoom"
                class="fixed inset-0 z-50 overflow-y-auto"
                @click.self="showEditModal = false"
            >
                <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                    <div class="relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl sm:my-8 sm:w-full sm:max-w-lg sm:p-6">
                        <h3 class="text-lg font-semibold mb-4">Edit Room {{ selectedRoom.number }}</h3>
                        <form @submit.prevent="submitEdit">
                            <div class="space-y-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Room Type</label>
                                    <select v-model="editForm.room_type_id" class="mt-1 block w-full rounded-md border-gray-300" required>
                                        <option v-for="type in roomTypes" :key="type.id" :value="type.id">
                                            {{ type.name }}
                                        </option>
                                    </select>
                                </div>
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Floor</label>
                                        <input v-model="editForm.floor" type="text" class="mt-1 block w-full rounded-md border-gray-300">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Wing</label>
                                        <input v-model="editForm.wing" type="text" class="mt-1 block w-full rounded-md border-gray-300">
                                    </div>
                                </div>
                                <div class="flex gap-4">
                                    <label class="flex items-center gap-2">
                                        <input v-model="editForm.is_accessible" type="checkbox" class="rounded border-gray-300">
                                        <span class="text-sm">Accessible</span>
                                    </label>
                                    <label class="flex items-center gap-2">
                                        <input v-model="editForm.is_smoking" type="checkbox" class="rounded border-gray-300">
                                        <span class="text-sm">Smoking</span>
                                    </label>
                                    <label class="flex items-center gap-2">
                                        <input v-model="editForm.is_active" type="checkbox" class="rounded border-gray-300">
                                        <span class="text-sm">Active</span>
                                    </label>
                                </div>
                            </div>
                            <div class="mt-6 flex justify-end gap-2">
                                <button
                                    type="button"
                                    class="px-4 py-2 text-sm text-gray-700 bg-gray-100 rounded-md hover:bg-gray-200"
                                    @click="showEditModal = false"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    class="px-4 py-2 text-sm text-white bg-gray-900 rounded-md hover:bg-gray-800"
                                >
                                    Save Changes
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Status Modal -->
            <div
                v-if="showStatusModal && selectedRoom"
                class="fixed inset-0 z-50 overflow-y-auto"
                @click.self="showStatusModal = false"
            >
                <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                    <div class="relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl sm:my-8 sm:w-full sm:max-w-sm sm:p-6">
                        <h3 class="text-lg font-semibold mb-4">
                            Room {{ selectedRoom.number }} Status
                        </h3>
                        <form @submit.prevent="submitStatus">
                            <div class="space-y-2">
                                <label
                                    v-for="status in ['available', 'occupied', 'dirty', 'out_of_order']"
                                    :key="status"
                                    class="flex items-center gap-3 p-3 border rounded-lg cursor-pointer hover:bg-gray-50"
                                    :class="{ 'bg-gray-50 border-gray-900': statusForm.status === status }"
                                >
                                    <input
                                        v-model="statusForm.status"
                                        type="radio"
                                        :value="status"
                                        class="text-gray-900"
                                    >
                                    <span class="text-sm font-medium capitalize">{{ status.replace('_', ' ') }}</span>
                                </label>
                            </div>
                            <div class="mt-6 flex justify-end gap-2">
                                <button
                                    type="button"
                                    class="px-4 py-2 text-sm text-gray-700 bg-gray-100 rounded-md hover:bg-gray-200"
                                    @click="showStatusModal = false"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    class="px-4 py-2 text-sm text-white bg-gray-900 rounded-md hover:bg-gray-800"
                                >
                                    Update Status
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
