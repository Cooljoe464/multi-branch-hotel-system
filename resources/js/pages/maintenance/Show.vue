<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Maintenance', href: '/maintenance' },
];

interface Room {
    id: number;
    number: string;
    floor: string;
}

interface User {
    id: number;
    name: string;
}

interface MaintenanceTicket {
    id: number;
    ticket_number: string;
    category: string;
    priority: string;
    status: string;
    title: string;
    description: string;
    resolution_notes: string | null;
    is_room_locked: boolean;
    estimated_cost: number | null;
    actual_cost: number | null;
    room: Room | null;
    reporter: User;
    assignee: User | null;
    created_at: string;
    started_at: string | null;
    completed_at: string | null;
}

const props = defineProps<{
    ticket: MaintenanceTicket;
}>();

const getStatusBadgeClass = (status: string) => {
    const classes: Record<string, string> = {
        open: 'bg-yellow-100 text-yellow-800',
        in_progress: 'bg-blue-100 text-blue-800',
        completed: 'bg-green-100 text-green-800',
    };
    return classes[status] || 'bg-gray-100 text-gray-800';
};

const getPriorityBadgeClass = (priority: string) => {
    const classes: Record<string, string> = {
        low: 'bg-gray-100 text-gray-800',
        normal: 'bg-blue-100 text-blue-800',
        high: 'bg-orange-100 text-orange-800',
        urgent: 'bg-red-100 text-red-800',
    };
    return classes[priority] || 'bg-gray-100 text-gray-800';
};

const formatDate = (dateStr: string | null) => {
    if (! dateStr) return '-';
    return new Date(dateStr).toLocaleString();
};
</script>

<template>
    <AppLayout :title="ticket.ticket_number" :breadcrumbs="breadcrumbs">
        <div class="p-6 max-w-4xl mx-auto">
            <div class="mb-6 flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">{{ ticket.ticket_number }}</h1>
                    <p class="text-gray-500">{{ ticket.title }}</p>
                </div>
                <div class="flex gap-2">
                    <Link
                        href="/maintenance"
                        class="px-4 py-2 text-sm text-gray-700 bg-gray-100 rounded-md hover:bg-gray-200"
                    >
                        Back to List
                    </Link>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-lg font-semibold mb-4">Ticket Details</h2>
                    <dl class="space-y-2">
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Status:</dt>
                            <dd>
                                <span
                                    class="px-2 py-1 text-xs font-medium rounded-full"
                                    :class="getStatusBadgeClass(ticket.status)"
                                >
                                    {{ ticket.status.replace('_', ' ') }}
                                </span>
                            </dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Priority:</dt>
                            <dd>
                                <span
                                    class="px-2 py-1 text-xs font-medium rounded-full"
                                    :class="getPriorityBadgeClass(ticket.priority)"
                                >
                                    {{ ticket.priority }}
                                </span>
                            </dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Category:</dt>
                            <dd>{{ ticket.category }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Room:</dt>
                            <dd>{{ ticket.room?.number || 'N/A' }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Room Locked:</dt>
                            <dd>{{ ticket.is_room_locked ? 'Yes' : 'No' }}</dd>
                        </div>
                    </dl>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-lg font-semibold mb-4">People</h2>
                    <dl class="space-y-2">
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Reported By:</dt>
                            <dd>{{ ticket.reporter.name }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Assigned To:</dt>
                            <dd>{{ ticket.assignee?.name || 'Unassigned' }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Created:</dt>
                            <dd>{{ formatDate(ticket.created_at) }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Started:</dt>
                            <dd>{{ formatDate(ticket.started_at) }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Completed:</dt>
                            <dd>{{ formatDate(ticket.completed_at) }}</dd>
                        </div>
                    </dl>
                </div>

                <div class="bg-white rounded-lg shadow p-6 md:col-span-2">
                    <h2 class="text-lg font-semibold mb-4">Description</h2>
                    <p class="text-gray-600 whitespace-pre-wrap">{{ ticket.description }}</p>
                </div>

                <div v-if="ticket.resolution_notes" class="bg-white rounded-lg shadow p-6 md:col-span-2">
                    <h2 class="text-lg font-semibold mb-4">Resolution Notes</h2>
                    <p class="text-gray-600 whitespace-pre-wrap">{{ ticket.resolution_notes }}</p>
                </div>

                <div v-if="ticket.estimated_cost || ticket.actual_cost" class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-lg font-semibold mb-4">Costs</h2>
                    <dl class="space-y-2">
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Estimated:</dt>
                            <dd>{{ ticket.estimated_cost ? `$${ticket.estimated_cost}` : '-' }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Actual:</dt>
                            <dd>{{ ticket.actual_cost ? `$${ticket.actual_cost}` : '-' }}</dd>
                        </div>
                    </dl>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
