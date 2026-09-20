<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import VueApexCharts from 'vue3-apexcharts';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useAppearance } from '@/composables/useAppearance';
import { formatCurrency } from '@/lib/format';

const props = defineProps<{
    data: Array<{ room_type_name: string; total_revenue: number; rooms_sold: number; adr: number }>;
    height?: number;
    currencySymbol?: string;
}>();

const { resolvedAppearance } = useAppearance();
const chartKey = ref(0);

const chartOptions = computed(() => {
    const isDark = resolvedAppearance.value === 'dark';
    const textColor = isDark ? '#a1a1aa' : '#9ca3af';
    const gridColor = isDark ? 'rgba(255,255,255,0.06)' : 'rgba(156,163,175,0.15)';

    return {
        chart: {
            type: 'bar' as const,
            toolbar: { show: false },
            background: 'transparent',
        },
        colors: ['#6366f1', '#a78bfa'],
        dataLabels: { enabled: false },
        plotOptions: {
            bar: {
                borderRadius: 6,
                columnWidth: '55%',
            },
        },
        grid: {
            borderColor: gridColor,
            strokeDashArray: 3,
        },
        xaxis: {
            categories: props.data.map((d) => d.room_type_name),
            labels: {
                style: { colors: textColor, fontSize: '12px' },
            },
            axisBorder: { show: false },
            axisTicks: { show: false },
        },
        yaxis: [
            {
                title: { text: 'Revenue', style: { color: textColor } },
                labels: {
                    style: { colors: textColor },
                    formatter: (val: number) => formatCurrency(val, props.currencySymbol ?? '₦'),
                },
            },
            {
                opposite: true,
                title: { text: 'Rooms Sold', style: { color: textColor } },
                labels: {
                    style: { colors: textColor },
                },
            },
        ],
        tooltip: {
            theme: isDark ? 'dark' : 'light',
            y: {
                formatter: (val: number, opts: any) => {
                    if (opts.seriesIndex === 1) {
                        return val.toLocaleString();
                    }
                    return formatCurrency(val, props.currencySymbol ?? '₦');
                },
            },
        },
        legend: {
            position: 'bottom' as const,
            labels: { colors: textColor },
        },
    };
});

const series = computed(() => [
    {
        name: 'Revenue',
        data: props.data.map((d) => d.total_revenue),
    },
    {
        name: 'Rooms Sold',
        data: props.data.map((d) => d.rooms_sold),
    },
]);

watch(() => props.data, () => { chartKey.value++; });
watch(resolvedAppearance, () => { chartKey.value++; });
</script>

<template>
    <Card class="border-border shadow-xs">
        <CardHeader class="pb-2">
            <CardTitle class="text-base font-semibold text-foreground">Room Type Performance</CardTitle>
        </CardHeader>
        <CardContent class="pt-0">
            <VueApexCharts
                :key="chartKey"
                type="bar"
                :height="height || 350"
                :options="chartOptions"
                :series="series"
            />
        </CardContent>
    </Card>
</template>
