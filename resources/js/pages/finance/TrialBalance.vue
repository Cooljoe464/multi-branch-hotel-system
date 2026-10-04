<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';

interface TrialRow {
    id: number;
    business_date: string;
    balanced: boolean;
    totals: { debits: number; credits: number; unknown_accounts: string[] };
}

const props = defineProps<{
    branch: { id: number; name: string; code: string };
    balances: TrialRow[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Trial Balance', href: '#' },
        ],
    },
});

function close(date: string) {
    router.post(`/branches/${props.branch.id}/trial-balance/close`, {
        business_date: date,
    });
}
</script>

<template>
    <Head title="Trial Balance" />
    <div class="space-y-6 p-6">
        <div>
            <h1 class="text-2xl font-semibold">
                Trial Balance — {{ branch.name }}
            </h1>
            <p class="text-muted-foreground text-sm">
                Debits must equal credits and every account must exist in the
                chart.
            </p>
        </div>

        <div class="rounded-lg border">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-muted-foreground border-b text-left">
                        <th class="p-3">Date</th>
                        <th class="p-3 text-right">Debits</th>
                        <th class="p-3 text-right">Credits</th>
                        <th class="p-3">Status</th>
                        <th class="p-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in balances"
                        :key="row.id"
                        class="border-b last:border-0"
                    >
                        <td class="p-3 font-medium">{{ row.business_date }}</td>
                        <td class="p-3 text-right">
                            {{
                                (row.totals.debits / 100).toLocaleString(
                                    undefined,
                                    { minimumFractionDigits: 2 },
                                )
                            }}
                        </td>
                        <td class="p-3 text-right">
                            {{
                                (row.totals.credits / 100).toLocaleString(
                                    undefined,
                                    { minimumFractionDigits: 2 },
                                )
                            }}
                        </td>
                        <td class="p-3">
                            <span v-if="row.balanced" class="text-emerald-600"
                                >balanced</span
                            >
                            <span v-else class="text-red-600"
                                >out of balance{{
                                    row.totals.unknown_accounts.length
                                        ? ` (${row.totals.unknown_accounts.join(', ')})`
                                        : ''
                                }}</span
                            >
                        </td>
                        <td class="p-3 text-right">
                            <Button
                                variant="outline"
                                size="sm"
                                @click="close(row.business_date)"
                                >Re-run</Button
                            >
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
