<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';

interface Branch {
    id: number;
    name: string;
    currency_code: string;
}
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

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Forecast', href: '/branches' },
        ],
    },
});

const approve = (id: number) =>
    router.post(
        `/branches/${props.branch.id}/price-recommendations/${id}/approve`,
    );
const reject = (id: number) =>
    router.post(
        `/branches/${props.branch.id}/price-recommendations/${id}/reject`,
    );
const apply = (id: number) =>
    router.post(
        `/branches/${props.branch.id}/price-recommendations/${id}/apply`,
    );

const bar = (p: number) => `${Math.round(p * 100)}%`;
const money = (minor: number) =>
    `${props.branch.currency_code} ${(minor / 100).toLocaleString('en-US')}`;
</script>

<template>
    <Head title="Demand Forecast" />
    <div class="p-6">
        <h1 class="text-foreground mb-6 text-2xl font-bold">
            Demand Forecast — {{ branch.name }}
        </h1>

        <h2 class="text-foreground mb-3 text-lg font-semibold">Next 30 Days</h2>
        <div class="border-border mb-8 rounded-lg border">
            <table class="w-full caption-bottom text-sm">
                <thead class="bg-muted/50">
                    <tr>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Stay Date
                        </th>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Demand
                        </th>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Expected Rooms
                        </th>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Model
                        </th>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Freshness
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="f in forecasts"
                        :key="f.stay_date"
                        class="border-border hover:bg-muted/50 border-t"
                    >
                        <td class="text-foreground p-4">{{ f.stay_date }}</td>
                        <td class="p-4">
                            <div class="flex items-center gap-2">
                                <div class="bg-muted h-2 w-24 rounded">
                                    <div
                                        class="bg-primary h-2 rounded"
                                        :style="{ width: bar(f.p_demand) }"
                                    />
                                </div>
                                <span class="text-foreground">{{
                                    bar(f.p_demand)
                                }}</span>
                            </div>
                        </td>
                        <td class="text-muted-foreground p-4">
                            {{ f.expected_rooms }}
                        </td>
                        <td class="text-muted-foreground p-4 font-mono text-xs">
                            {{ f.model_version }}
                        </td>
                        <td class="p-4">
                            <Badge
                                variant="outline"
                                :class="
                                    f.stale ? 'bg-red-100 text-red-800' : ''
                                "
                                >{{ f.stale ? 'stale' : 'fresh' }}</Badge
                            >
                        </td>
                    </tr>
                    <tr v-if="forecasts.length === 0">
                        <td
                            colspan="5"
                            class="text-muted-foreground p-4 text-center"
                        >
                            No forecasts yet — the nightly job generates them.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <h2 class="text-foreground mb-3 text-lg font-semibold">
            Open Proposals
        </h2>
        <div class="border-border rounded-lg border">
            <table class="w-full caption-bottom text-sm">
                <thead class="bg-muted/50">
                    <tr>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Stay
                        </th>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Room Type
                        </th>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Current
                        </th>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Recommended
                        </th>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Δ
                        </th>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Status
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
                        v-for="p in proposals"
                        :key="p.id"
                        class="border-border hover:bg-muted/50 border-t"
                    >
                        <td class="text-foreground p-4">{{ p.stay_date }}</td>
                        <td class="text-muted-foreground p-4">
                            {{ p.room_type }}
                        </td>
                        <td class="text-muted-foreground p-4">
                            {{ money(p.current_minor) }}
                        </td>
                        <td class="text-foreground p-4 font-semibold">
                            {{ money(p.recommended_minor) }}
                        </td>
                        <td class="text-muted-foreground p-4">
                            {{ (p.deviation_bps / 100).toFixed(1) }}%
                        </td>
                        <td class="p-4">
                            <Badge variant="outline">{{ p.status }}</Badge>
                        </td>
                        <td class="p-4">
                            <div
                                v-if="p.status === 'proposed'"
                                class="flex justify-end gap-2"
                            >
                                <Button
                                    size="sm"
                                    variant="outline"
                                    @click="approve(p.id)"
                                    >Approve</Button
                                >
                                <Button
                                    size="sm"
                                    variant="outline"
                                    @click="reject(p.id)"
                                    >Reject</Button
                                >
                            </div>
                            <div
                                v-else-if="p.status === 'approved'"
                                class="flex justify-end"
                            >
                                <Button size="sm" @click="apply(p.id)"
                                    >Apply</Button
                                >
                            </div>
                        </td>
                    </tr>
                    <tr v-if="proposals.length === 0">
                        <td
                            colspan="7"
                            class="text-muted-foreground p-4 text-center"
                        >
                            No open proposals.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
