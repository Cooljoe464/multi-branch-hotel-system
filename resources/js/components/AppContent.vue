<script setup lang="ts">
import { computed } from 'vue';
import type { AppVariant } from '@/types';

type Props = {
    variant?: AppVariant;
    class?: string;
};

const props = withDefaults(defineProps<Props>(), {
    variant: 'sidebar',
});
const className = computed(() => props.class);
</script>

<template>
    <div v-if="props.variant === 'sidebar'" class="relative flex h-full w-full flex-1 flex-col overflow-y-auto" :class="className">
        <slot />
    </div>
    <main
        v-else
        class="mx-auto flex h-full w-full max-w-7xl flex-1 flex-col gap-4 rounded-xl"
        :class="className"
    >
        <slot />
    </main>
</template>
