<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';
import { dashboard } from '@/routes';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    },
});

const pmsFeatures = [
    {
        title: 'Tape Chart',
        description: 'Visual room availability and reservation grid',
        href: '/tape-chart',
        icon: '📊',
    },
    {
        title: 'Rooms',
        description: 'Manage room inventory and status',
        href: '/rooms',
        icon: '🚪',
    },
    {
        title: 'Reservations',
        description: 'View and manage guest reservations',
        href: '/reservations',
        icon: '📅',
    },
    {
        title: 'Housekeeping',
        description: 'Task management and room turnover',
        href: '/housekeeping',
        icon: '🧹',
    },
    {
        title: 'Maintenance',
        description: 'Track maintenance tickets and repairs',
        href: '/maintenance',
        icon: '🔧',
    },
];
</script>

<template>
    <Head title="Dashboard" />

    <div
        class="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4"
    >
        <div class="grid auto-rows-min gap-4 md:grid-cols-3">
            <div
                v-for="feature in pmsFeatures"
                :key="feature.title"
                class="border-sidebar-border/70 dark:border-sidebar-border relative overflow-hidden rounded-xl border p-6 hover:border-gray-400 transition-colors"
            >
                <Link :href="feature.href" class="block">
                    <div class="text-3xl mb-2">{{ feature.icon }}</div>
                    <h3 class="text-lg font-semibold text-gray-900">{{ feature.title }}</h3>
                    <p class="text-sm text-gray-600 mt-1">{{ feature.description }}</p>
                </Link>
            </div>
        </div>
        <div
            class="border-sidebar-border/70 dark:border-sidebar-border relative min-h-[100vh] flex-1 rounded-xl border md:min-h-min"
        >
            <PlaceholderPattern />
        </div>
    </div>
</template>
