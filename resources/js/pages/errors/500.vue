<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Server error',
                href: '#',
            },
        ],
    },
});
</script>

<template>
    <Head title="Server error" />
    <div class="flex min-h-[60vh] flex-col items-center justify-center gap-4 text-center">
        <p class="text-sm font-medium text-muted-foreground">500</p>
        <h1 class="text-2xl font-semibold tracking-tight text-foreground">Something went wrong</h1>
        <p class="max-w-md text-sm text-muted-foreground">
            An unexpected error occurred. Our team has been notified.
            Please try again or return to the dashboard.
        </p>
        <div class="flex gap-3">
            <Link :href="dashboard()">
                <Button>Back to dashboard</Button>
            </Link>
            <Button variant="outline" @click="$inertia.reload()">Try again</Button>
        </div>
    </div>
</template>
