<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Access denied',
                href: '#',
            },
        ],
    },
});
</script>

<template>
    <Head title="Access denied" />
    <div class="flex min-h-[60vh] flex-col items-center justify-center gap-4 text-center">
        <p class="text-sm font-medium text-muted-foreground">403</p>
        <h1 class="text-2xl font-semibold tracking-tight text-foreground">You don't have access to this page</h1>
        <p class="max-w-md text-sm text-muted-foreground">
            Your role doesn't include permission for this area. If you need access, ask your
            Branch GM or Global Admin.
        </p>
        <Link :href="dashboard()">
            <Button>Back to dashboard</Button>
        </Link>
    </div>
</template>
