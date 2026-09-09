<script setup lang="ts">
import { router, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { reactive } from 'vue';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Maintenance', href: '/maintenance' },
    { title: 'New Ticket', href: '/maintenance/create' },
];

const props = defineProps<{
    rooms: Array<{ id: number; number: string; floor: string }>;
}>();

const form = reactive({
    room_id: '',
    category: 'plumbing',
    priority: 'normal',
    title: '',
    description: '',
    is_room_locked: false,
    estimated_cost: '',
});

const submit = () => {
    router.post('/maintenance', {
        ...form,
        estimated_cost: form.estimated_cost ? Number(form.estimated_cost) : null,
    });
};
</script>

<template>
    <AppLayout title="New Maintenance Ticket" :breadcrumbs="breadcrumbs">
        <div class="p-6 max-w-2xl mx-auto">
            <h1 class="text-2xl font-bold text-gray-900 mb-6">New Maintenance Ticket</h1>

            <form @submit.prevent="submit" class="space-y-6">
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Room</label>
                            <select v-model="form.room_id" class="mt-1 block w-full rounded-md border-gray-300">
                                <option value="">No room (general issue)</option>
                                <option v-for="room in rooms" :key="room.id" :value="room.id">
                                    Room {{ room.number }} (Floor {{ room.floor }})
                                </option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Title *</label>
                            <input v-model="form.title" type="text" required class="mt-1 block w-full rounded-md border-gray-300" placeholder="Brief description of the issue">
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Category *</label>
                                <select v-model="form.category" class="mt-1 block w-full rounded-md border-gray-300">
                                    <option value="plumbing">Plumbing</option>
                                    <option value="electrical">Electrical</option>
                                    <option value="hvac">HVAC</option>
                                    <option value="furniture">Furniture</option>
                                    <option value="appliance">Appliance</option>
                                    <option value="structural">Structural</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Priority *</label>
                                <select v-model="form.priority" class="mt-1 block w-full rounded-md border-gray-300">
                                    <option value="low">Low</option>
                                    <option value="normal">Normal</option>
                                    <option value="high">High</option>
                                    <option value="urgent">Urgent</option>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Description *</label>
                            <textarea v-model="form.description" rows="4" required class="mt-1 block w-full rounded-md border-gray-300" placeholder="Detailed description of the issue..."></textarea>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Estimated Cost ($)</label>
                                <input v-model="form.estimated_cost" type="number" min="0" class="mt-1 block w-full rounded-md border-gray-300">
                            </div>
                            <div class="flex items-end">
                                <label class="flex items-center gap-2">
                                    <input v-model="form.is_room_locked" type="checkbox" class="rounded border-gray-300">
                                    <span class="text-sm font-medium text-gray-700">Lock room for maintenance</span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex justify-end gap-2">
                    <Link
                        href="/maintenance"
                        class="px-4 py-2 text-sm text-gray-700 bg-gray-100 rounded-md hover:bg-gray-200"
                    >
                        Cancel
                    </Link>
                    <button
                        type="submit"
                        class="px-4 py-2 text-sm text-white bg-gray-900 rounded-md hover:bg-gray-800"
                    >
                        Create Ticket
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
