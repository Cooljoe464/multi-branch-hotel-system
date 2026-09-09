<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, router, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';

interface Room {
    id: number;
    number: string;
    floor: string;
}

interface RoomType {
    id: number;
    name: string;
    code: string;
}

interface Reservation {
    id: number;
    confirmation_number: string;
    guest_name: string;
    guest_email: string | null;
    guest_phone: string | null;
    adults: number;
    children: number;
    check_in_date: string;
    check_out_date: string;
    status: string;
    room_rate: number;
    total_amount: number;
    amount_paid: number;
    payment_status: string;
    room: Room | null;
    room_type: RoomType;
    created_at: string;
}

const props = defineProps<{
    reservations: {
        data: Reservation[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    filters: {
        status?: string;
        date?: string;
    };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Reservations', href: '/reservations' },
];

const filterForm = ref({
    status: props.filters.status || '',
    date: props.filters.date || '',
});

const getStatusBadgeClass = (status: string) => {
    const classes: Record<string, string> = {
        pending: 'bg-yellow-100 text-yellow-800',
        confirmed: 'bg-blue-100 text-blue-800',
        reserved: 'bg-indigo-100 text-indigo-800',
        checked_in: 'bg-green-100 text-green-800',
        checked_out: 'bg-gray-100 text-gray-800',
        cancelled: 'bg-red-100 text-red-800',
    };
    return classes[status] || 'bg-gray-100 text-gray-800';
};

const applyFilters = () => {
    router.get('/reservations', filterForm.value, {
        preserveState: true,
        replace: true,
    });
};

const formatDate = (dateStr: string) => {
    return new Date(dateStr).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
};
</script>

<template>
    <AppLayout title="Reservations" :breadcrumbs="breadcrumbs">
        <div class="p-6">
            <div class="mb-6 flex items-center justify-between">
                <h1 class="text-2xl font-bold text-gray-900">Reservations</h1>
                <Link
                    href="/reservations/create"
                    class="inline-flex items-center px-4 py-2 bg-gray-900 text-white rounded-md hover:bg-gray-800"
                >
                    New Reservation
                </Link>
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
                    <option value="confirmed">Confirmed</option>
                    <option value="reserved">Reserved</option>
                    <option value="checked_in">Checked In</option>
                    <option value="checked_out">Checked Out</option>
                    <option value="cancelled">Cancelled</option>
                </select>
                <input
                    v-model="filterForm.date"
                    type="date"
                    class="rounded-md border-gray-300 text-sm"
                    @change="applyFilters"
                >
            </div>

            <!-- Reservations Table -->
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Confirmation</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Guest</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Room</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Check-in</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Check-out</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Total</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <tr v-for="reservation in reservations.data" :key="reservation.id" class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-sm font-medium text-gray-900">
                                {{ reservation.confirmation_number }}
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600">
                                {{ reservation.guest_name }}
                                <div class="text-xs text-gray-500">{{ reservation.guest_email }}</div>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600">
                                {{ reservation.room?.number || 'Unassigned' }}
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600">
                                {{ reservation.room_type.name }}
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600">
                                {{ formatDate(reservation.check_in_date) }}
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600">
                                {{ formatDate(reservation.check_out_date) }}
                            </td>
                            <td class="px-4 py-3">
                                <span
                                    class="px-2 py-1 text-xs font-medium rounded-full"
                                    :class="getStatusBadgeClass(reservation.status)"
                                >
                                    {{ reservation.status.replace('_', ' ') }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-900 text-right">
                                ${{ reservation.total_amount }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                <Link
                                    :href="`/reservations/${reservation.id}`"
                                    class="text-sm text-gray-600 hover:text-gray-900"
                                >
                                    View
                                </Link>
                            </td>
                        </tr>
                        <tr v-if="reservations.data.length === 0">
                            <td colspan="9" class="px-4 py-8 text-center text-gray-500">
                                No reservations found.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div v-if="reservations.last_page > 1" class="mt-4 flex items-center justify-between">
                <div class="text-sm text-gray-500">
                    Showing {{ (reservations.current_page - 1) * reservations.per_page + 1 }}
                    to {{ Math.min(reservations.current_page * reservations.per_page, reservations.total) }}
                    of {{ reservations.total }} reservations
                </div>
                <div class="flex gap-1">
                    <Link
                        v-for="page in reservations.last_page"
                        :key="page"
                        :href="`/reservations?page=${page}&status=${filterForm.status || ''}`"
                        class="px-3 py-1 text-sm rounded-md"
                        :class="page === reservations.current_page ? 'bg-gray-900 text-white' : 'bg-white text-gray-700 border border-gray-300 hover:bg-gray-50'"
                    >
                        {{ page }}
                    </Link>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
