<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

interface IdempotencyRow {
    id: number;
    scope: string;
    key: string;
    status: string;
    updated_at: string;
}

defineProps<{
    branch: { id: number; name: string; code: string };
    keys: IdempotencyRow[];
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }, { title: 'Idempotency Keys', href: '#' }] } });
</script>

<template>
    <Head title="Idempotency Keys" />
    <div class="space-y-6 p-6">
        <div>
            <h1 class="text-2xl font-semibold">Idempotency Keys — {{ branch.name }}</h1>
            <p class="text-sm text-muted-foreground">Read-only replay inspector. Keys are immutable; completed keys replay instead of re-executing.</p>
        </div>

        <div class="rounded-lg border">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b text-left text-muted-foreground">
                        <th class="p-3">Scope</th>
                        <th class="p-3">Key</th>
                        <th class="p-3">Status</th>
                        <th class="p-3">Updated</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in keys" :key="row.id" class="border-b last:border-0">
                        <td class="p-3 font-mono">{{ row.scope }}</td>
                        <td class="p-3 font-mono">{{ row.key.slice(0, 8) }}…</td>
                        <td class="p-3">{{ row.status }}</td>
                        <td class="p-3">{{ row.updated_at }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
