<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

interface ShiftRow {
    id: number;
    business_date: string;
    status: string;
    opening_float_minor: number;
    expected_cash_minor: number;
    counted_cash_minor: number | null;
    variance_minor: number | null;
    cashier: { name: string } | null;
}

const props = defineProps<{
    branch: { id: number; name: string; code: string };
    shifts: ShiftRow[];
    current: ShiftRow | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Cashier', href: '#' },
        ],
    },
});

const openForm = useForm({ opening_float_minor: 0 });
const closeForm = useForm({ counted_cash_minor: 0, note: '' });

function open() {
    openForm.post(`/branches/${props.branch.id}/cashier/open`);
}

function close(id: number) {
    closeForm.post(`/cashier-shifts/${id}/close`);
}
</script>

<template>
    <Head title="Cashier Shifts" />
    <div class="space-y-6 p-6">
        <div>
            <h1 class="text-2xl font-semibold">Cashier — {{ branch.name }}</h1>
            <p class="text-muted-foreground text-sm">
                One open drawer per cashier. Variances above threshold need a
                note.
            </p>
        </div>

        <form
            v-if="!current"
            @submit.prevent="open"
            class="flex items-end gap-2 rounded-lg border p-4"
        >
            <div class="grid gap-2">
                <Label>Opening float (minor units)</Label>
                <Input
                    v-model.number="openForm.opening_float_minor"
                    type="number"
                    min="0"
                    required
                />
            </div>
            <Button type="submit">Open shift</Button>
        </form>

        <form
            v-else
            @submit.prevent="close(current.id)"
            class="space-y-2 rounded-lg border p-4"
        >
            <p class="text-sm">
                Open shift from {{ current.business_date }} · float
                {{ current.opening_float_minor }}
            </p>
            <div class="grid gap-2">
                <Label>Counted cash (minor units)</Label>
                <Input
                    v-model.number="closeForm.counted_cash_minor"
                    type="number"
                    min="0"
                    required
                />
            </div>
            <div class="grid gap-2">
                <Label>Note (required for large variances)</Label>
                <Input v-model="closeForm.note" />
            </div>
            <Button type="submit">Close shift</Button>
        </form>

        <div class="rounded-lg border">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-muted-foreground border-b text-left">
                        <th class="p-3">Date</th>
                        <th class="p-3">Cashier</th>
                        <th class="p-3">Status</th>
                        <th class="p-3 text-right">Expected</th>
                        <th class="p-3 text-right">Counted</th>
                        <th class="p-3 text-right">Variance</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="s in shifts"
                        :key="s.id"
                        class="border-b last:border-0"
                    >
                        <td class="p-3">{{ s.business_date }}</td>
                        <td class="p-3">{{ s.cashier?.name ?? '—' }}</td>
                        <td class="p-3">{{ s.status }}</td>
                        <td class="p-3 text-right">
                            {{ s.expected_cash_minor }}
                        </td>
                        <td class="p-3 text-right">
                            {{ s.counted_cash_minor ?? '—' }}
                        </td>
                        <td class="p-3 text-right">
                            {{ s.variance_minor ?? '—' }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
