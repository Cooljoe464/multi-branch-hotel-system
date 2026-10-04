<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import Pagination from '@/components/ui/pagination/Pagination.vue';

interface AuditFlag {
    id: number;
    flag_type: string;
    severity: string;
    description: string;
    is_reviewed: boolean;
    created_at: string;
}

const props = defineProps<{
    auditFlags: {
        data: AuditFlag[];
        current_page: number;
        last_page: number;
        total: number;
    };
    filters: { severity?: string; reviewed?: string };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Audit Flags', href: '/audit/flags' },
        ],
    },
});

const severityBadge: Record<string, string> = {
    low: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300',
    medium: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300',
    high: 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-300',
    critical: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300',
};

const review = (id: number) => router.post(`/audit/flags/${id}/review`);
const suppress = (id: number) => {
    if (confirm('Suppress this flag?'))
        router.post(`/audit/flags/${id}/suppress`);
};
const goToPage = (page: number) =>
    router.get(
        '/audit/flags',
        { page },
        { preserveState: true, replace: true },
    );
</script>

<template>
    <div class="p-6">
        <h1 class="text-foreground mb-6 text-2xl font-bold">Audit Flags</h1>
        <div class="border-border rounded-lg border">
            <table class="w-full caption-bottom text-sm">
                <thead class="bg-muted/50">
                    <tr>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Type
                        </th>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Severity
                        </th>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Description
                        </th>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Reviewed
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
                        v-for="flag in auditFlags.data"
                        :key="flag.id"
                        class="border-border hover:bg-muted/50 border-t"
                    >
                        <td class="text-foreground p-4 capitalize">
                            {{ flag.flag_type.replace('_', ' ') }}
                        </td>
                        <td class="p-4">
                            <Badge
                                :class="severityBadge[flag.severity]"
                                variant="outline"
                                class="capitalize"
                                >{{ flag.severity }}</Badge
                            >
                        </td>
                        <td class="text-muted-foreground max-w-xs truncate p-4">
                            {{ flag.description }}
                        </td>
                        <td class="p-4">
                            <Badge
                                :class="
                                    flag.is_reviewed
                                        ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300'
                                        : 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300'
                                "
                                variant="outline"
                                >{{
                                    flag.is_reviewed ? 'Reviewed' : 'Pending'
                                }}</Badge
                            >
                        </td>
                        <td class="p-4 text-right">
                            <div class="flex justify-end gap-1">
                                <Button
                                    v-if="!flag.is_reviewed"
                                    variant="outline"
                                    size="sm"
                                    @click="review(flag.id)"
                                    >Review</Button
                                >
                                <Button
                                    v-if="!flag.is_reviewed"
                                    variant="destructive"
                                    size="sm"
                                    @click="suppress(flag.id)"
                                    >Suppress</Button
                                >
                                <Button
                                    variant="outline"
                                    size="sm"
                                    @click="
                                        router.get(`/audit/flags/${flag.id}`)
                                    "
                                    >View</Button
                                >
                            </div>
                        </td>
                    </tr>
                    <tr v-if="auditFlags.data.length === 0">
                        <td
                            colspan="5"
                            class="text-muted-foreground p-4 text-center"
                        >
                            No audit flags.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="mt-4">
            <Pagination
                :data="auditFlags"
                label="flags"
                @page-change="goToPage"
            />
        </div>
    </div>
</template>
