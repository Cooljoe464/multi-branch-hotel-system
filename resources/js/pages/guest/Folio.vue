<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

interface Guest {
    id: number;
    first_name: string;
    last_name: string;
    email: string;
    phone: string | null;
    vip_status: string;
    total_stays: number;
    total_nights: number;
    total_spent: number;
}

interface Branch {
    id: number;
    name: string;
    address: string | null;
    city: string;
    phone: string | null;
    email: string | null;
}

interface RoomType {
    id: number;
    name: string;
}

interface Room {
    id: number;
    number: string;
    floor: string | null;
}

interface Reservation {
    id: number;
    confirmation_number: string;
    status: string;
    guest_name: string;
    guest_email: string | null;
    guest_phone: string | null;
    adults: number;
    children: number;
    check_in_date: string;
    check_out_date: string;
    actual_check_in_at: string | null;
    actual_check_out_at: string | null;
    room_rate: number;
    total_amount: number;
    amount_paid: number;
    payment_status: string;
    special_requests: string[] | null;
    branch: Branch;
    room: Room | null;
    room_type: RoomType;
    guest: Guest | null;
    nights: number;
}

const props = defineProps<{
    reservation: Reservation;
    guest: Guest | null;
}>();

const formatCurrency = (amount: number) => {
    return `$${(amount / 100).toFixed(2)}`;
};

const formatDate = (dateStr: string) => {
    return new Date(dateStr).toLocaleDateString('en-US', {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    });
};

const formatDateTime = (dateStr: string | null) => {
    if (! dateStr) return '-';
    return new Date(dateStr).toLocaleString();
};

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

const getVipBadgeClass = (status: string) => {
    const classes: Record<string, string> = {
        none: '',
        silver: 'bg-gray-100 text-gray-800',
        gold: 'bg-yellow-100 text-yellow-800',
        platinum: 'bg-purple-100 text-purple-800',
        diamond: 'bg-blue-100 text-blue-800',
    };
    return classes[status] || '';
};
</script>

<template>
    <Head :title="`Folio - ${reservation.confirmation_number}`" />

    <div class="min-h-screen bg-gray-50">
        <!-- Header -->
        <header class="bg-white shadow">
            <div class="max-w-4xl mx-auto px-4 py-6">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900">{{ reservation.branch.name }}</h1>
                        <p class="text-gray-600">{{ reservation.branch.city }}</p>
                    </div>
                    <div class="text-right">
                        <p class="text-sm text-gray-500">Confirmation</p>
                        <p class="font-mono font-bold text-lg">{{ reservation.confirmation_number }}</p>
                    </div>
                </div>
            </div>
        </header>

        <main class="max-w-4xl mx-auto px-4 py-8">
            <!-- Status Banner -->
            <div class="mb-6 p-4 rounded-lg" :class="{
                'bg-green-50 border border-green-200': reservation.status === 'checked_in',
                'bg-blue-50 border border-blue-200': reservation.status === 'confirmed' || reservation.status === 'reserved',
                'bg-gray-50 border border-gray-200': reservation.status === 'checked_out',
            }">
                <div class="flex items-center justify-between">
                    <div>
                        <span
                            class="px-3 py-1 text-sm font-medium rounded-full"
                            :class="getStatusBadgeClass(reservation.status)"
                        >
                            {{ reservation.status.replace('_', ' ').toUpperCase() }}
                        </span>
                        <span v-if="guest?.vip_status !== 'none'" class="ml-2 px-3 py-1 text-sm font-medium rounded-full" :class="getVipBadgeClass(guest.vip_status)">
                            {{ guest.vip_status.toUpperCase() }} VIP
                        </span>
                    </div>
                    <div class="text-right">
                        <p class="text-sm text-gray-500">Guest</p>
                        <p class="font-medium">{{ reservation.guest_name }}</p>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Stay Details -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-lg font-semibold mb-4">Stay Details</h2>
                    <dl class="space-y-3">
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Room Type</dt>
                            <dd class="font-medium">{{ reservation.room_type.name }}</dd>
                        </div>
                        <div v-if="reservation.room" class="flex justify-between">
                            <dt class="text-gray-500">Room Number</dt>
                            <dd class="font-medium">{{ reservation.room.number }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Check-in</dt>
                            <dd>{{ formatDate(reservation.check_in_date) }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Check-out</dt>
                            <dd>{{ formatDate(reservation.check_out_date) }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Nights</dt>
                            <dd>{{ reservation.nights }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Guests</dt>
                            <dd>{{ reservation.adults }} adults, {{ reservation.children }} children</dd>
                        </div>
                    </dl>
                </div>

                <!-- Contact Info -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-lg font-semibold mb-4">Contact Information</h2>
                    <dl class="space-y-3">
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Email</dt>
                            <dd>{{ reservation.guest_email || '-' }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Phone</dt>
                            <dd>{{ reservation.guest_phone || '-' }}</dd>
                        </div>
                        <div v-if="reservation.branch.phone" class="flex justify-between">
                            <dt class="text-gray-500">Property Phone</dt>
                            <dd>{{ reservation.branch.phone }}</dd>
                        </div>
                        <div v-if="reservation.branch.email" class="flex justify-between">
                            <dt class="text-gray-500">Property Email</dt>
                            <dd>{{ reservation.branch.email }}</dd>
                        </div>
                        <div v-if="reservation.branch.address" class="flex justify-between">
                            <dt class="text-gray-500">Address</dt>
                            <dd class="text-right">{{ reservation.branch.address }}</dd>
                        </div>
                    </dl>
                </div>

                <!-- Financial Summary -->
                <div class="bg-white rounded-lg shadow p-6 md:col-span-2">
                    <h2 class="text-lg font-semibold mb-4">Financial Summary</h2>
                    <dl class="space-y-3">
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Room Rate</dt>
                            <dd>{{ formatCurrency(reservation.room_rate) }} / night</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Subtotal ({{ reservation.nights }} nights)</dt>
                            <dd>{{ formatCurrency(reservation.room_rate * reservation.nights) }}</dd>
                        </div>
                        <div class="flex justify-between text-lg font-semibold pt-3 border-t">
                            <dt>Total</dt>
                            <dd>{{ formatCurrency(reservation.total_amount) }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Amount Paid</dt>
                            <dd class="text-green-600">{{ formatCurrency(reservation.amount_paid) }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Balance Due</dt>
                            <dd class="font-medium">{{ formatCurrency(reservation.total_amount - reservation.amount_paid) }}</dd>
                        </div>
                    </dl>
                </div>

                <!-- Timeline -->
                <div class="bg-white rounded-lg shadow p-6 md:col-span-2">
                    <h2 class="text-lg font-semibold mb-4">Timeline</h2>
                    <dl class="space-y-3">
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Booked On</dt>
                            <dd>{{ formatDateTime(reservation.created_at) }}</dd>
                        </div>
                        <div v-if="reservation.actual_check_in_at" class="flex justify-between">
                            <dt class="text-gray-500">Actual Check-in</dt>
                            <dd>{{ formatDateTime(reservation.actual_check_in_at) }}</dd>
                        </div>
                        <div v-if="reservation.actual_check_out_at" class="flex justify-between">
                            <dt class="text-gray-500">Actual Check-out</dt>
                            <dd>{{ formatDateTime(reservation.actual_check_out_at) }}</dd>
                        </div>
                    </dl>
                </div>

                <!-- Guest Profile (if linked) -->
                <div v-if="guest && guest.total_stays > 0" class="bg-white rounded-lg shadow p-6 md:col-span-2">
                    <h2 class="text-lg font-semibold mb-4">Guest Profile</h2>
                    <div class="grid grid-cols-3 gap-4 text-center">
                        <div>
                            <p class="text-2xl font-bold text-gray-900">{{ guest.total_stays }}</p>
                            <p class="text-sm text-gray-500">Total Stays</p>
                        </div>
                        <div>
                            <p class="text-2xl font-bold text-gray-900">{{ guest.total_nights }}</p>
                            <p class="text-sm text-gray-500">Total Nights</p>
                        </div>
                        <div>
                            <p class="text-2xl font-bold text-gray-900">{{ formatCurrency(guest.total_spent) }}</p>
                            <p class="text-sm text-gray-500">Total Spent</p>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</template>
