<script setup lang="ts">
import { computed, reactive } from 'vue';
import { Head, router, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';

interface Room {
    id: number;
    number: string;
    floor: string;
    room_type: { name: string };
}

interface RoomType {
    id: number;
    name: string;
    code: string;
    base_rate: number;
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
    room_type_id: number;
    room_id: number | null;
    special_requests: string[] | null;
    room: Room | null;
    room_type: RoomType;
}

const props = defineProps<{
    reservation: Reservation;
    roomTypes: RoomType[];
    availableRooms: Room[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Reservations', href: '/reservations' },
    { title: 'Edit', href: `/reservations/${props.reservation.id}/edit` },
];

const form = reactive({
    room_type_id: props.reservation.room_type_id,
    room_id: props.reservation.room_id || '',
    guest_name: props.reservation.guest_name,
    guest_email: props.reservation.guest_email || '',
    guest_phone: props.reservation.guest_phone || '',
    guest_notes: props.reservation.guest_notes || '',
    adults: props.reservation.adults,
    children: props.reservation.children,
    check_in_date: props.reservation.check_in_date,
    check_out_date: props.reservation.check_out_date,
});

const filteredRooms = computed(() => {
    if (! form.room_type_id) return props.availableRooms;
    return props.availableRooms.filter(r => r.room_type.id === Number(form.room_type_id));
});

const submit = () => {
    router.put(`/reservations/${props.reservation.id}`, form);
};
</script>

<template>
    <AppLayout title="Edit Reservation" :breadcrumbs="breadcrumbs">
        <div class="p-6 max-w-4xl mx-auto">
            <h1 class="text-2xl font-bold text-gray-900 mb-6">
                Edit Reservation {{ reservation.confirmation_number }}
            </h1>

            <form @submit.prevent="submit" class="space-y-6">
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-lg font-semibold mb-4">Guest Information</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Guest Name</label>
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
                            <label class="block text-sm font-medium text-gray-700">Adults</label>
                            <input v-model="form.adults" type="number" min="1" max="10" required class="mt-1 block w-full rounded-md border-gray-300">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Children</label>
                            <input v-model="form.children" type="number" min="0" max="10" class="mt-1 block w-full rounded-md border-gray-300">
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-lg font-semibold mb-4">Stay Details</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Room Type</label>
                            <select v-model="form.room_type_id" required class="mt-1 block w-full rounded-md border-gray-300">
                                <option v-for="type in roomTypes" :key="type.id" :value="type.id">
                                    {{ type.name }}
                                </option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Room</label>
                            <select v-model="form.room_id" class="mt-1 block w-full rounded-md border-gray-300">
                                <option value="">No room</option>
                                <option v-for="room in filteredRooms" :key="room.id" :value="room.id">
                                    Room {{ room.number }}
                                </option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Check-in Date</label>
                            <input v-model="form.check_in_date" type="date" required class="mt-1 block w-full rounded-md border-gray-300">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Check-out Date</label>
                            <input v-model="form.check_out_date" type="date" required class="mt-1 block w-full rounded-md border-gray-300">
                        </div>
                    </div>
                </div>

                <div class="flex justify-end gap-2">
                    <Link
                        :href="`/reservations/${reservation.id}`"
                        class="px-4 py-2 text-sm text-gray-700 bg-gray-100 rounded-md hover:bg-gray-200"
                    >
                        Cancel
                    </Link>
                    <button
                        type="submit"
                        class="px-4 py-2 text-sm text-white bg-gray-900 rounded-md hover:bg-gray-800"
                    >
                        Save Changes
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
