<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';

interface Branch { id: number; name: string; currency_code: string; }
interface ForecastRow {
    stay_date: string;
    p_demand: number;
    expected_rooms: number;
    model_version: string;
    generated_on: string;
    stale: boolean;
}
interface Proposal {
    id: number;
    stay_date: string;
    room_type: string;
    recommended_minor: number;
    current_minor: number;
    deviation_bps: number;
    status: string;
}

const props = defineProps<{
    branch: Branch;
    from: string;
    to: string;
    forecasts: ForecastRow[];
    proposals: Proposal[];
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }, { title: 'Forecast', href: '/branches' }] } });

const approve = (id: number) => router.post(`/branches/${props.branch.id}/price-recommendations/${id}/approve`);
const reject = (id: number) => router.post(`/branches/${props.branch.id}/price-recommendations/${id}/reject`);
const apply = (id: number) => router.post(`/branches/${props.branch.id}/price-recommendations/${id}/apply`);

const bar = (p: number) => `${Math.round(p * 100)}%`;
const money = (minor: number) => `${props.branch.currency_code} ${(minor / 100).toLocaleString('en-US')}`;
</script>

<template>
    <Head title="Demand Forecast" />
    <div class="p-6">
        <h1 class="mb-6 text-2xl font-bold text-foreground">Demand Forecast — {{ branch.name }}</h1>

        <h2 class="mb-3 text-lg font-semibold text-foreground">Next 30 Days</h2>
        <div class="mb-8 rounded-lg border border-border">
            <table class="w-full caption-bottom text-sm">
                <thead class="bg-muted/50"><tr>
                    <th class="h-12 px-4 text-left font-medium text-muted-foreground">Stay Date</th>
                    <th class="h-12 px-4 text-left font-medium text-muted-foreground">Demand</th>
                    <th class="h-12 px-4 text-left font-medium text-muted-foreground">Expected Rooms</th>
                    <th class="h-12 px-4 text-left font-medium text-muted-foreground">Model</th>
                    <th class="h-12 px-4 text-left font-medium text-muted-foreground">Freshness</th>
                </tr></thead>
                <tbody>
                    <tr v-for="f in forecasts" :key="f.stay_date" class="border-t border-border hover:bg-muted/50">
                        <td class="p-4 text-foreground">{{ f.stay_date }}</td>
                        <td class="p-4">
                            <div class="flex items-center gap-2">
                                <div class="h-2 w-24 rounded bg-muted"><div class="h-2 rounded bg-primary" :style="{ width: bar(f.p_demand) }" /></div>
                                <span class="text-foreground">{{ bar(f.p_demand) }}</span>
                            </div>
                        </td>
                        <td class="p-4 text-muted-foreground">{{ f.expected_rooms }}</td>
                        <td class="p-4 font-mono text-xs text-muted-foreground">{{ f.model_version }}</td>
                        <td class="p-4"><Badge variant="outline" :class="f.stale ? 'bg-red-100 text-red-800' : ''">{{ f.stale ? 'stale' : 'fresh' }}</Badge></td>
                    </tr>
                    <tr v-if="forecasts.length === 0"><td colspan="5" class="p-4 text-center text-muted-foreground">No forecasts yet — the nightly job generates them.</td></tr>
                </tbody>
            </table>
        </div>

        <h2 class="mb-3 text-lg font-semibold text-foreground">Open Proposals</h2>
        <div class="rounded-lg border border-border">
            <table class="w-full caption-bottom text-sm">
                <thead class="bg-muted/50"><tr>
                    <th class="h-12 px-4 text-left font-medium text-muted-foreground">Stay</th>
                    <th class="h-12 px-4 text-left font-medium text-muted-foreground">Room Type</th>
                    <th class="h-12 px-4 text-left font-medium text-muted-foreground">Current</th>
                    <th class="h-12 px-4 text-left font-medium text-muted-foreground">Recommended</th>
                    <th class="h-12 px-4 text-left font-medium text-muted-foreground">Δ</th>
                    <th class="h-12 px-4 text-left font-medium text-muted-foreground">Status</th>
                    <th class="h-12 px-4 text-right font-medium text-muted-foreground">Actions</th>
                </tr></thead>
                <tbody>
                    <tr v-for="p in proposals" :key="p.id" class="border-t border-border hover:bg-muted/50">
                        <td class="p-4 text-foreground">{{ p.stay_date }}</td>
                        <td class="p-4 text-muted-foreground">{{ p.room_type }}</td>
                        <td class="p-4 text-muted-foreground">{{ money(p.current_minor) }}</td>
                        <td class="p-4 font-semibold text-foreground">{{ money(p.recommended_minor) }}</td>
                        <td class="p-4 text-muted-foreground">{{ (p.deviation_bps / 100).toFixed(1) }}%</td>
                        <td class="p-4"><Badge variant="outline">{{ p.status }}</Badge></td>
                        <td class="p-4"><div v-if="p.status === 'proposed'" class="flex justify-end gap-2">
                            <Button size="sm" variant="outline" @click="approve(p.id)">Approve</Button>
                            <Button size="sm" variant="outline" @click="reject(p.id)">Reject</Button>
                        </div>
                        <div v-else-if="p.status === 'approved'" class="flex justify-end">
                            <Button size="sm" @click="apply(p.id)">Apply</Button>
                        </div></td>
                    </tr>
                    <tr v-if="proposals.length === 0"><td colspan="7" class="p-4 text-center text-muted-foreground">No open proposals.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
