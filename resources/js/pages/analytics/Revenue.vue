<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

interface PaceRow {
    stay_date: string;
    sold_now: number;
    sold_then: number;
    pickup: number;
    revenue_now_minor: number;
    revenue_then_minor: number;
}

interface BudgetRow {
    month: string;
    room_nights_target: number;
    room_nights_otb: number;
    nights_variance: number;
    revenue_target_minor: number;
    revenue_otb_minor: number;
    revenue_variance_minor: number;
}

interface CalendarRow {
    stay_date: string;
    rooms_available: number;
    rooms_sold: number;
    occupancy_bps: number;
    room_revenue_minor: number;
}

interface Metrics {
    adr_minor: number;
    revpar_minor: number;
    trevpar_minor: number;
    goppar_minor: number;
    occupancy_bps: number;
    rooms_available: number;
    rooms_sold: number;
    room_revenue_minor: number;
    total_revenue_minor: number;
    pace: PaceRow[];
    budgets: BudgetRow[];
    calendar: CalendarRow[];
    by_segment: Record<string, { nights: number; revenue_minor: number }>;
    by_source: Record<string, { nights: number; revenue_minor: number }>;
}

interface Budget {
    id: number;
    month: string;
    room_nights_target: number;
    revenue_target_minor: number;
}

const props = defineProps<{
    branch: { id: number; name: string; code: string };
    filters: { from: string; to: string };
    metrics: Metrics;
    budgets: Budget[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Revenue', href: '#' },
        ],
    },
});

const filterForm = useForm({ from: props.filters.from, to: props.filters.to });
const budgetForm = useForm({
    month: '',
    room_nights_target: 0,
    revenue_target_minor: 0,
});

const base = `/branches/${props.branch.id}/revenue`;

function money(minor: number): string {
    return (minor / 100).toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

function pct(bps: number): string {
    return `${(bps / 100).toFixed(1)}%`;
}
</script>

<template>
    <Head title="Revenue Analytics" />
    <div class="space-y-8 p-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold">
                    Revenue — {{ branch.name }}
                </h1>
                <p class="text-muted-foreground text-sm">
                    ADR, RevPAR, pace and OTB vs budget. Minor units throughout.
                </p>
            </div>
            <form
                @submit.prevent="filterForm.get(base, { preserveState: true })"
                class="flex items-end gap-2"
            >
                <div class="grid gap-2">
                    <Label>From</Label
                    ><Input v-model="filterForm.from" type="date" />
                </div>
                <div class="grid gap-2">
                    <Label>To</Label
                    ><Input v-model="filterForm.to" type="date" />
                </div>
                <Button type="submit">Apply</Button>
            </form>
        </div>

        <section class="grid grid-cols-2 gap-3 md:grid-cols-5">
            <div class="rounded-lg border p-4">
                <div class="text-muted-foreground text-xs">ADR</div>
                <div class="text-xl font-semibold">
                    {{ money(metrics.adr_minor) }}
                </div>
            </div>
            <div class="rounded-lg border p-4">
                <div class="text-muted-foreground text-xs">RevPAR</div>
                <div class="text-xl font-semibold">
                    {{ money(metrics.revpar_minor) }}
                </div>
            </div>
            <div class="rounded-lg border p-4">
                <div class="text-muted-foreground text-xs">TRevPAR</div>
                <div class="text-xl font-semibold">
                    {{ money(metrics.trevpar_minor) }}
                </div>
            </div>
            <div class="rounded-lg border p-4">
                <div class="text-muted-foreground text-xs">GOPPAR</div>
                <div class="text-xl font-semibold">
                    {{ money(metrics.goppar_minor) }}
                </div>
            </div>
            <div class="rounded-lg border p-4">
                <div class="text-muted-foreground text-xs">Occupancy</div>
                <div class="text-xl font-semibold">
                    {{ pct(metrics.occupancy_bps) }}
                </div>
            </div>
        </section>

        <section class="space-y-3">
            <h2 class="text-lg font-medium">
                Pickup &amp; pace (vs 7 days ago)
            </h2>
            <div class="rounded-lg border">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-muted-foreground border-b text-left">
                            <th class="p-3">Stay date</th>
                            <th class="p-3">Sold then → now</th>
                            <th class="p-3">Pickup</th>
                            <th class="p-3">Revenue pickup</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="p in metrics.pace.slice(0, 21)"
                            :key="p.stay_date"
                            class="border-b last:border-0"
                        >
                            <td class="p-3 font-medium">{{ p.stay_date }}</td>
                            <td class="p-3 font-mono text-xs">
                                {{ p.sold_then }} → {{ p.sold_now }}
                            </td>
                            <td
                                class="p-3 font-mono text-xs"
                                :class="
                                    p.pickup < 0
                                        ? 'text-red-600'
                                        : 'text-green-700'
                                "
                            >
                                {{ p.pickup > 0 ? `+${p.pickup}` : p.pickup }}
                            </td>
                            <td class="p-3 font-mono text-xs">
                                {{
                                    money(
                                        p.revenue_now_minor -
                                            p.revenue_then_minor,
                                    )
                                }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="space-y-3">
            <h2 class="text-lg font-medium">Demand calendar</h2>
            <div class="rounded-lg border">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-muted-foreground border-b text-left">
                            <th class="p-3">Date</th>
                            <th class="p-3">Sold / avail</th>
                            <th class="p-3">Occupancy</th>
                            <th class="p-3">Room revenue</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="c in metrics.calendar.slice(0, 31)"
                            :key="c.stay_date"
                            class="border-b last:border-0"
                        >
                            <td class="p-3 font-medium">{{ c.stay_date }}</td>
                            <td class="p-3 font-mono text-xs">
                                {{ c.rooms_sold }} / {{ c.rooms_available }}
                            </td>
                            <td class="p-3">
                                <div class="bg-muted h-2 w-32 rounded">
                                    <div
                                        class="bg-primary h-2 rounded"
                                        :style="{
                                            width: `${Math.min(100, c.occupancy_bps / 100)}%`,
                                        }"
                                    />
                                </div>
                            </td>
                            <td class="p-3 font-mono text-xs">
                                {{ money(c.room_revenue_minor) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="grid gap-6 md:grid-cols-2">
            <div class="space-y-3">
                <h2 class="text-lg font-medium">By segment</h2>
                <div class="rounded-lg border">
                    <table class="w-full text-sm">
                        <thead>
                            <tr
                                class="text-muted-foreground border-b text-left"
                            >
                                <th class="p-3">Segment</th>
                                <th class="p-3">Nights</th>
                                <th class="p-3">Revenue</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(s, name) in metrics.by_segment"
                                :key="name"
                                class="border-b last:border-0"
                            >
                                <td class="p-3 font-medium">{{ name }}</td>
                                <td class="p-3 font-mono text-xs">
                                    {{ s.nights }}
                                </td>
                                <td class="p-3 font-mono text-xs">
                                    {{ money(s.revenue_minor) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="space-y-3">
                <h2 class="text-lg font-medium">By source</h2>
                <div class="rounded-lg border">
                    <table class="w-full text-sm">
                        <thead>
                            <tr
                                class="text-muted-foreground border-b text-left"
                            >
                                <th class="p-3">Source</th>
                                <th class="p-3">Nights</th>
                                <th class="p-3">Revenue</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(s, name) in metrics.by_source"
                                :key="name"
                                class="border-b last:border-0"
                            >
                                <td class="p-3 font-medium">{{ name }}</td>
                                <td class="p-3 font-mono text-xs">
                                    {{ s.nights }}
                                </td>
                                <td class="p-3 font-mono text-xs">
                                    {{ money(s.revenue_minor) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section class="space-y-3">
            <h2 class="text-lg font-medium">OTB vs budget</h2>
            <form
                @submit.prevent="
                    budgetForm.post(`${base}/budgets`, {
                        onSuccess: () => {
                            budgetForm.reset();
                            router.get(base, {}, { preserveState: true });
                        },
                    })
                "
                class="grid grid-cols-2 gap-3 rounded-lg border p-4 md:grid-cols-4"
            >
                <div class="grid gap-2">
                    <Label>Month (YYYY-MM)</Label
                    ><Input
                        v-model="budgetForm.month"
                        placeholder="2026-11"
                        required
                    />
                </div>
                <div class="grid gap-2">
                    <Label>Room nights target</Label
                    ><Input
                        v-model.number="budgetForm.room_nights_target"
                        type="number"
                        min="0"
                    />
                </div>
                <div class="grid gap-2">
                    <Label>Revenue target (minor)</Label
                    ><Input
                        v-model.number="budgetForm.revenue_target_minor"
                        type="number"
                        min="0"
                    />
                </div>
                <div class="flex items-end">
                    <Button type="submit">Save budget</Button>
                </div>
            </form>
            <div class="rounded-lg border">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-muted-foreground border-b text-left">
                            <th class="p-3">Month</th>
                            <th class="p-3">Nights OTB / target</th>
                            <th class="p-3">Revenue OTB / target</th>
                            <th class="p-3">Variance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="b in metrics.budgets"
                            :key="b.month"
                            class="border-b last:border-0"
                        >
                            <td class="p-3 font-medium">{{ b.month }}</td>
                            <td class="p-3 font-mono text-xs">
                                {{ b.room_nights_otb }} /
                                {{ b.room_nights_target }}
                            </td>
                            <td class="p-3 font-mono text-xs">
                                {{ money(b.revenue_otb_minor) }} /
                                {{ money(b.revenue_target_minor) }}
                            </td>
                            <td
                                class="p-3 font-mono text-xs"
                                :class="
                                    b.revenue_variance_minor < 0
                                        ? 'text-red-600'
                                        : 'text-green-700'
                                "
                            >
                                {{ money(b.revenue_variance_minor) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
