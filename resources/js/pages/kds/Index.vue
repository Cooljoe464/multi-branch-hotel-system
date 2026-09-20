<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

interface KotItem { id: number; item_name: string; quantity: number; status: string; priority: string; notes: string | null; outlet: string; created_at: string; pos_charge?: { reservation?: { room?: { number: string }; guest_name: string } }; }

const props = defineProps<{ kotItems: KotItem[]; filters: { status?: string; outlet?: string; }; }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }, { title: 'KDS', href: '/kds' }] } });

const statusColor: Record<string, string> = { pending: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300', preparing: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300', ready: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300', served: 'bg-muted text-muted-foreground', cancelled: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300' };

const updateStatus = (item: KotItem, status: string) => { router.patch(`/kds/items/${item.id}/status`, { status }); };

let echo: any = null;

onMounted(() => {
    if (typeof window !== 'undefined' && typeof window.initEcho === 'function') {
        echo = window.initEcho();
        const channel = echo.channel(`branch.${props.filters?.outlet || 'kds'}.kds`);
        channel.listen('.kot.updated', (data: any) => {
            const idx = props.kotItems.findIndex((k: KotItem) => k.id === data.id);
            if (idx >= 0) {
                props.kotItems[idx].status = data.status;
            } else {
                props.kotItems.unshift({
                    id: data.id,
                    item_name: data.item_name,
                    quantity: data.quantity,
                    status: data.status,
                    priority: data.priority,
                    notes: null,
                    outlet: data.outlet,
                    created_at: new Date().toISOString(),
                });
            }
        });
    }
});

onUnmounted(() => {
    if (echo) echo.disconnect();
});
</script>

<template>
    <Head title="Kitchen Display System" />
<div class="p-6">
    <h1 class="text-2xl font-bold text-foreground mb-6">Kitchen Display System</h1>
    <div v-if="kotItems.length === 0" class="text-center text-muted-foreground py-12">No orders in queue.</div>
    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
        <div v-for="item in kotItems" :key="item.id" class="bg-card rounded-lg shadow border border-border p-4" :class="{ 'border-red-500': item.priority === 'rush' }">
            <div class="flex items-center justify-between mb-2">
                <span class="font-bold text-foreground">{{ item.item_name }}</span>
                <Badge :class="statusColor[item.status]" variant="outline" class="capitalize">{{ item.status }}</Badge>
            </div>
            <div class="text-sm text-muted-foreground mb-1">Qty: {{ item.quantity }}</div>
            <div class="text-sm text-muted-foreground mb-1">Outlet: {{ item.outlet }}</div>
            <div v-if="item.pos_charge?.reservation" class="text-xs text-muted-foreground mb-2">
                Room {{ item.pos_charge.reservation.room?.number }} - {{ item.pos_charge.reservation.guest_name }}
            </div>
            <div v-if="item.notes" class="text-xs text-muted-foreground italic mb-2">{{ item.notes }}</div>
            <div v-if="item.priority === 'rush'" class="text-xs text-red-600 dark:text-red-400 font-bold mb-2">RUSH</div>
            <div class="flex gap-1">
                <Button v-if="item.status === 'pending'" variant="outline" size="sm" class="flex-1" @click="updateStatus(item, 'preparing')">Start</Button>
                <Button v-if="item.status === 'preparing'" variant="outline" size="sm" class="flex-1" @click="updateStatus(item, 'ready')">Ready</Button>
                <Button v-if="item.status === 'ready'" variant="outline" size="sm" class="flex-1" @click="updateStatus(item, 'served')">Served</Button>
                <Button v-if="item.status !== 'cancelled' && item.status !== 'served'" variant="destructive" size="sm" @click="updateStatus(item, 'cancelled')">×</Button>
            </div>
        </div>
    </div>
</div>
</template>
