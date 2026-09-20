<script setup lang="ts">
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Building2, DollarSign, TrendingUp, BarChart3 } from '@lucide/vue';
import { formatCurrency, formatNumber } from '@/lib/format';

const page = usePage();
const branch = computed(() => page.props.branch?.current);
const currencySymbol = computed(() => branch.value?.currency_symbol || '₦');

const props = defineProps<{
    title: string;
    value: string | number;
    subtitle?: string;
    trend?: {
        value: number;
        direction: 'up' | 'down' | 'neutral';
    };
    icon?: string;
    format?: 'currency' | 'percent' | 'number';
}>();

const iconComponent = computed(() => {
    const map: Record<string, typeof Building2> = {
        '🏨': Building2,
        '💰': DollarSign,
        '📈': TrendingUp,
        '📊': BarChart3,
    };
    return map[props.icon ?? ''] ?? Building2;
});

const formattedValue = computed(() => {
    if (props.format === 'currency') {
        return formatCurrency(Number(props.value), currencySymbol.value, 0);
    }
    if (props.format === 'percent') {
        return `${props.value}%`;
    }
    return formatNumber(Number(props.value));
});

const trendColor = computed(() => {
    if (! props.trend) return '';
    if (props.trend.direction === 'up') return 'text-emerald-600 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-950/50';
    if (props.trend.direction === 'down') return 'text-rose-600 dark:text-rose-400 bg-rose-50 dark:bg-rose-950/50';
    return 'text-muted-foreground bg-muted';
});

const trendIcon = computed(() => {
    if (! props.trend) return '';
    if (props.trend.direction === 'up') return '↑';
    if (props.trend.direction === 'down') return '↓';
    return '→';
});
</script>

<template>
    <Card class="border-border shadow-xs transition-all hover:shadow-md">
        <CardHeader class="flex flex-row items-center justify-between pb-2 space-y-0">
            <CardTitle class="text-sm font-medium text-muted-foreground">
                {{ title }}
            </CardTitle>
            <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-muted">
                <component :is="iconComponent" class="h-4 w-4 text-muted-foreground" />
            </div>
        </CardHeader>
        <CardContent>
            <div class="text-2xl font-bold tracking-tight text-foreground">
                {{ formattedValue }}
            </div>
            <p v-if="subtitle" class="mt-1 text-xs text-muted-foreground">
                {{ subtitle }}
            </p>
            <div v-if="trend" class="mt-3 flex items-center gap-1.5 text-xs">
                <span :class="trendColor" class="inline-flex items-center px-1.5 py-0.5 rounded-md font-medium">
                    {{ trendIcon }} {{ Math.abs(trend.value) }}%
                </span>
                <span class="text-muted-foreground">vs previous period</span>
            </div>
        </CardContent>
    </Card>
</template>
