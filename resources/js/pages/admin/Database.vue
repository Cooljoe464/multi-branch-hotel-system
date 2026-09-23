<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';

interface Replica { host: string; lag_seconds: number | null; stale_threshold: number; }
interface TableStatus { partitioned: boolean; partitions: number; blockers: string[]; }
interface Drill { drill_date: string; mode: string; status: string; rto_minutes: number | null; rpo_minutes: number | null; }

const props = defineProps<{
    replica: Replica;
    partitions: Record<string, TableStatus>;
    last_drill: Drill | null;
    drill_fresh: boolean;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }, { title: 'Database', href: '/admin/system-health' }] } });

const runDrill = () => router.post('/admin/database/drill');
</script>

<template>
    <Head title="Database" />
    <div class="p-6">
        <div class="mb-6 flex items-center justify-between">
            <h1 class="text-2xl font-bold text-foreground">Database & Recovery</h1>
            <Button @click="runDrill">Run DR Drill</Button>
        </div>

        <h2 class="mb-3 text-lg font-semibold text-foreground">Read Replica</h2>
        <div class="mb-8 rounded-lg border border-border p-4 text-sm">
            <p class="text-muted-foreground">Host: <span class="font-mono text-foreground">{{ replica.host }}</span></p>
            <p class="text-muted-foreground">Lag:
                <span class="font-medium text-foreground">{{ replica.lag_seconds === null ? 'unknown (primary or unreachable)' : `${replica.lag_seconds}s` }}</span>
                <Badge v-if="replica.lag_seconds !== null && replica.lag_seconds > replica.stale_threshold" variant="outline" class="ml-2 bg-red-100 text-red-800">stale</Badge>
            </p>
        </div>

        <h2 class="mb-3 text-lg font-semibold text-foreground">Partitions</h2>
        <div class="mb-8 rounded-lg border border-border">
            <table class="w-full caption-bottom text-sm">
                <thead class="bg-muted/50"><tr>
                    <th class="h-12 px-4 text-left font-medium text-muted-foreground">Table</th>
                    <th class="h-12 px-4 text-left font-medium text-muted-foreground">Partitioned</th>
                    <th class="h-12 px-4 text-left font-medium text-muted-foreground">Children</th>
                    <th class="h-12 px-4 text-left font-medium text-muted-foreground">Blockers</th>
                </tr></thead>
                <tbody>
                    <tr v-for="(status, table) in partitions" :key="table" class="border-t border-border">
                        <td class="p-4 font-mono text-foreground">{{ table }}</td>
                        <td class="p-4"><Badge variant="outline">{{ status.partitioned ? 'yes' : 'no' }}</Badge></td>
                        <td class="p-4 text-muted-foreground">{{ status.partitions }}</td>
                        <td class="p-4 text-xs text-muted-foreground">{{ status.blockers.length > 0 ? status.blockers.join('; ') : '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <h2 class="mb-3 text-lg font-semibold text-foreground">Disaster Recovery</h2>
        <div class="rounded-lg border border-border p-4 text-sm">
            <p v-if="last_drill" class="text-muted-foreground">
                Last drill: <span class="font-medium text-foreground">{{ last_drill.drill_date }}</span>
                ({{ last_drill.mode }}, {{ last_drill.status }},
                RTO {{ last_drill.rto_minutes ?? '—' }} min, RPO {{ last_drill.rpo_minutes ?? '—' }} min)
                <Badge variant="outline" class="ml-2" :class="drill_fresh ? '' : 'bg-red-100 text-red-800'">{{ drill_fresh ? 'fresh' : 'stale' }}</Badge>
            </p>
            <p v-else class="text-muted-foreground">No drill recorded yet.</p>
        </div>
    </div>
</template>
