<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ref } from 'vue';

interface LaundryOrder {
    id: number;
    status: string;
    items: any[];
    reservation: { room: { number: string }; guest_name: string };
    attendant: { name: string } | null;
}

const props = defineProps<{ laundryOrder: LaundryOrder }>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Laundry', href: '/laundry' },
            { title: 'Details', href: '#' },
        ],
    },
});

const verifyForm = ref(
    props.laundryOrder.items.map((item: any) => ({
        type: item.type,
        count: item.count,
    })),
);
const verify = () =>
    router.post(`/laundry/${props.laundryOrder.id}/verify`, {
        items: verifyForm.value,
    });
const pickup = () => router.post(`/laundry/${props.laundryOrder.id}/pickup`);
const deliver = () => router.post(`/laundry/${props.laundryOrder.id}/deliver`);
</script>

<template>
    <Head title="Laundry Order Details" />
    <div class="p-6">
        <div class="mb-6">
            <h1 class="text-foreground text-2xl font-bold">
                Laundry Order #{{ laundryOrder.id }}
            </h1>
            <p class="text-muted-foreground">
                Room {{ laundryOrder.reservation.room?.number }} -
                {{ laundryOrder.reservation.guest_name }}
            </p>
        </div>
        <div class="bg-card border-border mb-6 rounded-lg border p-4">
            <h2 class="text-foreground mb-2 font-semibold">Items</h2>
            <div
                v-for="(item, i) in laundryOrder.items"
                :key="i"
                class="flex justify-between py-1 text-sm"
            >
                <span class="text-muted-foreground capitalize">{{
                    item.type
                }}</span
                ><span class="text-foreground">{{ item.count }}</span>
            </div>
        </div>
        <div class="bg-card border-border rounded-lg border p-4">
            <h2 class="text-foreground mb-2 font-semibold">Verify Count</h2>
            <div
                v-for="(item, i) in verifyForm"
                :key="i"
                class="mb-2 flex gap-2"
            >
                <span class="text-muted-foreground w-24 text-sm capitalize">{{
                    item.type
                }}</span>
                <Input
                    v-model.number="item.count"
                    type="number"
                    min="0"
                    class="w-24"
                />
            </div>
            <Button @click="verify" class="mt-2">Verify & Adjust</Button>
        </div>
        <div class="bg-card border-border mt-6 rounded-lg border p-4">
            <h2 class="text-foreground mb-2 font-semibold">Actions</h2>
            <div class="flex gap-2">
                <Button
                    v-if="
                        laundryOrder.status === 'pending' ||
                        laundryOrder.status === 'verified'
                    "
                    @click="pickup"
                    variant="default"
                    >Mark Picked Up</Button
                >
                <Button
                    v-if="
                        laundryOrder.status === 'in_progress' ||
                        laundryOrder.status === 'completed'
                    "
                    @click="deliver"
                    variant="default"
                    >Mark Delivered</Button
                >
            </div>
        </div>
    </div>
</template>
