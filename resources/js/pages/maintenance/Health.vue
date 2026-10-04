<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';

interface Branch {
    id: number;
    name: string;
}
interface AssetRow {
    id: number;
    name: string;
    category: string;
    room: string | null;
    failure_prob: number | null;
    scored_on: string | null;
    signals: Record<string, number | boolean>;
    draft_ticket_id: number | null;
    draft_status: string | null;
}

const props = defineProps<{
    branch: Branch;
    assets: AssetRow[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Asset Health', href: '/maintenance' },
        ],
    },
});

const rescore = () =>
    router.post(`/branches/${props.branch.id}/maintenance/health/rescore`);
const publish = (id: number) =>
    router.post(
        `/branches/${props.branch.id}/maintenance/drafts/${id}/publish`,
    );
const discard = (id: number) =>
    router.delete(`/branches/${props.branch.id}/maintenance/drafts/${id}`);

const riskClass = (p: number | null) =>
    p === null
        ? ''
        : p >= 0.8
          ? 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200'
          : p >= 0.6
            ? 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200'
            : 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200';
</script>

<template>
    <Head title="Asset Health" />
    <div class="p-6">
        <div class="mb-6 flex items-center justify-between">
            <h1 class="text-foreground text-2xl font-bold">
                Asset Health — {{ branch.name }}
            </h1>
            <Button variant="outline" @click="rescore">Re-score Now</Button>
        </div>

        <div class="border-border rounded-lg border">
            <table class="w-full caption-bottom text-sm">
                <thead class="bg-muted/50">
                    <tr>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Asset
                        </th>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Room
                        </th>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Failure Risk
                        </th>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Signals
                        </th>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            PM Draft
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
                        v-for="a in assets"
                        :key="a.id"
                        class="border-border hover:bg-muted/50 border-t"
                    >
                        <td class="p-4">
                            <span class="text-foreground font-medium">{{
                                a.name
                            }}</span>
                            <span class="text-muted-foreground text-xs">{{
                                a.category
                            }}</span>
                        </td>
                        <td class="text-muted-foreground p-4">
                            {{ a.room ?? '—' }}
                        </td>
                        <td class="p-4">
                            <Badge
                                v-if="a.failure_prob !== null"
                                variant="outline"
                                :class="riskClass(a.failure_prob)"
                                >{{ Math.round(a.failure_prob * 100) }}%</Badge
                            >
                            <span v-else class="text-muted-foreground">—</span>
                        </td>
                        <td class="text-muted-foreground p-4 font-mono text-xs">
                            {{
                                Object.entries(a.signals)
                                    .map(([k, v]) => `${k}=${v}`)
                                    .join(' ') || '—'
                            }}
                        </td>
                        <td class="p-4">
                            <Badge v-if="a.draft_ticket_id" variant="outline"
                                >{{ a.draft_status }} #{{
                                    a.draft_ticket_id
                                }}</Badge
                            >
                            <span v-else class="text-muted-foreground">—</span>
                        </td>
                        <td class="p-4">
                            <div
                                v-if="
                                    a.draft_ticket_id &&
                                    a.draft_status === 'draft'
                                "
                                class="flex justify-end gap-2"
                            >
                                <Button
                                    size="sm"
                                    @click="publish(a.draft_ticket_id)"
                                    >Publish</Button
                                >
                                <Button
                                    size="sm"
                                    variant="outline"
                                    @click="discard(a.draft_ticket_id)"
                                    >Discard</Button
                                >
                            </div>
                        </td>
                    </tr>
                    <tr v-if="assets.length === 0">
                        <td
                            colspan="6"
                            class="text-muted-foreground p-4 text-center"
                        >
                            No assets tracked at this property.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="text-muted-foreground mt-3 text-xs">
            Automation drafts PM work only. Rooms are never locked out
            automatically — outages go through the normal room-condition flow
            after GM review.
        </p>
    </div>
</template>
