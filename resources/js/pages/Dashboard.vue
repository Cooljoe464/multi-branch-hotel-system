<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import DatePicker from '@/components/ui/date-picker/DatePicker.vue';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';
import KpiCard from '@/components/analytics/KpiCard.vue';
import RevenueChart from '@/components/analytics/RevenueChart.vue';
import OccupancyChart from '@/components/analytics/OccupancyChart.vue';
import RoomTypeBreakdown from '@/components/analytics/RoomTypeBreakdown.vue';
import { formatDate } from '@/lib/dates';

const page = usePage();
const currencySymbol = computed(
    () => (page.props.branch?.current as any)?.currency_symbol || '₦',
);

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    },
});

const props = defineProps<{
    kpi: {
        date: string;
        occupancy_pct: number;
        adr: number;
        revpar: number;
        revenue_7d: {
            total_room_revenue: number;
            total_tax: number;
            total_payments: number;
            net_revenue: number;
            daily: Array<{
                date: string;
                room_revenue: number;
                tax: number;
                other_charges: number;
                payments: number;
                net_revenue: number;
            }>;
        };
        revenue_30d: {
            total_room_revenue: number;
            total_tax: number;
            total_payments: number;
            net_revenue: number;
            daily: Array<{
                date: string;
                room_revenue: number;
                tax: number;
                other_charges: number;
                payments: number;
                net_revenue: number;
            }>;
        };
        occupancy_trend_30d: Array<{
            date: string;
            occupancy_pct: number;
            occupied_rooms: number;
            total_rooms: number;
        }>;
        room_type_performance: Array<{
            room_type_name: string;
            total_revenue: number;
            rooms_sold: number;
            adr: number;
        }>;
    };
    branch: {
        name: string;
        currency_symbol: string;
    };
    days: number;
    startDate: string | null;
    endDate: string | null;
    roomStatusCounts: {
        available: number;
        occupied: number;
        dirty: number;
        out_of_order: number;
    };
}>();

const periodOptions = [
    { label: '7 Days', value: 7 },
    { label: '30 Days', value: 30 },
    { label: '90 Days', value: 90 },
];

const dateFrom = ref(props.startDate ?? '');
const dateTo = ref(props.endDate ?? '');

function changePeriod(days: number) {
    dateFrom.value = '';
    dateTo.value = '';
    router.get('/dashboard', { days }, { preserveState: true });
}

function applyDateRange() {
    if (dateFrom.value && dateTo.value) {
        router.get(
            '/dashboard',
            { start_date: dateFrom.value, end_date: dateTo.value },
            { preserveState: true },
        );
    }
}

function clearDateRange() {
    dateFrom.value = '';
    dateTo.value = '';
    router.get('/dashboard', { days: 30 }, { preserveState: true });
}
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
        <div
            class="border-border flex flex-col justify-between gap-4 border-b pb-2 sm:flex-row sm:items-center"
        >
            <div>
                <h1 class="text-foreground text-2xl font-bold tracking-tight">
                    Revenue Dashboard
                </h1>
                <p class="text-muted-foreground mt-0.5 text-sm">
                    Overview and performance metrics for
                    <span class="text-foreground font-medium">{{
                        branch.name
                    }}</span>
                </p>
            </div>
            <div class="flex items-center gap-3">
                <div class="flex gap-2">
                    <Button
                        v-for="option in periodOptions"
                        :key="option.value"
                        size="sm"
                        :variant="
                            days === option.value && !dateFrom && !dateTo
                                ? 'default'
                                : 'outline'
                        "
                        @click="changePeriod(option.value)"
                    >
                        {{ option.label }}
                    </Button>
                </div>
                <div class="flex items-end gap-2">
                    <div class="grid gap-1.5">
                        <Label class="text-muted-foreground text-xs"
                            >From</Label
                        >
                        <DatePicker
                            v-model="dateFrom"
                            placeholder="Start date"
                            class="w-36"
                            @change="applyDateRange"
                        />
                    </div>
                    <div class="grid gap-1.5">
                        <Label class="text-muted-foreground text-xs">To</Label>
                        <DatePicker
                            v-model="dateTo"
                            :min-date="dateFrom || undefined"
                            placeholder="End date"
                            class="w-36"
                            @change="applyDateRange"
                        />
                    </div>
                    <Button
                        v-if="dateFrom || dateTo"
                        variant="ghost"
                        size="sm"
                        @click="clearDateRange"
                        class="mb-0.5"
                    >
                        Clear
                    </Button>
                </div>
                <div
                    class="bg-muted text-muted-foreground inline-flex items-center gap-2 self-start rounded-md px-3 py-1.5 text-xs font-medium sm:self-auto"
                >
                    <span class="size-2 rounded-full bg-emerald-500"></span>
                    <span>As of {{ formatDate(kpi.date) }}</span>
                </div>
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <KpiCard
                title="Occupancy"
                :value="kpi.occupancy_pct"
                format="percent"
                icon="🏨"
            />
            <KpiCard
                title="Average Daily Rate"
                :value="kpi.adr"
                format="currency"
                icon="💰"
            />
            <KpiCard
                title="RevPAR"
                :value="kpi.revpar"
                format="currency"
                icon="📈"
            />
            <KpiCard
                title="Net Revenue (30d)"
                :value="kpi.revenue_30d.net_revenue"
                format="currency"
                icon="📊"
            />
        </div>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <a
                href="/rooms?status=available"
                class="border-border bg-card flex items-center gap-4 rounded-lg border p-4 shadow-xs transition-all hover:border-green-300 hover:shadow-md"
            >
                <div
                    class="flex h-12 w-12 items-center justify-center rounded-lg bg-green-100 dark:bg-green-900/30"
                >
                    <span class="text-xl">🟢</span>
                </div>
                <div>
                    <p class="text-muted-foreground text-sm font-medium">
                        Available
                    </p>
                    <p
                        class="text-2xl font-bold text-green-600 dark:text-green-400"
                    >
                        {{ roomStatusCounts.available }}
                    </p>
                </div>
            </a>
            <a
                href="/rooms?status=occupied"
                class="border-border bg-card flex items-center gap-4 rounded-lg border p-4 shadow-xs transition-all hover:border-red-300 hover:shadow-md"
            >
                <div
                    class="flex h-12 w-12 items-center justify-center rounded-lg bg-red-100 dark:bg-red-900/30"
                >
                    <span class="text-xl">🔴</span>
                </div>
                <div>
                    <p class="text-muted-foreground text-sm font-medium">
                        Occupied
                    </p>
                    <p
                        class="text-2xl font-bold text-red-600 dark:text-red-400"
                    >
                        {{ roomStatusCounts.occupied }}
                    </p>
                </div>
            </a>
            <a
                href="/rooms?status=dirty"
                class="border-border bg-card flex items-center gap-4 rounded-lg border p-4 shadow-xs transition-all hover:border-yellow-300 hover:shadow-md"
            >
                <div
                    class="flex h-12 w-12 items-center justify-center rounded-lg bg-yellow-100 dark:bg-yellow-900/30"
                >
                    <span class="text-xl">🟡</span>
                </div>
                <div>
                    <p class="text-muted-foreground text-sm font-medium">
                        Needs Cleanup
                    </p>
                    <p
                        class="text-2xl font-bold text-yellow-600 dark:text-yellow-400"
                    >
                        {{ roomStatusCounts.dirty }}
                    </p>
                </div>
            </a>
            <a
                href="/rooms?status=out_of_order"
                class="border-border bg-card hover:border-muted-foreground/30 flex items-center gap-4 rounded-lg border p-4 shadow-xs transition-all hover:shadow-md"
            >
                <div
                    class="bg-muted flex h-12 w-12 items-center justify-center rounded-lg"
                >
                    <span class="text-xl">⚫</span>
                </div>
                <div>
                    <p class="text-muted-foreground text-sm font-medium">
                        Out of Order
                    </p>
                    <p class="text-muted-foreground text-2xl font-bold">
                        {{ roomStatusCounts.out_of_order }}
                    </p>
                </div>
            </a>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <RevenueChart
                :data="kpi.revenue_30d.daily"
                :height="320"
                :currency-symbol="currencySymbol"
            />
            <OccupancyChart :data="kpi.occupancy_trend_30d" :height="320" />
        </div>

        <RoomTypeBreakdown
            v-if="kpi.room_type_performance.length > 0"
            :data="kpi.room_type_performance"
            :currency-symbol="currencySymbol"
            :height="350"
        />
    </div>
</template>
