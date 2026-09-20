<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import VueApexCharts from 'vue3-apexcharts';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useAppearance } from '@/composables/useAppearance';
import { formatDateShort } from '@/lib/dates';

const props = defineProps<{
    data: Array<{ date: string; occupancy_pct: number; occupied_rooms: number; total_rooms: number }>;
    height?: number;
}>();

const { resolvedAppearance } = useAppearance();
const chartKey = ref(0);

const chartOptions = computed(() => {
    const isDark = resolvedAppearance.value === 'dark';
    const textColor = isDark ? '#a1a1aa' : '#9ca3af';
    const gridColor = isDark ? 'rgba(255,255,255,0.06)' : 'rgba(156,163,175,0.15)';

    return {
        chart: {
            type: 'area' as const,
            toolbar: { show: false },
            background: 'transparent',
        },
        colors: ['#6366f1'],
        dataLabels: { enabled: false },
        stroke: { curve: 'smooth' as const, width: 2 },
        fill: {
            type: 'gradient' as const,
            gradient: {
                shadeIntensity: 1,
                opacityFrom: 0.4,
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
            min: 0,
            max: 100,
            labels: {
                style: { colors: textColor },
                formatter: (val: number) => `${val}%`,
            },
        },
        tooltip: {
            theme: isDark ? 'dark' : 'light',
            y: {
                formatter: (val: number) => `${val}%`,
            },
        },
        annotations: {
            yaxis: [
                {
                    y: 70,
                    borderColor: '#10b981',
                    strokeDashArray: 4,
                    label: {
                        text: 'Target 70%',
                        style: { color: '#10b981', background: 'transparent' },
                    },
                },
            ],
        },
    };
});

const series = computed(() => [
    {
        name: 'Occupancy',
        data: props.data.map((d) => d.occupancy_pct),
    },
]);

watch(() => props.data, () => { chartKey.value++; });
watch(resolvedAppearance, () => { chartKey.value++; });
</script>

<template>
    <Card class="border-border shadow-xs">
        <CardHeader class="pb-2">
            <CardTitle class="text-base font-semibold text-foreground">Occupancy Trend</CardTitle>
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
