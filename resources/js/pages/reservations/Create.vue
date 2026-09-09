<script setup lang="ts">
import { Head, router, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Reservations', href: '/reservations' },
    { title: 'New Reservation', href: '/reservations/create' },
];

const props = defineProps<{
    roomTypes: Array<{ id: number; name: string; code: string; base_rate: number }>;
    availableRooms: Array<{ id: number; number: string; floor: string; room_type: { name: string } }>;
    prefilledDate: string | null;
}>();

import { computed, reactive } from 'vue';

const form = reactive({
    room_type_id: '',
    room_id: '',
    guest_name: '',
    guest_email: '',
    guest_phone: '',
    guest_notes: '',
    adults: 1,
    children: 0,
    check_in_date: props.prefilledDate || new Date().toISOString().split('T')[0],
    check_out_date: '',
    special_requests: [],
    is_group_booking: false,
    group_id: '',
});

const calculateTotal = () => {
    if (! form.check_in_date || ! form.check_out_date) return 0;
    const type = props.roomTypes.find(t => t.id === Number(form.room_type_id));
    if (! type) return 0;
    const nights = Math.ceil(
        (new Date(form.check_out_date).getTime() - new Date(form.check_in_date).getTime()) / (1000 * 60 * 60 * 24)
    );
    return type.base_rate * Math.max(nights, 1);
};

const filteredRooms = computed(() => {
    if (! form.room_type_id) return props.availableRooms;
    return props.availableRooms.filter(r => r.room_type.id === Number(form.room_type_id));
});

const submit = () => {
    router.post('/reservations', form);
};
</script>

<template>
    <AppLayout title="New Reservation" :breadcrumbs="breadcrumbs">
        <div class="p-6 max-w-4xl mx-auto">
            <h1 class="text-2xl font-bold text-gray-900 mb-6">New Reservation</h1>

            <form @submit.prevent="submit" class="space-y-6">
                <!-- Guest Information -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-lg font-semibold mb-4">Guest Information</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Guest Name *</label>
                            <input v-model="form.guest_name" type="text" required class="mt-1 block w-full rounded-md border-gray-300">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Email</label>
                            <input v-model="form.guest_email" type="email" class="mt-1 block w-full rounded-md border-gray-300">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Phone</label>
                            <input v-model="form.guest_phone" type="tel" class="mt-1 block w-full rounded-md border-gray-300">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Adults *</label>
                            <input v-model="form.adults" type="number" min="1" max="10" required class="mt-1 block w-full rounded-md border-gray-300">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Children</label>
                            <input v-model="form.children" type="number" min="0" max="10" class="mt-1 block w-full rounded-md border-gray-300">
                        </div>
                    </div>
                    <div class="mt-4">
                        <label class="block text-sm font-medium text-gray-700">Guest Notes</label>
                        <textarea v-model="form.guest_notes" rows="2" class="mt-1 block w-full rounded-md border-gray-300"></textarea>
                    </div>
                </div>

                <!-- Stay Details -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-lg font-semibold mb-4">Stay Details</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Room Type *</label>
                            <select v-model="form.room_type_id" required class="mt-1 block w-full rounded-md border-gray-300">
                                <option value="">Select room type</option>
                                <option v-for="type in roomTypes" :key="type.id" :value="type.id">
                                    {{ type.name }} - ${{ type.base_rate }}/night
                                </option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Room</label>
                            <select v-model="form.room_id" class="mt-1 block w-full rounded-md border-gray-300">
                                <option value="">No room assigned</option>
                                <option v-for="room in filteredRooms" :key="room.id" :value="room.id">
                                    Room {{ room.number }} ({{ room.room_type.name }})
                                </option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Check-in Date *</label>
                            <input v-model="form.check_in_date" type="date" required class="mt-1 block w-full rounded-md border-gray-300">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Check-out Date *</label>
                            <input v-model="form.check_out_date" type="date" required class="mt-1 block w-full rounded-md border-gray-300">
                        </div>
                    </div>
                </div>

                <!-- Total -->
                <div class="bg-gray-50 rounded-lg p-6">
                    <div class="flex justify-between items-center">
                        <span class="text-lg font-medium text-gray-900">Total Amount:</span>
                        <span class="text-2xl font-bold text-gray-900">${{ calculateTotal() }}</span>
                    </div>
                </div>

                <!-- Actions -->
                <div class="flex justify-end gap-2">
                    <Link
                        href="/reservations"
                        class="px-4 py-2 text-sm text-gray-700 bg-gray-100 rounded-md hover:bg-gray-200"
                    >
                        Cancel
                    </Link>
                    <button
                        type="submit"
                        class="px-4 py-2 text-sm text-white bg-gray-900 rounded-md hover:bg-gray-800"
                    >
                        Create Reservation
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
