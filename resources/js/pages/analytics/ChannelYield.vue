<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Analytics', href: '/analytics' },
            { title: 'Channel Yield', href: '/reports/channel-yield' },
        ],
    },
});

const props = defineProps<{
    channelYieldData: Array<{
        business_date: string;
        total_room_revenue: number;
        rooms_posted: number;
    }>;
}>();

import { formatCurrency } from '@/lib/format';

const page = usePage();
const branch = computed(() => page.props.branch?.current);
const currencySymbol = computed(() => branch.value?.currency_symbol || '₦');

function formatCents(cents: number): string {
    return formatCurrency(cents, currencySymbol.value, 0);
}

function calculateADR(revenue: number, rooms: number): string {
    if (rooms === 0) return formatCents(0);
    return formatCents(Math.round(revenue / rooms));
}

function formatDate(dateStr: string): string {
    return new Date(dateStr).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
}
</script>

<template>
    <Head title="Channel Yield" />

    <div
        class="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-4 md:p-6"
    >
        <div>
            <h1 class="text-foreground text-2xl font-bold">
                Channel Yield Report
            </h1>
            <p class="text-muted-foreground text-sm">
                Daily revenue performance (last 30 days)
            </p>
        </div>

        <div class="bg-card text-card-foreground rounded-xl border shadow-sm">
            <!-- Mobile: Card View -->
            <div class="divide-border divide-y md:hidden">
                <div
                    v-for="row in channelYieldData"
                    :key="row.business_date"
                    class="p-4"
                >
                    <div class="mb-2 flex items-start justify-between gap-2">
                        <span class="text-foreground text-sm font-medium">{{
                            formatDate(row.business_date)
                        }}</span>
                        <span class="text-foreground text-sm font-medium">{{
                            calculateADR(
                                row.total_room_revenue,
                                row.rooms_posted,
                            )
                        }}</span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-muted-foreground"
                            >Rooms Sold:
                            <span class="text-foreground font-medium">{{
                                row.rooms_posted
                            }}</span></span
                        >
                        <span class="text-muted-foreground"
                            >Revenue:
                            <span class="text-foreground font-medium">{{
                                formatCents(row.total_room_revenue)
                            }}</span></span
                        >
                    </div>
                </div>
                <div
                    v-if="channelYieldData.length === 0"
                    class="text-muted-foreground p-4 py-8 text-center"
                >
                    No channel yield data available.
                </div>
            </div>

            <!-- Desktop: Table View -->
            <div class="hidden overflow-x-auto md:block">
                <table class="w-full caption-bottom text-sm">
                    <thead class="bg-muted/50 border-b [&_tr]:border-b">
                        <tr>
                            <th
                                class="text-muted-foreground h-12 px-4 text-left font-medium"
                            >
                                Date
                            </th>
                            <th
                                class="text-muted-foreground h-12 px-4 text-right font-medium"
                            >
                                Rooms Sold
                            </th>
                            <th
                                class="text-muted-foreground h-12 px-4 text-right font-medium"
                            >
                                Room Revenue
                            </th>
                            <th
                                class="text-muted-foreground h-12 px-4 text-right font-medium"
                            >
                                ADR
                            </th>
                        </tr>
                    </thead>
                    <tbody class="[&_tr:last-child]:border-0">
                        <tr
                            v-for="row in channelYieldData"
                            :key="row.business_date"
                            class="hover:bg-muted/50 border-b transition-colors"
                        >
                            <td class="text-foreground p-4 font-medium">
                                {{ formatDate(row.business_date) }}
                            </td>
                            <td class="text-muted-foreground p-4 text-right">
                                {{ row.rooms_posted }}
                            </td>
                            <td class="text-foreground p-4 text-right">
                                {{ formatCents(row.total_room_revenue) }}
                            </td>
                            <td
                                class="text-foreground p-4 text-right font-medium"
                            >
                                {{
                                    calculateADR(
                                        row.total_room_revenue,
                                        row.rooms_posted,
                                    )
                                }}
                            </td>
                        </tr>
                        <tr v-if="channelYieldData.length === 0">
                            <td
                                colspan="4"
                                class="text-muted-foreground p-4 py-8 text-center"
                            >
                                No channel yield data available.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
