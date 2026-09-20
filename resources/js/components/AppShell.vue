<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { provide, ref } from 'vue';
import { SIDEBAR_STATE_KEY, type SidebarState } from '@/composables/useSidebar';
import type { AppVariant } from '@/types';

type Props = {
    variant?: AppVariant;
};

withDefaults(defineProps<Props>(), {
    variant: 'sidebar',
});

const page = usePage();
const sidebarOpen = ref(
    typeof page.props.sidebarOpen === 'boolean'
        ? page.props.sidebarOpen
        : true,
);
const mobileSidebarOpen = ref(false);

function toggleSidebar(): void {
    sidebarOpen.value = !sidebarOpen.value;
    try {
        document.cookie = `sidebar_state=${sidebarOpen.value}; path=/; max-age=31536000`;
    } catch {
        // cookies unavailable (e.g. in tests) — state still works in-memory
    }
}

provide<SidebarState>(SIDEBAR_STATE_KEY, {
    sidebarOpen,
    mobileSidebarOpen,
    toggleSidebar,
    openMobileSidebar: () => {
        mobileSidebarOpen.value = true;
    },
    closeMobileSidebar: () => {
        mobileSidebarOpen.value = false;
    },
});
</script>

<template>
    <div v-if="variant === 'header'" class="flex min-h-screen w-full flex-col">
        <slot />
    </div>
    <div v-else class="flex h-screen w-full overflow-hidden bg-gray-50 dark:bg-gray-900">
        <slot />
    </div>
</template>
