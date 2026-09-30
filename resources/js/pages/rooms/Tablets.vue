<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';

interface Branch { id: number; name: string; }
interface Session {
    id: number;
    room: string | null;
    reservation: string | null;
    device_id: string | null;
    last_active_at: string | null;
    wiped_at: string | null;
}

defineProps<{
    branch: Branch;
    sessions: Session[];
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }, { title: 'Tablets', href: '/rooms' }] } });

const wipe = (id: number) => router.post('/tablet/wipe', { tablet_session_id: id });
</script>

<template>
    <Head title="Room Tablets" />
    <div class="p-6">
        <h1 class="mb-6 text-2xl font-bold text-foreground">Room Tablets</h1>

        <div class="rounded-lg border border-border">
            <table class="w-full caption-bottom text-sm">
                <thead class="bg-muted/50"><tr>
                    <th class="h-12 px-4 text-left font-medium text-muted-foreground">Room</th>
                    <th class="h-12 px-4 text-left font-medium text-muted-foreground">Reservation</th>
                    <th class="h-12 px-4 text-left font-medium text-muted-foreground">Device</th>
                    <th class="h-12 px-4 text-left font-medium text-muted-foreground">Last Active</th>
                    <th class="h-12 px-4 text-left font-medium text-muted-foreground">Status</th>
                    <th class="h-12 px-4 text-right font-medium text-muted-foreground">Actions</th>
                </tr></thead>
                <tbody>
                    <tr v-for="s in sessions" :key="s.id" class="border-t border-border hover:bg-muted/50">
                        <td class="p-4 text-foreground">{{ s.room ?? '—' }}</td>
                        <td class="p-4 font-mono text-xs text-muted-foreground">{{ s.reservation ?? '—' }}</td>
                        <td class="p-4 font-mono text-xs text-muted-foreground">{{ s.device_id ?? '—' }}</td>
                        <td class="p-4 text-muted-foreground">{{ s.last_active_at ?? '—' }}</td>
                        <td class="p-4"><Badge variant="outline">{{ s.wiped_at ? 'wiped' : 'live' }}</Badge></td>
                        <td class="p-4 text-right"><Button v-if="!s.wiped_at" size="sm" variant="outline" @click="wipe(s.id)">Wipe</Button></td>
                    </tr>
                    <tr v-if="sessions.length === 0"><td colspan="6" class="p-4 text-center text-muted-foreground">No tablet sessions. Pair from a room to begin.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
