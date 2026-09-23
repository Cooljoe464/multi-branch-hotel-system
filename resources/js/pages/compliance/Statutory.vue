<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

interface Report {
    id: number;
    kind: string;
    period_from: string;
    period_to: string;
    status: string;
    file_hash: string | null;
    summary: { rows: number; annex: number; completeness_bps: number } | null;
}

const props = defineProps<{
    branch: { id: number; name: string };
    reports: { data: Report[] };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Statutory', href: '#' },
        ],
    },
});

const form = useForm({ kind: 'immigration', from: '', to: '' });

const base = `/branches/${props.branch.id}/compliance/statutory`;
</script>

<template>
    <Head title="Statutory Reports" />
    <div class="space-y-6 p-6">
        <div>
            <h1 class="text-2xl font-semibold">
                Statutory Reports — {{ branch.name }}
            </h1>
            <p class="text-muted-foreground text-sm">
                Immigration, police and tourism-board registers. Same range
                always yields the same hashed file.
            </p>
        </div>

        <form
            @submit.prevent="form.post(base, { onSuccess: () => form.reset() })"
            class="flex flex-wrap items-end gap-3 rounded-lg border p-4"
        >
            <div class="grid gap-2">
                <Label>Kind</Label>
                <select
                    v-model="form.kind"
                    class="rounded-md border p-2 text-sm"
                >
                    <option value="immigration">Immigration</option>
                    <option value="police">Police</option>
                    <option value="tourism_board">Tourism board</option>
                </select>
            </div>
            <div class="grid gap-2">
                <Label>From</Label
                ><Input v-model="form.from" type="date" required />
            </div>
            <div class="grid gap-2">
                <Label>To</Label
                ><Input v-model="form.to" type="date" required />
            </div>
            <Button type="submit">Generate</Button>
        </form>

        <div class="rounded-lg border">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-muted-foreground border-b text-left">
                        <th class="p-3">Kind</th>
                        <th class="p-3">Period</th>
                        <th class="p-3">Status</th>
                        <th class="p-3">Rows / annex</th>
                        <th class="p-3">Complete</th>
                        <th class="p-3">Hash</th>
                        <th class="p-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="r in reports.data"
                        :key="r.id"
                        class="border-b last:border-0"
                    >
                        <td class="p-3 font-medium">{{ r.kind }}</td>
                        <td class="p-3 font-mono text-xs">
                            {{ r.period_from }} → {{ r.period_to }}
                        </td>
                        <td class="p-3 font-mono text-xs">{{ r.status }}</td>
                        <td class="p-3 font-mono text-xs">
                            {{ r.summary?.rows ?? '—' }} /
                            {{ r.summary?.annex ?? '—' }}
                        </td>
                        <td class="p-3 font-mono text-xs">
                            {{
                                r.summary
                                    ? `${(r.summary.completeness_bps / 100).toFixed(1)}%`
                                    : '—'
                            }}
                        </td>
                        <td class="p-3 font-mono text-xs">
                            {{
                                r.file_hash
                                    ? `${r.file_hash.slice(0, 12)}…`
                                    : '—'
                            }}
                        </td>
                        <td class="p-3">
                            <div class="flex justify-end gap-1">
                                <a
                                    v-if="r.status === 'ready'"
                                    :href="`${base}/${r.id}/download`"
                                    ><Button variant="outline" size="sm"
                                        >Download</Button
                                    ></a
                                >
                                <Button
                                    v-else
                                    variant="outline"
                                    size="sm"
                                    @click="
                                        router.post(`${base}/${r.id}/resubmit`)
                                    "
                                    >Resubmit</Button
                                >
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
