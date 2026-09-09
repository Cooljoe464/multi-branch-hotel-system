<script setup lang="ts">
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

interface Branch {
    id: number;
    name: string;
}

interface Reservation {
    id: number;
    confirmation_number: string;
    guest_name: string;
    guest_email: string | null;
    guest_phone: string | null;
    guest_notes: string | null;
    adults: number;
    children: number;
    check_in_date: string;
    check_out_date: string;
    actual_check_in_at: string | null;
    actual_check_out_at: string | null;
    status: string;
    room_rate: number;
    total_amount: number;
    amount_paid: number;
    payment_status: string;
    special_requests: string[] | null;
    room: Room | null;
    room_type: RoomType;
    branch: Branch;
    created_at: string;
}

const props = defineProps<{
    reservation: Reservation;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Reservations', href: '/reservations' },
    { title: props.reservation.confirmation_number, href: `/reservations/${props.reservation.id}` },
];

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

const formatDate = (dateStr: string) => {
    return new Date(dateStr).toLocaleDateString('en-US', {
        month: 'long',
        day: 'numeric',
        year: 'numeric',
    });
};

const formatDateTime = (dateStr: string | null) => {
    if (! dateStr) return '-';
    return new Date(dateStr).toLocaleString();
};

const checkIn = () => {
    const roomId = prompt('Enter Room ID to check in:');
    if (roomId) {
        router.post(`/reservations/${props.reservation.id}/check-in`, {
            room_id: Number(roomId),
        });
    }
};

const checkOut = () => {
    if (confirm('Check out this reservation?')) {
        router.post(`/reservations/${props.reservation.id}/check-out`);
    }
};

const cancel = () => {
    if (confirm('Cancel this reservation?')) {
        router.post(`/reservations/${props.reservation.id}/cancel`);
    }
};
</script>

<template>
    <AppLayout :title="reservation.confirmation_number" :breadcrumbs="breadcrumbs">
        <div class="p-6 max-w-4xl mx-auto">
            <div class="mb-6 flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">{{ reservation.confirmation_number }}</h1>
                    <p class="text-gray-500">Guest: {{ reservation.guest_name }}</p>
                </div>
                <div class="flex gap-2">
                    <button
                        v-if="reservation.status === 'confirmed' || reservation.status === 'reserved'"
                        class="px-4 py-2 text-sm text-white bg-green-600 rounded-md hover:bg-green-700"
                        @click="checkIn"
                    >
                        Check In
                    </button>
                    <button
                        v-if="reservation.status === 'checked_in'"
                        class="px-4 py-2 text-sm text-white bg-blue-600 rounded-md hover:bg-blue-700"
                        @click="checkOut"
                    >
                        Check Out
                    </button>
                    <button
                        v-if="reservation.status !== 'checked_out' && reservation.status !== 'cancelled'"
                        class="px-4 py-2 text-sm text-red-600 bg-red-50 rounded-md hover:bg-red-100"
                        @click="cancel"
                    >
                        Cancel
                    </button>
                    <Link
                        :href="`/reservations/${reservation.id}/edit`"
                        class="px-4 py-2 text-sm text-gray-700 bg-gray-100 rounded-md hover:bg-gray-200"
                    >
                        Edit
                    </Link>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Guest Info -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-lg font-semibold mb-4">Guest Information</h2>
                    <dl class="space-y-2">
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Name:</dt>
                            <dd class="font-medium">{{ reservation.guest_name }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Email:</dt>
                            <dd>{{ reservation.guest_email || '-' }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Phone:</dt>
                            <dd>{{ reservation.guest_phone || '-' }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Adults:</dt>
                            <dd>{{ reservation.adults }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Children:</dt>
                            <dd>{{ reservation.children }}</dd>
                        </div>
                    </dl>
                </div>

                <!-- Stay Info -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-lg font-semibold mb-4">Stay Details</h2>
                    <dl class="space-y-2">
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Status:</dt>
                            <dd>
                                <span
                                    class="px-2 py-1 text-xs font-medium rounded-full"
                                    :class="getStatusBadgeClass(reservation.status)"
                                >
                                    {{ reservation.status.replace('_', ' ') }}
                                </span>
                            </dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Room:</dt>
                            <dd>{{ reservation.room?.number || 'Unassigned' }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Room Type:</dt>
                            <dd>{{ reservation.room_type.name }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Check-in:</dt>
                            <dd>{{ formatDate(reservation.check_in_date) }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Check-out:</dt>
                            <dd>{{ formatDate(reservation.check_out_date) }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Actual Check-in:</dt>
                            <dd>{{ formatDateTime(reservation.actual_check_in_at) }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Actual Check-out:</dt>
                            <dd>{{ formatDateTime(reservation.actual_check_out_at) }}</dd>
                        </div>
                    </dl>
                </div>

                <!-- Payment Info -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-lg font-semibold mb-4">Payment</h2>
                    <dl class="space-y-2">
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Room Rate:</dt>
                            <dd>${{ reservation.room_rate }}/night</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Total Amount:</dt>
                            <dd class="font-bold">${{ reservation.total_amount }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Amount Paid:</dt>
                            <dd>${{ reservation.amount_paid }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Payment Status:</dt>
                            <dd>{{ reservation.payment_status }}</dd>
                        </div>
                    </dl>
                </div>

                <!-- Notes -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-lg font-semibold mb-4">Notes</h2>
                    <p class="text-gray-600">{{ reservation.guest_notes || 'No notes' }}</p>
                    <div v-if="reservation.special_requests?.length" class="mt-4">
                        <h3 class="text-sm font-medium text-gray-500 mb-2">Special Requests</h3>
                        <ul class="list-disc list-inside text-gray-600">
                            <li v-for="(request, idx) in reservation.special_requests" :key="idx">
                                {{ request }}
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
