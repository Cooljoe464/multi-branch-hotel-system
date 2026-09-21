<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

interface ZLine {
    type: string;
    category: string;
    total: number;
    lines: number;
}

defineProps<{
    branch: { id: number; name: string; code: string };
    report: {
        opening_float_minor: number;
        expected_cash_minor: number;
        counted_cash_minor: number | null;
        variance_minor: number | null;
        lines: ZLine[];
        generated_at: string;
    };
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }, { title: 'Z-Report', href: '#' }] } });
</script>

<template>
    <Head title="Z-Report" />
    <div class="space-y-6 p-6 print:p-0">
        <div>
            <h1 class="text-2xl font-semibold">Z-Report — {{ branch.name }}</h1>
            <p class="text-sm text-muted-foreground">Generated {{ report.generated_at }}</p>
        </div>

        <div class="grid gap-2 rounded-lg border p-4 text-sm">
            <div class="flex justify-between"><span>Opening float</span><span class="font-medium">{{ report.opening_float_minor }}</span></div>
            <div class="flex justify-between"><span>Expected cash</span><span class="font-medium">{{ report.expected_cash_minor }}</span></div>
            <div class="flex justify-between"><span>Counted cash</span><span class="font-medium">{{ report.counted_cash_minor ?? '—' }}</span></div>
            <div class="flex justify-between font-semibold"><span>Variance</span><span>{{ report.variance_minor ?? '—' }}</span></div>
        </div>

        <div class="rounded-lg border">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b text-left text-muted-foreground">
                        <th class="p-3">Type</th>
                        <th class="p-3">Category</th>
                        <th class="p-3 text-right">Total</th>
                        <th class="p-3 text-right">Lines</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(l, i) in report.lines" :key="i" class="border-b last:border-0">
                        <td class="p-3">{{ l.type }}</td>
                        <td class="p-3 font-mono">{{ l.category }}</td>
                        <td class="p-3 text-right">{{ l.total }}</td>
                        <td class="p-3 text-right">{{ l.lines }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
