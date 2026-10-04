<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'System Health', href: '#' },
        ],
    },
});

const page = usePage();
const horizonUrl = computed(() => '/horizon');
</script>

<template>
    <Head title="System Health" />
    <div class="space-y-6 p-6">
        <div>
            <h1 class="text-2xl font-semibold">System Health</h1>
            <p class="text-muted-foreground text-sm">
                Liveness, queue depth and background workers. Horizon dashboard
                linked below.
            </p>
        </div>

        <div class="grid gap-4 md:grid-cols-3">
            <a :href="horizonUrl" class="hover:bg-muted rounded-lg border p-4">
                <div class="text-muted-foreground text-sm">Queue dashboard</div>
                <div class="text-lg font-semibold">Horizon</div>
            </a>
            <a href="/healthz" class="hover:bg-muted rounded-lg border p-4">
                <div class="text-muted-foreground text-sm">Liveness probe</div>
                <div class="text-lg font-semibold">/healthz</div>
            </a>
            <a href="/readyz" class="hover:bg-muted rounded-lg border p-4">
                <div class="text-muted-foreground text-sm">Readiness probe</div>
                <div class="text-lg font-semibold">/readyz</div>
            </a>
        </div>
    </div>
</template>
