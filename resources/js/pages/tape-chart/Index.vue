<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, router, Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogFooter,
} from '@/components/ui/dialog';
import {
    formatDate,
    formatDateShort,
    formatWeekday as formatDay,
    isToday,
} from '@/lib/dates';

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
    status: string;
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

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Tape Chart', href: '/tape-chart' },
        ],
    },
});

const selectedReservation = ref<ReservationData | null>(null);
const showReservationModal = ref(false);

const navigate = (direction: 'prev' | 'next') => {
    const start = new Date(props.startDate);
    start.setDate(start.getDate() + (direction === 'next' ? 14 : -14));
    router.get(
        '/tape-chart',
        {
            start_date: start.toISOString().split('T')[0],
        },
        {
            preserveState: true,
            replace: true,
        },
    );
};

const openReservation = (reservation: ReservationData) => {
    selectedReservation.value = reservation;
    showReservationModal.value = true;
};

const getStatusColor = (
    status: string,
    reservation: ReservationData | null,
) => {
    if (!reservation) return '';
    if (reservation.status === 'checked_in')
        return 'bg-green-100 text-green-800';
    if (reservation.status === 'reserved') return 'bg-blue-100 text-blue-800';
    return 'bg-yellow-100 text-yellow-800';
};

const getRoomStatusIndicator = (status: string) => {
    const colors: Record<string, string> = {
        available: 'bg-green-500',
        occupied: 'bg-red-500',
        dirty: 'bg-yellow-500',
        out_of_order: 'bg-muted-foreground',
    };
    return colors[status] || 'bg-muted-foreground/50';
};
</script>

<template>
    <div class="p-4 md:p-6">
        <div
            class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
        >
            <h1 class="text-foreground text-2xl font-bold">Tape Chart</h1>
            <div class="flex flex-wrap items-center gap-2">
                <Button variant="outline" @click="navigate('prev')">
                    Previous
                </Button>
                <span class="text-muted-foreground text-sm">
                    {{ formatDate(startDate) }} - {{ formatDate(endDate) }}
                </span>
                <Button variant="outline" @click="navigate('next')">
                    Next
                </Button>
            </div>
        </div>

        <!-- Legend -->
        <div
            class="text-foreground mb-4 flex flex-wrap items-center gap-3 text-sm sm:gap-4"
        >
            <div class="flex items-center gap-1">
                <span class="inline-block h-3 w-3 rounded bg-green-500"></span>
                <span>Available</span>
            </div>
            <div class="flex items-center gap-1">
                <span class="inline-block h-3 w-3 rounded bg-red-500"></span>
                <span>Occupied</span>
            </div>
            <div class="flex items-center gap-1">
                <span class="inline-block h-3 w-3 rounded bg-yellow-500"></span>
                <span>Dirty</span>
            </div>
            <div class="flex items-center gap-1">
                <span class="inline-block h-3 w-3 rounded bg-blue-500"></span>
                <span>Reserved</span>
            </div>
            <div class="flex items-center gap-1">
                <span class="inline-block h-3 w-3 rounded bg-green-200"></span>
                <span>Checked In</span>
            </div>
        </div>

        <!-- Chart -->
        <div class="overflow-x-auto rounded-md border">
            <table class="w-full caption-bottom text-sm">
                <thead class="bg-muted/50 border-b [&_tr]:border-b">
                    <tr>
                        <th
                            class="text-muted-foreground sticky left-0 z-10 h-10 border-r px-2 text-left align-middle font-medium"
                        >
                            Room
                        </th>
                        <th
                            class="text-muted-foreground sticky left-24 z-10 h-10 border-r px-2 text-left align-middle font-medium"
                        >
                            Type
                        </th>
                        <th
                            v-for="date in dates"
                            :key="date"
                            class="text-muted-foreground h-10 min-w-[80px] border-r px-2 text-center text-left align-middle font-medium"
                        >
                            <div>{{ formatDay(date) }}</div>
                            <div>{{ formatDate(date) }}</div>
                        </th>
                    </tr>
                </thead>
                <tbody class="[&_tr:last-child]:border-0">
                    <tr
                        v-for="room in chartData"
                        :key="room.id"
                        class="hover:bg-muted/50 border-b transition-colors"
                    >
                        <td
                            class="sticky left-0 z-10 border-r p-2 align-middle whitespace-nowrap"
                        >
                            <div class="flex items-center gap-2">
                                <span
                                    class="h-2 w-2 rounded-full"
                                    :class="getRoomStatusIndicator(room.status)"
                                ></span>
                                <span class="text-foreground font-medium">{{
                                    room.number
                                }}</span>
                            </div>
                            <div class="text-muted-foreground text-xs">
                                Floor {{ room.floor
                                }}{{ room.wing ? ` - ${room.wing}` : '' }}
                            </div>
                        </td>
                        <td
                            class="text-muted-foreground sticky left-24 z-10 border-r p-2 align-middle text-sm whitespace-nowrap"
                        >
                            {{ room.room_type.code }}
                        </td>
                        <td
                            v-for="(cell, idx) in room.cells"
                            :key="idx"
                            class="border-r p-2 align-middle"
                        >
                            <div
                                v-if="cell.reservation"
                                class="cursor-pointer rounded p-1 text-xs hover:opacity-80"
                                :class="
                                    getStatusColor(
                                        cell.status,
                                        cell.reservation,
                                    )
                                "
                                @click="openReservation(cell.reservation)"
                            >
                                <div class="truncate font-medium">
                                    {{ cell.is_check_in ? '→' : ''
                                    }}{{ cell.reservation.guest_name }}
                                </div>
                                <div
                                    v-if="cell.is_check_out"
                                    class="text-muted-foreground"
                                >
                                    ←
                                </div>
                            </div>
                            <div
                                v-else-if="room.status === 'out_of_order'"
                                class="text-muted-foreground p-1 text-xs italic"
                            >
                                OOO
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Reservation Modal -->
        <Dialog
            v-if="showReservationModal && selectedReservation"
            @close="showReservationModal = false"
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {{ selectedReservation.guest_name }}
                    </DialogTitle>
                </DialogHeader>
                <div class="space-y-1">
                    <p class="text-muted-foreground text-sm">
                        Confirmation:
                        {{ selectedReservation.confirmation_number }}
                    </p>
                    <p class="text-muted-foreground text-sm">
                        Status: {{ selectedReservation.status }}
                    </p>
                    <p class="text-muted-foreground text-sm">
                        Check-in:
                        {{ formatDate(selectedReservation.check_in_date) }}
                    </p>
                    <p class="text-muted-foreground text-sm">
                        Check-out:
                        {{ formatDate(selectedReservation.check_out_date) }}
                    </p>
                </div>
                <DialogFooter>
                    <div class="flex gap-2">
                        <Link
                            :href="`/reservations/${selectedReservation.id}`"
                            class="border-border bg-background text-foreground hover:bg-muted inline-flex items-center justify-center rounded-md border px-3 py-2 text-sm font-semibold shadow-sm"
                        >
                            View Details
                        </Link>
                        <Button
                            variant="outline"
                            @click="showReservationModal = false"
                        >
                            Close
                        </Button>
                    </div>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
