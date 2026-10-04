<script setup lang="ts">
import { locale, locales, setLocale } from '@/lib/locale';
</script>

<template>
    <div
        class="inline-flex items-center gap-1 text-xs"
        role="group"
        aria-label="Language"
    >
        <button
            v-for="option in locales"
            :key="option.code"
            type="button"
            @click="setLocale(option.code)"
            :aria-pressed="locale === option.code"
            class="rounded px-2 py-1 font-medium transition-colors"
            :class="
                locale === option.code
                    ? 'bg-primary text-primary-foreground'
                    : 'text-muted-foreground hover:text-foreground'
            "
        >
            {{ option.code.toUpperCase() }}
        </button>
    </div>
</template>
