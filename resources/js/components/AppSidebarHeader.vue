<script setup lang="ts">
import { PanelLeft } from '@lucide/vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import PropertySwitcher from '@/components/PropertySwitcher.vue';
import { useCan } from '@/composables/useCan';
import { useSidebar } from '@/composables/useSidebar';
import type { BreadcrumbItem } from '@/types';

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);

const { toggleSidebar, openMobileSidebar } = useSidebar();
const { hasRole } = useCan();
</script>

<template>
    <header
        class="sticky top-0 z-20 flex h-16 shrink-0 items-center gap-2 border-b border-border bg-background px-4"
    >
        <button
            type="button"
            class="hidden rounded-lg p-2 text-muted-foreground hover:bg-accent lg:inline-flex"
            aria-label="Toggle sidebar"
            @click="toggleSidebar"
        >
            <PanelLeft class="h-5 w-5" />
        </button>
        <button
            type="button"
            class="rounded-lg p-2 text-muted-foreground hover:bg-accent lg:hidden"
            aria-label="Open menu"
            @click="openMobileSidebar"
        >
            <PanelLeft class="h-5 w-5" />
        </button>
        <template v-if="breadcrumbs && breadcrumbs.length > 0">
            <Breadcrumbs :breadcrumbs="breadcrumbs" />
        </template>
        <div class="ms-auto flex items-center gap-2">
            <PropertySwitcher v-if="hasRole('Global Admin') || hasRole('Property Owner')" />
        </div>
    </header>
</template>
