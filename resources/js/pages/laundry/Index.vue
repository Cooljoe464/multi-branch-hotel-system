<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import Pagination from '@/components/ui/pagination/Pagination.vue';

interface LaundryOrder {
    id: number;
    status: string;
    items: any[];
    created_at: string;
    reservation: { room: { number: string }; guest_name: string };
    attendant: { name: string } | null;
}

const props = defineProps<{
    laundryOrders: {
        data: LaundryOrder[];
        current_page: number;
        last_page: number;
        total: number;
    };
    filters: { status?: string };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Laundry', href: '/laundry' },
        ],
    },
});

const statusBadge: Record<string, string> = {
    pending:
        'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300',
    picked_up: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300',
    processing:
        'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-300',
    delivered:
        'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300',
};

const pickup = (id: number) => router.post(`/laundry/${id}/pickup`);
const deliver = (id: number) => router.post(`/laundry/${id}/deliver`);
const goToPage = (page: number) =>
    router.get('/laundry', { page }, { preserveState: true, replace: true });
</script>

<template>
    <Head title="Laundry Orders" />
    <div class="p-6">
        <h1 class="text-foreground mb-6 text-2xl font-bold">Laundry Orders</h1>
        <div class="border-border rounded-lg border">
            <table class="w-full caption-bottom text-sm">
                <thead class="bg-muted/50">
                    <tr>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Room
                        </th>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Guest
                        </th>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Items
                        </th>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Status
                        </th>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Attendant
                        </th>
                        <th
                            class="text-muted-foreground h-12 px-4 text-right font-medium"
                        >
                            Actions
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="order in laundryOrders.data"
                        :key="order.id"
                        class="border-border hover:bg-muted/50 border-t"
                    >
                        <td class="text-foreground p-4 font-medium">
                            {{ order.reservation.room?.number }}
                        </td>
                        <td class="text-muted-foreground p-4">
                            {{ order.reservation.guest_name }}
                        </td>
                        <td class="text-muted-foreground p-4">
                            {{ order.items.length }} types
                        </td>
                        <td class="p-4">
                            <Badge
                                :class="statusBadge[order.status]"
                                variant="outline"
                                class="capitalize"
                                >{{ order.status.replace('_', ' ') }}</Badge
                            >
                        </td>
                        <td class="text-muted-foreground p-4">
                            {{ order.attendant?.name ?? 'Unassigned' }}
                        </td>
                        <td class="p-4 text-right">
                            <div class="flex justify-end gap-1">
                                <Button
                                    v-if="order.status === 'pending'"
                                    variant="outline"
                                    size="sm"
                                    @click="pickup(order.id)"
                                    >Pickup</Button
                                >
                                <Button
                                    v-if="
                                        order.status === 'picked_up' ||
                                        order.status === 'processing'
                                    "
                                    variant="outline"
                                    size="sm"
                                    @click="deliver(order.id)"
                                    >Deliver</Button
                                >
                                <Button
                                    variant="outline"
                                    size="sm"
                                    @click="router.get(`/laundry/${order.id}`)"
                                    >View</Button
                                >
                            </div>
                        </td>
                    </tr>
                    <tr v-if="laundryOrders.data.length === 0">
                        <td
                            colspan="6"
                            class="text-muted-foreground p-4 text-center"
                        >
                            No laundry orders.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="mt-4">
            <Pagination
                :data="laundryOrders"
                label="orders"
                @page-change="goToPage"
            />
        </div>
    </div>
</template>
