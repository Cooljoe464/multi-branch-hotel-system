<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';

interface RoomType {
    id: number;
    name: string;
    code: string;
}

interface Room {
    id: number;
    number: string;
    floor: string;
    wing: string;
    status: string;
    room_type: RoomType;
}

interface ReservationData {
    id: number;
    confirmation_number: string;
    guest_name: string;
    status: string;
    check_in_date: string;
    check_out_date: string;
}

interface Cell {
    date: string;
    is_check_in: boolean;
    is_check_out: boolean;
    reservation: ReservationData | null;
}

interface ChartRoom {
    id: number;
    number: string;
    floor: string;
    wing: string;
    status: string;
    room_type: RoomType;
    cells: Cell[];
}

const props = defineProps<{
    chartData: ChartRoom[];
    dates: string[];
    startDate: string;
    endDate: string;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Tape Chart', href: '/tape-chart' },
];

const selectedReservation = ref<ReservationData | null>(null);
const showReservationModal = ref(false);

const formatDate = (dateStr: string) => {
    const date = new Date(dateStr);
    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
};

const formatDay = (dateStr: string) => {
    const date = new Date(dateStr);
    return date.toLocaleDateString('en-US', { weekday: 'short' });
};

const isToday = (dateStr: string) => {
    return dateStr === new Date().toISOString().split('T')[0];
};

const navigate = (direction: 'prev' | 'next') => {
    const start = new Date(props.startDate);
    start.setDate(start.getDate() + (direction === 'next' ? 14 : -14));
    router.get('/tape-chart', {
        start_date: start.toISOString().split('T')[0],
    }, {
        preserveState: true,
        replace: true,
    });
};

const openReservation = (reservation: ReservationData) => {
    selectedReservation.value = reservation;
    showReservationModal.value = true;
};

const getStatusColor = (status: string, reservation: ReservationData | null) => {
    if (! reservation) return '';
    if (reservation.status === 'checked_in') return 'bg-green-100 text-green-800';
    if (reservation.status === 'reserved') return 'bg-blue-100 text-blue-800';
    return 'bg-yellow-100 text-yellow-800';
};

const getRoomStatusIndicator = (status: string) => {
    const colors: Record<string, string> = {
        available: 'bg-green-500',
        occupied: 'bg-red-500',
        dirty: 'bg-yellow-500',
        out_of_order: 'bg-gray-500',
    };
    return colors[status] || 'bg-gray-300';
};
</script>

<template>
    <AppLayout title="Tape Chart" :breadcrumbs="breadcrumbs">
        <div class="p-6">
            <div class="mb-6 flex items-center justify-between">
                <h1 class="text-2xl font-bold text-gray-900">Tape Chart</h1>
                <div class="flex items-center gap-2">
                    <button
                        class="px-3 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50"
                        @click="navigate('prev')"
                    >
                        Previous
                    </button>
                    <span class="text-sm text-gray-600">
                        {{ formatDate(startDate) }} - {{ formatDate(endDate) }}
                    </span>
                    <button
                        class="px-3 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50"
                        @click="navigate('next')"
                    >
                        Next
                    </button>
                </div>
            </div>

            <!-- Legend -->
            <div class="mb-4 flex items-center gap-4 text-sm">
                <div class="flex items-center gap-1">
                    <span class="inline-block w-3 h-3 rounded bg-green-500"></span>
                    <span>Available</span>
                </div>
                <div class="flex items-center gap-1">
                    <span class="inline-block w-3 h-3 rounded bg-red-500"></span>
                    <span>Occupied</span>
                </div>
                <div class="flex items-center gap-1">
                    <span class="inline-block w-3 h-3 rounded bg-yellow-500"></span>
                    <span>Dirty</span>
                </div>
                <div class="flex items-center gap-1">
                    <span class="inline-block w-3 h-3 rounded bg-blue-500"></span>
                    <span>Reserved</span>
                </div>
                <div class="flex items-center gap-1">
                    <span class="inline-block w-3 h-3 rounded bg-green-200"></span>
                    <span>Checked In</span>
                </div>
            </div>

            <!-- Chart -->
            <div class="overflow-x-auto bg-white rounded-lg shadow">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead>
                        <tr>
                            <th class="sticky left-0 z-10 bg-gray-50 px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border-r">
                                Room
                            </th>
                            <th class="sticky left-24 z-10 bg-gray-50 px-2 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border-r">
                                Type
                            </th>
                            <th
                                v-for="date in dates"
                                :key="date"
                                class="px-2 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider border-r min-w-[80px]"
                                :class="{ 'bg-blue-50': isToday(date) }"
                            >
                                <div>{{ formatDay(date) }}</div>
                                <div>{{ formatDate(date) }}</div>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <tr v-for="room in chartData" :key="room.id" class="hover:bg-gray-50">
                            <td class="sticky left-0 z-10 bg-white px-4 py-2 whitespace-nowrap border-r">
                                <div class="flex items-center gap-2">
                                    <span
                                        class="w-2 h-2 rounded-full"
                                        :class="getRoomStatusIndicator(room.status)"
                                    ></span>
                                    <span class="font-medium text-gray-900">{{ room.number }}</span>
                                </div>
                                <div class="text-xs text-gray-500">
                                    Floor {{ room.floor }}{{ room.wing ? ` - ${room.wing}` : '' }}
                                </div>
                            </td>
                            <td class="sticky left-24 z-10 bg-white px-2 py-2 whitespace-nowrap border-r text-sm text-gray-600">
                                {{ room.room_type.code }}
                            </td>
                            <td
                                v-for="(cell, idx) in room.cells"
                                :key="idx"
                                class="px-1 py-1 border-r"
                                :class="{ 'bg-blue-50/50': isToday(cell.date) }"
                            >
                                <div
                                    v-if="cell.reservation"
                                    class="rounded p-1 text-xs cursor-pointer hover:opacity-80"
                                    :class="getStatusColor(cell.status, cell.reservation)"
                                    @click="openReservation(cell.reservation)"
                                >
                                    <div class="font-medium truncate">
                                        {{ cell.is_check_in ? '→' : '' }}{{ cell.reservation.guest_name }}
                                    </div>
                                    <div v-if="cell.is_check_out" class="text-gray-500">←</div>
                                </div>
                                <div
                                    v-else-if="room.status === 'out_of_order'"
                                    class="text-xs text-gray-400 italic p-1"
                                >
                                    OOO
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Reservation Modal -->
            <div
                v-if="showReservationModal && selectedReservation"
                class="fixed inset-0 z-50 overflow-y-auto"
                @click.self="showReservationModal = false"
            >
                <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                    <div class="relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl sm:my-8 sm:w-full sm:max-w-lg sm:p-6">
                        <div class="absolute right-0 top-0 pr-4 pt-4">
                            <button
                                type="button"
                                class="rounded-md bg-white text-gray-400 hover:text-gray-500"
                                @click="showReservationModal = false"
                            >
                                <span class="sr-only">Close</span>
                                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>
                        <div class="sm:flex sm:items-start">
                            <div class="mt-3 text-center sm:mt-0 sm:text-left w-full">
                                <h3 class="text-lg font-semibold leading-6 text-gray-900">
                                    {{ selectedReservation.guest_name }}
                                </h3>
                                <div class="mt-2 space-y-1">
                                    <p class="text-sm text-gray-500">
                                        Confirmation: {{ selectedReservation.confirmation_number }}
                                    </p>
                                    <p class="text-sm text-gray-500">
                                        Status: {{ selectedReservation.status }}
                                    </p>
                                    <p class="text-sm text-gray-500">
                                        Check-in: {{ selectedReservation.check_in_date }}
                                    </p>
                                    <p class="text-sm text-gray-500">
                                        Check-out: {{ selectedReservation.check_out_date }}
                                    </p>
                                </div>
                                <div class="mt-4 flex gap-2">
                                    <a
                                        :href="`/reservations/${selectedReservation.id}`"
                                        class="inline-flex justify-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
                                    >
                                        View Details
                                    </a>
                                    <button
                                        type="button"
                                        class="inline-flex justify-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
                                        @click="showReservationModal = false"
                                    >
                                        Close
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
