<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/vue3';

interface Row {
    inventory_item_id: number;
    name: string;
    unit: string;
    theoretical_qty: number;
    actual_qty: number;
    variance_qty: number;
    cost_value_minor: number;
    flagged: boolean;
}

const props = defineProps<{
    branch: { id: number; name: string };
    filters: { from: string; to: string };
    report: { from: string; to: string; rows: Row[] };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Costing', href: '/inventory' },
            { title: 'Variance', href: '#' },
        ],
    },
});

const filterForm = useForm({ from: props.filters.from, to: props.filters.to });
</script>

<template>
    <Head title="Cost Variance" />
    <div class="space-y-6 p-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold">
                    Theoretical vs actual — {{ branch.name }}
                </h1>
                <p class="text-muted-foreground text-sm">
                    Recipes × sales against posted deductions. Flagged rows
                    breach tolerance.
                </p>
            </div>
            <form
                @submit.prevent="
                    filterForm.get(`/branches/${branch.id}/costing/variance`, {
                        preserveState: true,
                    })
                "
                class="flex items-end gap-2"
            >
                <div class="grid gap-2">
                    <Label>From</Label
                    ><Input v-model="filterForm.from" type="date" />
                </div>
                <div class="grid gap-2">
                    <Label>To</Label
                    ><Input v-model="filterForm.to" type="date" />
                </div>
                <Button type="submit">Apply</Button>
            </form>
        </div>

        <div class="rounded-lg border">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-muted-foreground border-b text-left">
                        <th class="p-3">Item</th>
                        <th class="p-3">Theoretical</th>
                        <th class="p-3">Actual</th>
                        <th class="p-3">Variance</th>
                        <th class="p-3">Value (minor)</th>
                        <th class="p-3">Flag</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in report.rows"
                        :key="row.inventory_item_id"
                        class="border-b last:border-0"
                        :class="row.flagged ? 'bg-red-50' : ''"
                    >
                        <td class="p-3 font-medium">
                            {{ row.name }} ({{ row.unit }})
                        </td>
                        <td class="p-3 font-mono text-xs">
                            {{ row.theoretical_qty }}
                        </td>
                        <td class="p-3 font-mono text-xs">
                            {{ row.actual_qty }}
                        </td>
                        <td class="p-3 font-mono text-xs">
                            {{ row.variance_qty }}
                        </td>
                        <td class="p-3 font-mono text-xs">
                            {{ row.cost_value_minor }}
                        </td>
                        <td class="p-3 font-mono text-xs">
                            {{ row.flagged ? 'WASTE?' : '—' }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
