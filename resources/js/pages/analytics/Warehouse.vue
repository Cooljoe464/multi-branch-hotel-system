<script setup lang="ts">
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';

interface Manifest {
    id: number;
    business_date: string;
    files: Record<string, string>;
    row_counts: Record<string, number>;
    status: string;
    last_error: string | null;
    schema_version: number;
}

const props = defineProps<{ manifests: Manifest[] }>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Warehouse', href: '/analytics' },
        ],
    },
});

const businessDate = ref('');

const runExport = () =>
    router.post('/analytics/warehouse/exports', {
        business_date: businessDate.value,
    });
const verify = (id: number) =>
    router.post(`/analytics/warehouse/manifests/${id}/verify`);

const statusClass = (status: string) =>
    status === 'ready'
        ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200'
        : status === 'failed'
          ? 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200'
          : 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200';
</script>

<template>
    <Head title="Warehouse" />
    <div class="p-6">
        <div class="mb-6 flex items-center justify-between">
            <h1 class="text-foreground text-2xl font-bold">Warehouse Feed</h1>
        </div>

        <div class="mb-6 flex items-end gap-3">
            <div class="grid gap-2">
                <Label>Business date</Label
                ><Input v-model="businessDate" type="date" />
            </div>
            <Button @click="runExport">Run Export</Button>
        </div>

        <div class="border-border rounded-lg border">
            <table class="w-full caption-bottom text-sm">
                <thead class="bg-muted/50">
                    <tr>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Date
                        </th>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Status
                        </th>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Rows
                        </th>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Schema
                        </th>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Error
                        </th>
                        <th
                            class="text-muted-foreground h-12 px-4 text-right font-medium"
                        >
                            Actions
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="m in manifests"
                        :key="m.id"
                        class="border-border hover:bg-muted/50 border-t"
                    >
                        <td class="text-foreground p-4">
                            {{ m.business_date }}
                        </td>
                        <td class="p-4">
                            <Badge
                                variant="outline"
                                :class="statusClass(m.status)"
                                >{{ m.status }}</Badge
                            >
                        </td>
                        <td class="text-muted-foreground p-4 text-xs">
                            {{
                                Object.entries(m.row_counts)
                                    .map(([t, c]) => `${t}: ${c}`)
                                    .join(' · ')
                            }}
                        </td>
                        <td class="text-muted-foreground p-4">
                            v{{ m.schema_version }}
                        </td>
                        <td
                            class="text-muted-foreground max-w-64 truncate p-4 text-xs"
                        >
                            {{ m.last_error ?? '—' }}
                        </td>
                        <td class="p-4 text-right">
                            <Button
                                size="sm"
                                variant="outline"
                                @click="verify(m.id)"
                                >Verify</Button
                            >
                        </td>
                    </tr>
                    <tr v-if="manifests.length === 0">
                        <td
                            colspan="6"
                            class="text-muted-foreground p-4 text-center"
                        >
                            No manifests yet.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
