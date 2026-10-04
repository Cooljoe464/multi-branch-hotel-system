<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import DatePicker from '@/components/ui/date-picker/DatePicker.vue';
import { Label } from '@/components/ui/label';
import { formatCurrency } from '@/lib/format';
import KpiCard from '@/components/analytics/KpiCard.vue';
import RevenueChart from '@/components/analytics/RevenueChart.vue';
import OccupancyChart from '@/components/analytics/OccupancyChart.vue';
import RoomTypeBreakdown from '@/components/analytics/RoomTypeBreakdown.vue';
import analytics from '@/routes/analytics';

const page = usePage();
const currencySymbol = computed(
    () => (page.props.branch?.current as any)?.currency_symbol || '₦',
);

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Analytics', href: '/analytics' },
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
    revenue: {
        period_days: number;
        total_room_revenue: number;
        total_tax: number;
        total_other_charges: number;
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
    occupancyTrend: Array<{
        date: string;
        occupancy_pct: number;
        occupied_rooms: number;
        total_rooms: number;
    }>;
    roomTypePerformance: Array<{
        room_type_name: string;
        total_revenue: number;
        rooms_sold: number;
        adr: number;
    }>;
    days: number;
    startDate: string | null;
    endDate: string | null;
    branch: {
        name: string;
        currency_symbol: string;
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
    router.get('/analytics', { days }, { preserveState: true });
}

function applyDateRange() {
    if (dateFrom.value && dateTo.value) {
        router.get(
            '/analytics',
            { start_date: dateFrom.value, end_date: dateTo.value },
            { preserveState: true },
        );
    }
}

function clearDateRange() {
    dateFrom.value = '';
    dateTo.value = '';
    router.get('/analytics', { days: 30 }, { preserveState: true });
}
</script>

<template>
    <Head title="Analytics" />

    <div
        class="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-4"
    >
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-foreground text-2xl font-bold">
                    Analytics &amp; Reporting
                </h1>
                <p class="text-muted-foreground text-sm">{{ branch.name }}</p>
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
            </div>
        </div>

        <div class="grid gap-4 md:grid-cols-4">
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
                :title="`Net Revenue (${days}d)`"
                :value="revenue.net_revenue"
                format="currency"
                icon="📊"
            />
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <RevenueChart
                :data="revenue.daily"
                :height="350"
                :currency-symbol="currencySymbol"
            />
            <OccupancyChart :data="occupancyTrend" :height="350" />
        </div>

        <RoomTypeBreakdown
            v-if="roomTypePerformance.length > 0"
            :data="roomTypePerformance"
            :height="350"
            :currency-symbol="currencySymbol"
        />

        <div class="grid gap-6 lg:grid-cols-3">
            <div
                class="bg-card text-card-foreground rounded-xl border p-6 shadow-sm"
            >
                <h3 class="text-lg font-semibold">Total Room Revenue</h3>
                <p
                    class="mt-2 text-3xl font-bold text-indigo-600 dark:text-indigo-400"
                >
                    {{ formatCurrency(revenue.total_room_revenue) }}
                </p>
            </div>
            <div
                class="bg-card text-card-foreground rounded-xl border p-6 shadow-sm"
            >
                <h3 class="text-lg font-semibold">Total Tax</h3>
                <p
                    class="mt-2 text-3xl font-bold text-amber-600 dark:text-amber-400"
                >
                    {{ formatCurrency(revenue.total_tax) }}
                </p>
            </div>
            <div
                class="bg-card text-card-foreground rounded-xl border p-6 shadow-sm"
            >
                <h3 class="text-lg font-semibold">Total Payments</h3>
                <p
                    class="mt-2 text-3xl font-bold text-emerald-600 dark:text-emerald-400"
                >
                    {{ formatCurrency(revenue.total_payments) }}
                </p>
            </div>
        </div>
    </div>
</template>
