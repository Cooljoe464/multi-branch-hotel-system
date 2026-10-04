<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import VueApexCharts from 'vue3-apexcharts';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useAppearance } from '@/composables/useAppearance';
import { formatDateShort } from '@/lib/dates';
import { formatCurrency } from '@/lib/format';

const props = defineProps<{
    data: Array<{
        date: string;
        room_revenue: number;
        tax: number;
        other_charges: number;
        net_revenue: number;
    }>;
    height?: number;
    currencySymbol?: string;
}>();

const { resolvedAppearance } = useAppearance();
const chartKey = ref(0);

const chartOptions = computed(() => {
    const isDark = resolvedAppearance.value === 'dark';
    const textColor = isDark ? '#a1a1aa' : '#9ca3af';
    const gridColor = isDark
        ? 'rgba(255,255,255,0.06)'
        : 'rgba(156,163,175,0.15)';

    return {
        chart: {
            type: 'area' as const,
            toolbar: { show: false },
            background: 'transparent',
        },
        colors: ['#6366f1', '#10b981', '#f59e0b'],
        dataLabels: { enabled: false },
        stroke: { curve: 'smooth' as const, width: 2 },
        fill: {
            type: 'gradient' as const,
            gradient: {
                shadeIntensity: 1,
                opacityFrom: 0.3,
                opacityTo: 0.05,
            },
        },
        grid: {
            borderColor: gridColor,
            strokeDashArray: 3,
        },
        xaxis: {
            categories: props.data.map((d) => formatDateShort(d.date)),
            labels: {
                style: { colors: textColor, fontSize: '11px' },
                rotate: -45,
            },
            axisBorder: { show: false },
            axisTicks: { show: false },
        },
        yaxis: {
            labels: {
                style: { colors: textColor },
                formatter: (val: number) =>
                    formatCurrency(val, props.currencySymbol ?? '₦'),
            },
        },
        tooltip: {
            theme: (isDark ? 'dark' : 'light') as 'dark' | 'light',
            y: {
                formatter: (val: number) =>
                    formatCurrency(val, props.currencySymbol ?? '₦'),
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
        name: 'Room Revenue',
        data: props.data.map((d) => d.room_revenue),
    },
    {
        name: 'Net Revenue',
        data: props.data.map((d) => d.net_revenue),
    },
    {
        name: 'Tax',
        data: props.data.map((d) => d.tax),
    },
]);

watch(
    () => props.data,
    () => {
        chartKey.value++;
    },
);
watch(resolvedAppearance, () => {
    chartKey.value++;
});
</script>

<template>
    <Card class="border-border shadow-xs">
        <CardHeader class="pb-2">
            <CardTitle class="text-foreground text-base font-semibold"
                >Revenue Trend</CardTitle
            >
        </CardHeader>
        <CardContent class="pt-0">
            <VueApexCharts
                :key="chartKey"
                type="area"
                :height="height || 320"
                :options="chartOptions"
                :series="series"
            />
        </CardContent>
    </Card>
</template>
