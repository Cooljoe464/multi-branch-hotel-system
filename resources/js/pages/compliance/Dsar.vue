<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

interface Dsar {
    id: number;
    guest_id: number;
    kind: string;
    status: string;
    created_at: string;
    guest: { email: string } | null;
}

interface Policy {
    id: number;
    data_class: string;
    retain_days: number;
    action: string;
}

const props = defineProps<{
    branch: { id: number; name: string };
    requests: { data: Dsar[] };
    policies: Policy[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Privacy', href: '#' },
        ],
    },
});

const form = useForm({ guest_id: undefined as number | undefined, kind: 'access' });
const policyForm = useForm({
    data_class: '',
    retain_days: 365,
    action: 'anonymize',
});
const rejectForm = useForm({ reason: '' });

const base = `/branches/${props.branch.id}/privacy`;

function dueDate(created: string): string {
    return new Date(new Date(created).getTime() + 30 * 86400000)
        .toISOString()
        .slice(0, 10);
}
</script>

<template>
    <Head title="Privacy & DSAR" />
    <div class="space-y-8 p-6">
        <div>
            <h1 class="text-2xl font-semibold">Privacy — {{ branch.name }}</h1>
            <p class="text-muted-foreground text-sm">
                DSAR intake with a 30-day clock, fulfillment bundles, and
                retention rules. Journals are never purged.
            </p>
        </div>

        <section class="space-y-3">
            <h2 class="text-lg font-medium">New request</h2>
            <form
                @submit.prevent="
                    form.post(`${base}/dsar`, { onSuccess: () => form.reset() })
                "
                class="flex flex-wrap items-end gap-3 rounded-lg border p-4"
            >
                <div class="grid gap-2">
                    <Label>Guest id</Label
                    ><Input
                        v-model.number="form.guest_id"
                        type="number"
                        required
                    />
                </div>
                <div class="grid gap-2">
                    <Label>Kind</Label>
                    <select
                        v-model="form.kind"
                        class="rounded-md border p-2 text-sm"
                    >
                        <option value="access">Access</option>
                        <option value="erasure">Erasure</option>
                        <option value="portability">Portability</option>
                    </select>
                </div>
                <Button type="submit">Open request</Button>
            </form>
        </section>

        <section class="space-y-3">
            <h2 class="text-lg font-medium">Requests</h2>
            <div class="rounded-lg border">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-muted-foreground border-b text-left">
                            <th class="p-3">ID</th>
                            <th class="p-3">Guest</th>
                            <th class="p-3">Kind</th>
                            <th class="p-3">Status</th>
                            <th class="p-3">Due</th>
                            <th class="p-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="r in requests.data"
                            :key="r.id"
                            class="border-b last:border-0"
                        >
                            <td class="p-3 font-medium">#{{ r.id }}</td>
                            <td class="p-3 font-mono text-xs">
                                {{ r.guest?.email ?? `#${r.guest_id}` }}
                            </td>
                            <td class="p-3">{{ r.kind }}</td>
                            <td class="p-3 font-mono text-xs">
                                {{ r.status }}
                            </td>
                            <td class="p-3 font-mono text-xs">
                                {{ dueDate(r.created_at) }}
                            </td>
                            <td class="p-3">
                                <div
                                    v-if="r.status === 'open'"
                                    class="flex justify-end gap-1"
                                >
                                    <Button
                                        size="sm"
                                        @click="
                                            router.post(
                                                `${base}/dsar/${r.id}/fulfill`,
                                            )
                                        "
                                        >Fulfill</Button
                                    >
                                    <Input
                                        v-model="rejectForm.reason"
                                        placeholder="Reject reason"
                                        class="w-40"
                                    />
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        @click="
                                            rejectForm.post(
                                                `${base}/dsar/${r.id}/reject`,
                                            )
                                        "
                                        >Reject</Button
                                    >
                                </div>
                                <div v-else class="flex justify-end">
                                    <a
                                        v-if="r.kind !== 'erasure'"
                                        :href="`${base}/dsar/${r.id}/download`"
                                        ><Button variant="outline" size="sm"
                                            >Bundle</Button
                                        ></a
                                    >
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="space-y-3">
            <h2 class="text-lg font-medium">Retention policies</h2>
            <form
                @submit.prevent="
                    policyForm.post(`${base}/retention`, {
                        onSuccess: () => policyForm.reset(),
                    })
                "
                class="flex flex-wrap items-end gap-3 rounded-lg border p-4"
            >
                <div class="grid gap-2">
                    <Label>Data class</Label
                    ><Input
                        v-model="policyForm.data_class"
                        placeholder="id_scans"
                        required
                    />
                </div>
                <div class="grid gap-2">
                    <Label>Retain (days)</Label
                    ><Input
                        v-model.number="policyForm.retain_days"
                        type="number"
                        min="1"
                    />
                </div>
                <div class="grid gap-2">
                    <Label>Action</Label>
                    <select
                        v-model="policyForm.action"
                        class="rounded-md border p-2 text-sm"
                    >
                        <option value="anonymize">Anonymize</option>
                        <option value="purge">Purge</option>
                    </select>
                </div>
                <Button type="submit">Save policy</Button>
            </form>
            <div class="rounded-lg border">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-muted-foreground border-b text-left">
                            <th class="p-3">Class</th>
                            <th class="p-3">Retain</th>
                            <th class="p-3">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="p in policies"
                            :key="p.id"
                            class="border-b last:border-0"
                        >
                            <td class="p-3 font-medium">{{ p.data_class }}</td>
                            <td class="p-3">{{ p.retain_days }} days</td>
                            <td class="p-3 font-mono text-xs">
                                {{ p.action }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
