<script setup lang="ts">
import { Badge } from '@/components/ui/badge';

interface AuditFlag {
    id: number;
    flag_type: string;
    severity: string;
    description: string;
    evidence: any;
    is_reviewed: boolean;
    reviewer: { name: string } | null;
    reviewed_at: string | null;
    created_at: string;
}

const props = defineProps<{ auditFlag: AuditFlag }>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Audit Flags', href: '/audit/flags' },
            { title: 'Details', href: '#' },
        ],
    },
});

const severityBadge: Record<string, string> = {
    low: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300',
    medium: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300',
    high: 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-300',
    critical: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300',
};
</script>

<template>
    <div class="p-6">
        <div class="mb-6">
            <h1 class="text-foreground text-2xl font-bold">
                Audit Flag #{{ auditFlag.id }}
            </h1>
        </div>
        <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
            <div class="bg-card border-border rounded-lg border p-4">
                <h2 class="text-foreground mb-2 font-semibold">Details</h2>
                <dl class="space-y-1 text-sm">
                    <dt class="text-muted-foreground">Type</dt>
                    <dd class="text-foreground capitalize">
                        {{ auditFlag.flag_type.replace('_', ' ') }}
                    </dd>
                    <dt class="text-muted-foreground">Severity</dt>
                    <dd>
                        <Badge
                            :class="severityBadge[auditFlag.severity]"
                            variant="outline"
                            class="capitalize"
                            >{{ auditFlag.severity }}</Badge
                        >
                    </dd>
                    <dt class="text-muted-foreground">Description</dt>
                    <dd class="text-foreground">{{ auditFlag.description }}</dd>
                    <dt class="text-muted-foreground">Created</dt>
                    <dd class="text-foreground">
                        {{ new Date(auditFlag.created_at).toLocaleString() }}
                    </dd>
                </dl>
            </div>
            <div class="bg-card border-border rounded-lg border p-4">
                <h2 class="text-foreground mb-2 font-semibold">Review</h2>
                <dl class="space-y-1 text-sm">
                    <dt class="text-muted-foreground">Status</dt>
                    <dd>
                        <Badge
                            :class="
                                auditFlag.is_reviewed
                                    ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300'
                                    : 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300'
                            "
                            variant="outline"
                            >{{
                                auditFlag.is_reviewed ? 'Reviewed' : 'Pending'
                            }}</Badge
                        >
                    </dd>
                    <dt v-if="auditFlag.reviewer" class="text-muted-foreground">
                        Reviewed by
                    </dt>
                    <dd v-if="auditFlag.reviewer" class="text-foreground">
                        {{ auditFlag.reviewer.name }}
                    </dd>
                    <dt
                        v-if="auditFlag.reviewed_at"
                        class="text-muted-foreground"
                    >
                        Reviewed at
                    </dt>
                    <dd v-if="auditFlag.reviewed_at" class="text-foreground">
                        {{ new Date(auditFlag.reviewed_at).toLocaleString() }}
                    </dd>
                </dl>
            </div>
        </div>
    </div>
</template>
