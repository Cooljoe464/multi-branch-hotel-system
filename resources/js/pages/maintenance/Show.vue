<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { formatDateTime } from '@/lib/dates';
import { formatCurrency as formatCurrencyRaw } from '@/lib/format';

defineOptions({ layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }, { title: 'Maintenance', href: '/maintenance' }] } });

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

const page = usePage();
const branchSymbol = computed(() => (page.props.branch?.current as any)?.currency_symbol || '$');
const resolveSymbol = (code?: string) => getCurrencySymbol(code || 'NGN') || branchSymbol.value;
const formatCurrency = (amount: number, currencyCode?: string) => formatCurrencyRaw(amount, resolveSymbol(currencyCode));

const props = defineProps<{
    ticket: MaintenanceTicket;
}>();

const startWork = () => router.post(`/maintenance/${props.ticket.id}/start`);
const completeWork = () => {
    const notes = prompt('Resolution notes (optional):');
    if (notes !== null) {
        router.post(`/maintenance/${props.ticket.id}/complete`, { resolution_notes: notes });
    }
};
const lockRoom = () => router.post(`/maintenance/${props.ticket.id}/lock-room`);
const unlockRoom = () => router.post(`/maintenance/${props.ticket.id}/unlock-room`);

const getStatusBadgeClass = (status: string) => {
    const classes: Record<string, string> = {
        open: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200',
        in_progress: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
        completed: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
    };
    return classes[status] || 'bg-muted text-muted-foreground';
};

const getPriorityBadgeClass = (priority: string) => {
    const classes: Record<string, string> = {
        low: 'bg-muted text-muted-foreground',
        normal: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
        high: 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-200',
        urgent: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
    };
    return classes[priority] || 'bg-muted text-muted-foreground';
};
</script>

<template>
    <Head title="Maintenance Ticket" />
    <div class="p-6 max-w-4xl mx-auto">
        <div class="mb-6 flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-foreground">{{ ticket.ticket_number }}</h1>
                    <p class="text-muted-foreground">{{ ticket.title }}</p>
                </div>
                <div class="flex gap-2">
                    <Link
                        href="/maintenance"
                        class="px-4 py-2 text-sm text-muted-foreground bg-muted rounded-md hover:bg-accent"
                    >
                        Back to List
                    </Link>
                    <Button v-if="ticket.status === 'open'" @click="startWork" variant="default" class="bg-blue-600 hover:bg-blue-700">Start Work</Button>
                    <Button v-if="ticket.status === 'in_progress'" @click="completeWork" variant="default" class="bg-green-600 hover:bg-green-700">Complete</Button>
                    <Button v-if="ticket.room && !ticket.is_room_locked" @click="lockRoom" variant="destructive">Lock Room</Button>
                    <Button v-if="ticket.room && ticket.is_room_locked" @click="unlockRoom" variant="default" class="bg-yellow-600 hover:bg-yellow-700">Unlock Room</Button>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="bg-card rounded-lg shadow p-6">
                    <h2 class="text-lg font-semibold mb-4">Ticket Details</h2>
                    <dl class="space-y-2">
                        <div class="flex justify-between">
                            <dt class="text-muted-foreground">Status:</dt>
                            <dd>
                                <Badge :class="getStatusBadgeClass(ticket.status)">
                                    {{ ticket.status.replace('_', ' ') }}
                                </Badge>
                            </dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-muted-foreground">Priority:</dt>
                            <dd>
                                <Badge :class="getPriorityBadgeClass(ticket.priority)">
                                    {{ ticket.priority }}
                                </Badge>
                            </dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-muted-foreground">Category:</dt>
                            <dd>{{ ticket.category }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-muted-foreground">Room:</dt>
                            <dd>{{ ticket.room?.number || 'N/A' }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-muted-foreground">Room Locked:</dt>
                            <dd>{{ ticket.is_room_locked ? 'Yes' : 'No' }}</dd>
                        </div>
                    </dl>
                </div>

                <div class="bg-card rounded-lg shadow p-6">
                    <h2 class="text-lg font-semibold mb-4">People</h2>
                    <dl class="space-y-2">
                        <div class="flex justify-between">
                            <dt class="text-muted-foreground">Reported By:</dt>
                            <dd>{{ ticket.reporter.name }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-muted-foreground">Assigned To:</dt>
                            <dd>{{ ticket.assignee?.name || 'Unassigned' }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-muted-foreground">Created:</dt>
                            <dd>{{ formatDateTime(ticket.created_at) }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-muted-foreground">Started:</dt>
                            <dd>{{ formatDateTime(ticket.started_at) }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-muted-foreground">Completed:</dt>
                            <dd>{{ formatDateTime(ticket.completed_at) }}</dd>
                        </div>
                    </dl>
                </div>

                <div class="bg-card rounded-lg shadow p-6 md:col-span-2">
                    <h2 class="text-lg font-semibold mb-4">Description</h2>
                    <p class="text-muted-foreground whitespace-pre-wrap">{{ ticket.description }}</p>
                </div>

                <div v-if="ticket.resolution_notes" class="bg-card rounded-lg shadow p-6 md:col-span-2">
                    <h2 class="text-lg font-semibold mb-4">Resolution Notes</h2>
                    <p class="text-muted-foreground whitespace-pre-wrap">{{ ticket.resolution_notes }}</p>
                </div>

                <div v-if="ticket.estimated_cost || ticket.actual_cost" class="bg-card rounded-lg shadow p-6">
                    <h2 class="text-lg font-semibold mb-4">Costs</h2>
                    <dl class="space-y-2">
                        <div class="flex justify-between">
                            <dt class="text-muted-foreground">Estimated:</dt>
                            <dd>{{ ticket.estimated_cost ? formatCurrency(ticket.estimated_cost) : '-' }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-muted-foreground">Actual:</dt>
                            <dd>{{ ticket.actual_cost ? formatCurrency(ticket.actual_cost) : '-' }}</dd>
                        </div>
                    </dl>
                </div>
        </div>
    </div>
</template>
