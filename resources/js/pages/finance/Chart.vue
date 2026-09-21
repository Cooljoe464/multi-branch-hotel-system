<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

interface ChartAccount {
    id: number;
    code: string;
    name: string;
    type: string;
    system: boolean;
}

interface PostingRule {
    id: number;
    event: string;
    debit_account: string;
    credit_account: string;
}

defineProps<{
    branch: { id: number; name: string; code: string };
    accounts: ChartAccount[];
    rules: PostingRule[];
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }, { title: 'Chart of Accounts', href: '#' }] } });
</script>

<template>
    <Head title="Chart of Accounts" />
    <div class="space-y-6 p-6">
        <div>
            <h1 class="text-2xl font-semibold">Chart of Accounts — {{ branch.name }}</h1>
            <p class="text-sm text-muted-foreground">Every journal leg must reference a code below. History is never rewritten.</p>
        </div>

        <div class="rounded-lg border">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b text-left text-muted-foreground">
                        <th class="p-3">Code</th>
                        <th class="p-3">Name</th>
                        <th class="p-3">Type</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="a in accounts" :key="a.id" class="border-b last:border-0">
                        <td class="p-3 font-mono">{{ a.code }}</td>
                        <td class="p-3">{{ a.name }}</td>
                        <td class="p-3">{{ a.type }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="rounded-lg border p-4">
            <h2 class="mb-2 font-semibold">Posting rules (event → legs)</h2>
            <table class="w-full text-sm">
                <tbody>
                    <tr v-for="r in rules" :key="r.id" class="border-t">
                        <td class="py-1 font-mono">{{ r.event }}</td>
                        <td class="py-1 font-mono">Dr {{ r.debit_account }}</td>
                        <td class="py-1 font-mono">Cr {{ r.credit_account }}</td>
                    </tr>
                </tbody>
            </table>
            <p class="mt-2 text-xs text-muted-foreground">Rule changes apply to future postings only. Use the API with accounting.manage_chart to update.</p>
        </div>
    </div>
</template>
