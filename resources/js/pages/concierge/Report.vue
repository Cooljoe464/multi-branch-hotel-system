<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Concierge ROI', href: '#' },
        ],
    },
});

const props = defineProps<{
    branch: { id: number; name: string; currency_code: string };
    days: number;
    deflection: {
        ai_handled: number;
        deflected_to_human: number;
        deflection_rate: number;
    };
    revenueImpact: { actions_applied: number; revenue_minor: number };
    sentimentTrend: { date: string; avg_sentiment: number; guest_messages: number }[];
}>();

const ranges = [
    { days: 7, label: '7 days' },
    { days: 30, label: '30 days' },
    { days: 90, label: '90 days' },
    { days: 365, label: '12 months' },
];

const money = (minor: number) =>
    new Intl.NumberFormat(undefined, { style: 'currency', currency: props.branch.currency_code }).format(minor / 100);

const rate = computed(() => `${Math.round(props.deflection.deflection_rate * 100)}%`);
const totalConversations = computed(() => props.deflection.ai_handled + props.deflection.deflected_to_human);
const aiShare = computed(() =>
    totalConversations.value === 0 ? 0 : (props.deflection.ai_handled / totalConversations.value) * 100,
);

// Sentiment runs from -1 (negative) to 1 (positive); bars grow from the midline.
const barStyle = (value: number) => ({
    height: `${Math.min(Math.abs(value), 1) * 50}%`,
    [value >= 0 ? 'bottom' : 'top']: '50%',
});

const setRange = (days: number) => {
    router.get(`/branches/${props.branch.id}/concierge/report`, { days }, { preserveScroll: true });
};
</script>

<template>
    <Head title="Concierge ROI" />

    <div class="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-4">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-foreground text-2xl font-bold">Concierge ROI</h1>
                <p class="text-muted-foreground text-sm">
                    What the WhatsApp AI concierge handled for {{ branch.name }}.
                </p>
            </div>
            <div class="flex gap-2">
                <Button
                    v-for="range in ranges"
                    :key="range.days"
                    size="sm"
                    :variant="days === range.days ? 'default' : 'outline'"
                    @click="setRange(range.days)"
                >
                    {{ range.label }}
                </Button>
            </div>
        </div>

        <section class="grid gap-4 md:grid-cols-3">
            <div class="border-border rounded-lg border p-4">
                <p class="text-muted-foreground text-xs">Deflection rate</p>
                <p class="text-3xl font-semibold">{{ rate }}</p>
                <div class="bg-muted mt-2 h-2 overflow-hidden rounded">
                    <div class="bg-primary h-full" :style="{ width: `${aiShare}%` }" />
                </div>
                <p class="text-muted-foreground mt-1 text-xs">
                    {{ deflection.ai_handled }} resolved by AI · {{ deflection.deflected_to_human }} handed to staff
                </p>
            </div>

            <div class="border-border rounded-lg border p-4">
                <p class="text-muted-foreground text-xs">Revenue from concierge actions</p>
                <p class="text-3xl font-semibold">{{ money(revenueImpact.revenue_minor) }}</p>
                <p class="text-muted-foreground mt-1 text-xs">
                    {{ revenueImpact.actions_applied }} applied action(s)
                </p>
            </div>

            <div class="border-border rounded-lg border p-4">
                <p class="text-muted-foreground text-xs">Guest conversations</p>
                <p class="text-3xl font-semibold">{{ totalConversations }}</p>
                <p class="text-muted-foreground mt-1 text-xs">in the last {{ days }} days</p>
            </div>
        </section>

        <section>
            <h2 class="text-foreground mb-2 text-lg font-semibold">Guest sentiment by day</h2>
            <div
                v-if="sentimentTrend.length === 0"
                class="border-border text-muted-foreground rounded-lg border p-6 text-center text-sm"
            >
                No scored guest messages in this period.
            </div>
            <div v-else class="border-border rounded-lg border p-4">
                <div class="flex h-32 items-stretch gap-1">
                    <div
                        v-for="point in sentimentTrend"
                        :key="point.date"
                        class="relative min-w-2 flex-1"
                        :title="`${point.date}: ${point.avg_sentiment.toFixed(2)} (${point.guest_messages} messages)`"
                    >
                        <div
                            class="absolute inset-x-0 rounded-sm"
                            :class="point.avg_sentiment >= 0 ? 'bg-green-500' : 'bg-red-500'"
                            :style="barStyle(point.avg_sentiment)"
                        />
                    </div>
                </div>
                <p class="text-muted-foreground mt-2 text-xs">
                    Above the line is positive, below is negative. Hover a bar for the day's score.
                </p>
            </div>
        </section>
    </div>
</template>
