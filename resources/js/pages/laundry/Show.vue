<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ref } from 'vue';

interface LaundryOrder { id: number; status: string; items: any[]; reservation: { room: { number: string }; guest_name: string }; attendant: { name: string } | null; }

const props = defineProps<{ laundryOrder: LaundryOrder; }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Laundry', href: '/laundry' }, { title: 'Details', href: '#' }] } });

const verifyForm = ref(props.laundryOrder.items.map((item: any) => ({ type: item.type, count: item.count })));
const verify = () => router.post(`/laundry/${props.laundryOrder.id}/verify`, { items: verifyForm.value });
const pickup = () => router.post(`/laundry/${props.laundryOrder.id}/pickup`);
const deliver = () => router.post(`/laundry/${props.laundryOrder.id}/deliver`);
</script>

<template>
    <Head title="Laundry Order Details" />
<div class="p-6">
    <div class="mb-6"><h1 class="text-2xl font-bold text-foreground">Laundry Order #{{ laundryOrder.id }}</h1><p class="text-muted-foreground">Room {{ laundryOrder.reservation.room?.number }} - {{ laundryOrder.reservation.guest_name }}</p></div>
    <div class="bg-card rounded-lg border border-border p-4 mb-6">
        <h2 class="font-semibold text-foreground mb-2">Items</h2>
        <div v-for="(item, i) in laundryOrder.items" :key="i" class="flex justify-between py-1 text-sm"><span class="text-muted-foreground capitalize">{{ item.type }}</span><span class="text-foreground">{{ item.count }}</span></div>
    </div>
    <div class="bg-card rounded-lg border border-border p-4">
        <h2 class="font-semibold text-foreground mb-2">Verify Count</h2>
        <div v-for="(item, i) in verifyForm" :key="i" class="flex gap-2 mb-2">
            <span class="text-sm text-muted-foreground w-24 capitalize">{{ item.type }}</span>
            <Input v-model.number="item.count" type="number" min="0" class="w-24" />
        </div>
        <Button @click="verify" class="mt-2">Verify & Adjust</Button>
    </div>
    <div class="bg-card rounded-lg border border-border p-4 mt-6">
        <h2 class="font-semibold text-foreground mb-2">Actions</h2>
        <div class="flex gap-2">
            <Button v-if="laundryOrder.status === 'pending' || laundryOrder.status === 'verified'" @click="pickup" variant="default">Mark Picked Up</Button>
            <Button v-if="laundryOrder.status === 'in_progress' || laundryOrder.status === 'completed'" @click="deliver" variant="default">Mark Delivered</Button>
        </div>
    </div>
</div>
</template>
