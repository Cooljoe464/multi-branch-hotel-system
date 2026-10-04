<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import DatePicker from '@/components/ui/date-picker/DatePicker.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import Pagination from '@/components/ui/pagination/Pagination.vue';
import { formatDate } from '@/lib/dates';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Analytics', href: '/analytics' },
            { title: 'Reports', href: '/reports' },
        ],
    },
});

const props = defineProps<{
    ledgers: {
        data: Array<{
            id: number;
            business_date: string;
            status: string;
            rooms_posted: number;
            total_room_revenue: number;
            total_tax: number;
            total_other_charges: number;
            total_payments: number;
            net_revenue: number;
            completed_at: string;
        }>;
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    branch: {
        name: string;
        currency_symbol: string;
    };
}>();

const ledgerStatusVariant: Record<
    string,
    'default' | 'secondary' | 'destructive' | 'outline'
> = {
    completed: 'default',
    open: 'secondary',
};

const startDate = ref(
    new Date(Date.now() - 30 * 86400000).toISOString().split('T')[0],
);
const endDate = ref(new Date().toISOString().split('T')[0]);
const exporting = ref(false);

function exportNightAudit() {
    exporting.value = true;
    const params = new URLSearchParams({
        start_date: startDate.value,
        end_date: endDate.value,
    });
    window.location.href = `/reports/night-audit/export?${params.toString()}`;
    setTimeout(() => {
        exporting.value = false;
    }, 2000);
}

function exportFinancial() {
    exporting.value = true;
    const params = new URLSearchParams({
        start_date: startDate.value,
        end_date: endDate.value,
    });
    window.location.href = `/reports/financial/export?${params.toString()}`;
    setTimeout(() => {
        exporting.value = false;
    }, 2000);
}

import { formatCurrency } from '@/lib/format';

const currencySymbol = computed(() => props.branch.currency_symbol || '₦');

function formatCents(cents: number): string {
    return formatCurrency(cents, currencySymbol.value, 0);
}

function goToPage(page: number) {
    router.get('/reports', { page }, { preserveState: true, replace: true });
}
</script>

<template>
    <Head title="Reports" />

    <div
        class="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-4 md:p-6"
    >
        <div>
            <h1 class="text-foreground text-2xl font-bold">
                Reports &amp; Exports
            </h1>
            <p class="text-muted-foreground text-sm">{{ branch.name }}</p>
        </div>

        <div
            class="bg-card text-card-foreground rounded-xl border p-4 shadow-sm md:p-6"
        >
            <h2 class="mb-4 text-lg font-semibold">Export Data</h2>
            <div
                class="flex flex-col gap-4 sm:flex-row sm:flex-wrap sm:items-end"
            >
                <div class="min-w-40 space-y-1">
                    <Label for="startDate">Start Date</Label>
                    <DatePicker id="startDate" v-model="startDate" />
                </div>
                <div class="min-w-40 space-y-1">
                    <Label for="endDate">End Date</Label>
                    <DatePicker id="endDate" v-model="endDate" />
                </div>
                <Button :disabled="exporting" @click="exportNightAudit">
                    {{ exporting ? 'Exporting...' : 'Night Audit CSV' }}
                </Button>
                <Button
                    variant="default"
                    :disabled="exporting"
                    @click="exportFinancial"
                >
                    {{ exporting ? 'Exporting...' : 'Financial Summary CSV' }}
                </Button>
            </div>
        </div>

        <div class="bg-card text-card-foreground rounded-xl border shadow-sm">
            <div class="border-b p-4 md:p-6">
                <h2 class="text-lg font-semibold">Night Audit Log</h2>
            </div>

            <!-- Mobile: Card View -->
            <div class="divide-border divide-y md:hidden">
                <div
                    v-for="ledger in ledgers.data"
                    :key="ledger.id"
                    class="p-4"
                >
                    <div class="mb-2 flex items-start justify-between gap-2">
                        <span class="text-foreground text-sm font-medium">{{
                            formatDate(ledger.business_date)
                        }}</span>
                        <Badge
                            :variant="
                                ledgerStatusVariant[ledger.status] ?? 'outline'
                            "
                        >
                            {{ ledger.status }}
                        </Badge>
                    </div>
                    <div class="grid grid-cols-2 gap-2 text-sm">
                        <div>
                            <span class="text-muted-foreground">Rooms:</span>
                            <span class="text-foreground ml-1 font-medium">{{
                                ledger.rooms_posted
                            }}</span>
                        </div>
                        <div>
                            <span class="text-muted-foreground">Revenue:</span>
                            <span class="text-foreground ml-1 font-medium">{{
                                formatCents(ledger.total_room_revenue)
                            }}</span>
                        </div>
                        <div>
                            <span class="text-muted-foreground">Tax:</span>
                            <span class="text-foreground ml-1 font-medium">{{
                                formatCents(ledger.total_tax)
                            }}</span>
                        </div>
                        <div>
                            <span class="text-muted-foreground">Net:</span>
                            <span class="text-foreground ml-1 font-medium">{{
                                formatCents(ledger.net_revenue)
                            }}</span>
                        </div>
                    </div>
                </div>
                <div
                    v-if="ledgers.data.length === 0"
                    class="text-muted-foreground p-4 py-8 text-center"
                >
                    No night audit records found.
                </div>
            </div>

            <!-- Desktop: Table View -->
            <div class="hidden overflow-x-auto rounded-md border md:block">
                <table class="w-full caption-bottom text-sm">
                    <thead class="bg-muted/50 border-b [&_tr]:border-b">
                        <tr>
                            <th
                                class="text-muted-foreground h-10 px-2 text-left align-middle font-medium"
                            >
                                Date
                            </th>
                            <th
                                class="text-muted-foreground h-10 px-2 text-left align-middle font-medium"
                            >
                                Status
                            </th>
                            <th
                                class="text-muted-foreground h-10 px-2 text-left align-middle font-medium"
                            >
                                Rooms
                            </th>
                            <th
                                class="text-muted-foreground h-10 px-2 text-left align-middle font-medium"
                            >
                                Room Revenue
                            </th>
                            <th
                                class="text-muted-foreground h-10 px-2 text-left align-middle font-medium"
                            >
                                Tax
                            </th>
                            <th
                                class="text-muted-foreground h-10 px-2 text-left align-middle font-medium"
                            >
                                Net Revenue
                            </th>
                        </tr>
                    </thead>
                    <tbody class="[&_tr:last-child]:border-0">
                        <tr
                            v-for="ledger in ledgers.data"
                            :key="ledger.id"
                            class="hover:bg-muted/50 border-b transition-colors"
                        >
                            <td class="p-2 align-middle">
                                {{ formatDate(ledger.business_date) }}
                            </td>
                            <td class="p-2 align-middle">
                                <Badge
                                    :variant="
                                        ledgerStatusVariant[ledger.status] ??
                                        'outline'
                                    "
                                >
                                    {{ ledger.status }}
                                </Badge>
                            </td>
                            <td class="p-2 align-middle">
                                {{ ledger.rooms_posted }}
                            </td>
                            <td class="p-2 align-middle">
                                {{ formatCents(ledger.total_room_revenue) }}
                            </td>
                            <td class="p-2 align-middle">
                                {{ formatCents(ledger.total_tax) }}
                            </td>
                            <td class="p-2 align-middle">
                                {{ formatCents(ledger.net_revenue) }}
                            </td>
                        </tr>
                        <tr v-if="ledgers.data.length === 0">
                            <td
                                colspan="6"
                                class="text-muted-foreground p-2 py-8 text-center align-middle"
                            >
                                No night audit records found.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="p-4">
                <Pagination
                    :data="ledgers"
                    label="records"
                    @page-change="goToPage"
                />
            </div>
        </div>
    </div>
</template>
