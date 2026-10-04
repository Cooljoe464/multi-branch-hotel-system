<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

interface AuditRun {
    id: number;
    business_date: string;
    status: string;
    steps: Record<string, { status: string }> | null;
    result: Record<string, unknown> | null;
    created_at: string;
}

const props = defineProps<{
    branch: { id: number; name: string; code: string };
    runs: AuditRun[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Night Audit', href: '#' },
        ],
    },
});

const form = useForm({ business_date: new Date().toISOString().slice(0, 10) });

function run() {
    form.post(`/branches/${props.branch.id}/night-audit/run`);
}

function retry(id: number) {
    router.post(`/branches/${props.branch.id}/night-audit/${id}/retry`);
}
</script>

<template>
    <Head title="Night Audit" />
    <div class="space-y-6 p-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold">
                    Night Audit — {{ branch.name }}
                </h1>
                <p class="text-muted-foreground text-sm">
                    Idempotent and resumable. Re-running a date never
                    double-posts.
                </p>
            </div>
        </div>

        <form
            @submit.prevent="run"
            class="flex items-end gap-2 rounded-lg border p-4"
        >
            <div class="grid gap-2">
                <Label>Business date</Label>
                <Input v-model="form.business_date" type="date" required />
            </div>
            <Button type="submit">Run audit</Button>
        </form>

        <div class="rounded-lg border">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-muted-foreground border-b text-left">
                        <th class="p-3">Date</th>
                        <th class="p-3">Status</th>
                        <th class="p-3">Steps</th>
                        <th class="p-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="r in runs"
                        :key="r.id"
                        class="border-b last:border-0"
                    >
                        <td class="p-3 font-medium">{{ r.business_date }}</td>
                        <td class="p-3">{{ r.status }}</td>
                        <td class="p-3 font-mono text-xs">
                            {{
                                r.steps ? Object.keys(r.steps).join(', ') : '—'
                            }}
                        </td>
                        <td class="p-3 text-right">
                            <Button
                                v-if="
                                    r.status === 'failed' ||
                                    r.status === 'running'
                                "
                                variant="outline"
                                size="sm"
                                @click="retry(r.id)"
                                >Resume</Button
                            >
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
