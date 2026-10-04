<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
</script>

<template>
    <Head title="Page not found" />
    <div class="bg-background flex min-h-screen items-center justify-center">
        <div class="text-center">
            <p class="text-muted-foreground text-sm font-medium">404</p>
            <h1
                class="text-foreground mt-2 text-2xl font-semibold tracking-tight"
            >
                Page not found
            </h1>
            <p class="text-muted-foreground mt-2 max-w-md text-sm">
                The page you're looking for doesn't exist or has been moved.
            </p>
            <div class="mt-6">
                <Link href="/">
                    <Button>Go home</Button>
                </Link>
            </div>
        </div>
    </div>
</template>
