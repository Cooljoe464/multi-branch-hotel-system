<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';

interface Approval {
    id: number;
    subject_type: string | null;
    subject_id: number | null;
    status: string;
    note: string | null;
    reason_code: { code: string; kind: string } | null;
}

interface Code {
    id: number;
    code: string;
    kind: string;
    requires_supervisor: boolean;
}

defineProps<{
    branch: { id: number; name: string; code: string };
    approvals: Approval[];
    codes: Code[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Voids & Refunds', href: '#' },
        ],
    },
});

function approve(id: number) {
    router.post(`/void-approvals/${id}/approve`);
}

function reject(id: number) {
    router.post(`/void-approvals/${id}/reject`);
}
</script>

<template>
    <Head title="Voids & Refunds" />
    <div class="space-y-6 p-6">
        <div>
            <h1 class="text-2xl font-semibold">
                Voids & Refunds — {{ branch.name }}
            </h1>
            <p class="text-muted-foreground text-sm">
                Protected codes need a second user to approve. Self-approval is
                rejected.
            </p>
        </div>

        <div class="rounded-lg border">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-muted-foreground border-b text-left">
                        <th class="p-3">#</th>
                        <th class="p-3">Subject</th>
                        <th class="p-3">Code</th>
                        <th class="p-3">Status</th>
                        <th class="p-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="a in approvals"
                        :key="a.id"
                        class="border-b last:border-0"
                    >
                        <td class="p-3 font-mono">{{ a.id }}</td>
                        <td class="p-3 font-mono text-xs">
                            {{ a.subject_type }} #{{ a.subject_id }}
                        </td>
                        <td class="p-3 font-mono">{{ a.reason_code?.code }}</td>
                        <td class="p-3">{{ a.status }}</td>
                        <td class="p-3 text-right">
                            <template v-if="a.status === 'pending'">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    class="mr-2"
                                    @click="approve(a.id)"
                                    >Approve</Button
                                >
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    @click="reject(a.id)"
                                    >Reject</Button
                                >
                            </template>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
