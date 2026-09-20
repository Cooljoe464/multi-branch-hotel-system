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

    <div class="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-4 md:p-6">
        <div>
            <h1 class="text-2xl font-bold text-foreground">Channel Yield Report</h1>
            <p class="text-sm text-muted-foreground">Daily revenue performance (last 30 days)</p>
        </div>

        <div class="rounded-xl border bg-card text-card-foreground shadow-sm">
            <!-- Mobile: Card View -->
            <div class="md:hidden divide-y divide-border">
                <div v-for="row in channelYieldData" :key="row.business_date" class="p-4">
                    <div class="flex items-start justify-between gap-2 mb-2">
                        <span class="text-sm font-medium text-foreground">{{ formatDate(row.business_date) }}</span>
                        <span class="text-sm font-medium text-foreground">{{ calculateADR(row.total_room_revenue, row.rooms_posted) }}</span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-muted-foreground">Rooms Sold: <span class="font-medium text-foreground">{{ row.rooms_posted }}</span></span>
                        <span class="text-muted-foreground">Revenue: <span class="font-medium text-foreground">{{ formatCents(row.total_room_revenue) }}</span></span>
                    </div>
                </div>
                <div v-if="channelYieldData.length === 0" class="p-4 py-8 text-center text-muted-foreground">
                    No channel yield data available.
                </div>
            </div>

            <!-- Desktop: Table View -->
            <div class="hidden md:block overflow-x-auto">
                <table class="w-full caption-bottom text-sm">
                    <thead class="border-b bg-muted/50 [&_tr]:border-b">
                        <tr>
                            <th class="h-12 px-4 text-left font-medium text-muted-foreground">Date</th>
                            <th class="h-12 px-4 text-right font-medium text-muted-foreground">Rooms Sold</th>
                            <th class="h-12 px-4 text-right font-medium text-muted-foreground">Room Revenue</th>
                            <th class="h-12 px-4 text-right font-medium text-muted-foreground">ADR</th>
                        </tr>
                    </thead>
                    <tbody class="[&_tr:last-child]:border-0">
                        <tr v-for="row in channelYieldData" :key="row.business_date" class="border-b transition-colors hover:bg-muted/50">
                            <td class="p-4 font-medium text-foreground">{{ formatDate(row.business_date) }}</td>
                            <td class="p-4 text-right text-muted-foreground">{{ row.rooms_posted }}</td>
                            <td class="p-4 text-right text-foreground">{{ formatCents(row.total_room_revenue) }}</td>
                            <td class="p-4 text-right font-medium text-foreground">{{ calculateADR(row.total_room_revenue, row.rooms_posted) }}</td>
                        </tr>
                        <tr v-if="channelYieldData.length === 0">
                            <td colspan="4" class="p-4 text-center text-muted-foreground py-8">
                                No channel yield data available.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
