<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';

interface BusinessDate {
    id: number;
    business_date: string;
    status: string;
    opened_at: string | null;
    closed_at: string | null;
    close_summary: Record<string, unknown> | null;
}

const props = defineProps<{
    branch: { id: number; name: string; code: string; timezone: string };
    current: BusinessDate;
    history: BusinessDate[];
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }, { title: 'Business Date', href: '#' }] } });

function advance() {
    router.post(`/branches/${props.branch.id}/business-date/advance`);
}
</script>

<template>
    <Head title="Business Date" />
    <div class="space-y-6 p-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold">Business Date — {{ branch.name }}</h1>
                <p class="text-sm text-muted-foreground">Timezone: {{ branch.timezone }} · All postings anchor to this date.</p>
            </div>
            <div class="flex items-center gap-3">
                <span class="rounded-full bg-emerald-100 px-3 py-1 text-sm font-medium text-emerald-800">
                    {{ current.business_date }} · {{ current.status }}
                </span>
                <Button @click="advance">Advance to next day</Button>
            </div>
        </div>

        <div class="rounded-lg border">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b text-left text-muted-foreground">
                        <th class="p-3">Date</th>
                        <th class="p-3">Status</th>
                        <th class="p-3">Opened</th>
                        <th class="p-3">Closed</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in history" :key="row.id" class="border-b last:border-0">
                        <td class="p-3 font-medium">{{ row.business_date }}</td>
                        <td class="p-3">{{ row.status }}</td>
                        <td class="p-3">{{ row.opened_at ?? '—' }}</td>
                        <td class="p-3">{{ row.closed_at ?? '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
