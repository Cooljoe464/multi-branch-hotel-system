<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

interface ChannelProvider { id: number; provider: string; is_active: boolean; last_sync_at: string | null; channel_reservations_count: number; channel_rates_count: number; }

const props = defineProps<{ channelProviders: ChannelProvider[]; }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }, { title: 'Channels', href: '/channels' }] } });

const providerLabel: Record<string, string> = { booking_com: 'Booking.com', expedia: 'Expedia', agoda: 'Agoda' };
const sync = (id: number) => router.post(`/channels/${id}/sync`);
const pull = (id: number) => router.post(`/channels/${id}/pull`);
</script>

<template>
    <Head title="Channel Manager" />
<div class="p-6">
    <h1 class="text-2xl font-bold text-foreground mb-6">Channel Manager</h1>
    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
        <div v-for="provider in channelProviders" :key="provider.id" class="bg-card rounded-lg shadow border border-border p-4">
            <div class="flex items-center justify-between mb-2">
                <span class="font-bold text-foreground text-lg">{{ providerLabel[provider.provider] || provider.provider }}</span>
                <Badge :class="provider.is_active ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300' : 'bg-muted text-muted-foreground'" variant="outline">{{ provider.is_active ? 'Active' : 'Inactive' }}</Badge>
            </div>
            <div class="text-sm text-muted-foreground mb-1">Reservations synced: {{ provider.channel_reservations_count }}</div>
            <div class="text-sm text-muted-foreground mb-1">Mapped rates: {{ provider.channel_rates_count }}</div>
            <div class="text-xs text-muted-foreground mb-3">Last sync: {{ provider.last_sync_at ? new Date(provider.last_sync_at).toLocaleString() : 'Never' }}</div>
            <div class="flex gap-1"><Button variant="outline" size="sm" class="flex-1" @click="sync(provider.id)">Sync Rates</Button><Button variant="outline" size="sm" class="flex-1" @click="pull(provider.id)">Pull Bookings</Button></div>
        </div>
        <div v-if="channelProviders.length === 0" class="col-span-full text-center text-muted-foreground py-12">No channel providers configured.</div>
    </div>
</div>
</template>
