<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
</script>

<template>
    <Head title="Page not found" />
    <div class="flex min-h-screen items-center justify-center bg-background">
        <div class="text-center">
            <p class="text-sm font-medium text-muted-foreground">404</p>
            <h1 class="mt-2 text-2xl font-semibold tracking-tight text-foreground">Page not found</h1>
            <p class="mt-2 max-w-md text-sm text-muted-foreground">
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
