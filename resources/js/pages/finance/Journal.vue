<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

interface JournalRow {
    id: number;
    business_date: string;
    event: string;
    debit_account: string;
    credit_account: string;
    amount_minor: number;
    currency_code: string;
    posted_at: string | null;
}

const props = defineProps<{
    branch: { id: number; name: string; code: string };
    entries: { data: JournalRow[] };
    filters: { business_date?: string; event?: string };
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }, { title: 'Journal', href: '#' }] } });

function formatMinor(minor: number, currency: string): string {
    return `${currency} ${(minor / 100).toLocaleString(undefined, { minimumFractionDigits: 2 })}`;
}
</script>

<template>
    <Head title="Financial Journal" />
    <div class="space-y-6 p-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold">Financial Journal — {{ branch.name }}</h1>
                <p class="text-sm text-muted-foreground">Append-only. Corrections post as reversals, never edits.</p>
            </div>
            <Button variant="outline" @click="router.reload()">Refresh</Button>
        </div>

        <div class="rounded-lg border">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b text-left text-muted-foreground">
                        <th class="p-3">#</th>
                        <th class="p-3">Date</th>
                        <th class="p-3">Event</th>
                        <th class="p-3">Debit</th>
                        <th class="p-3">Credit</th>
                        <th class="p-3 text-right">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in entries.data" :key="row.id" class="border-b last:border-0">
                        <td class="p-3 font-mono">{{ row.id }}</td>
                        <td class="p-3">{{ row.business_date }}</td>
                        <td class="p-3 font-mono">{{ row.event }}</td>
                        <td class="p-3 font-mono">{{ row.debit_account }}</td>
                        <td class="p-3 font-mono">{{ row.credit_account }}</td>
                        <td class="p-3 text-right font-medium">{{ formatMinor(row.amount_minor, row.currency_code) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
