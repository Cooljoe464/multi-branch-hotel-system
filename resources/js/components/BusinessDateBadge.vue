<script setup lang="ts">
defineProps<{
    businessDate: string;
    status: string;
}>();
</script>

<template>
    <span
        class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-700"
        :title="`Business date status: ${status}`"
    >
        {{ businessDate }}
    </span>
</template>
