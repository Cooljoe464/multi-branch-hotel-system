<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { computed } from 'vue';

type PaginatedData = {
    data: unknown[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
};

const props = withDefaults(
    defineProps<{
        data: PaginatedData;
        label?: string;
    }>(),
    {
        label: 'items',
    },
);

const emit = defineEmits<{
    (e: 'page-change', page: number): void;
}>();

const showingFrom = computed(() => (props.data.current_page - 1) * props.data.per_page + 1);
const showingTo = computed(() => Math.min(props.data.current_page * props.data.per_page, props.data.total));

const visiblePages = computed(() => {
    const { current_page, last_page } = props.data;
    const pages: (number | '...')[] = [];

    if (last_page <= 7) {
        for (let i = 1; i <= last_page; i++) {
            pages.push(i);
        }
        return pages;
    }

    pages.push(1);

    if (current_page > 3) {
        pages.push('...');
    }

    const start = Math.max(2, current_page - 1);
    const end = Math.min(last_page - 1, current_page + 1);

    for (let i = start; i <= end; i++) {
        pages.push(i);
    }

    if (current_page < last_page - 2) {
        pages.push('...');
    }

    pages.push(last_page);

    return pages;
});
</script>

<template>
    <div v-if="data.last_page > 1" class="mt-4 flex flex-col items-center justify-between gap-3 sm:flex-row">
        <p class="text-sm text-muted-foreground">
            Showing {{ showingFrom }} to {{ showingTo }} of {{ data.total }} {{ label }}
        </p>
        <div class="flex items-center gap-1">
            <Button
                variant="outline"
                size="sm"
                :disabled="data.current_page <= 1"
                @click="emit('page-change', data.current_page - 1)"
            >
                <ChevronLeft class="h-4 w-4" />
            </Button>
            <template v-for="(page, index) in visiblePages" :key="index">
                <span v-if="page === '...'" class="px-2 text-sm text-muted-foreground">...</span>
                <Button
                    v-else
                    variant="outline"
                    size="sm"
                    :class="page === data.current_page ? 'bg-primary text-primary-foreground' : ''"
                    @click="emit('page-change', page)"
                >
                    {{ page }}
                </Button>
            </template>
            <Button
                variant="outline"
                size="sm"
                :disabled="data.current_page >= data.last_page"
                @click="emit('page-change', data.current_page + 1)"
            >
                <ChevronRight class="h-4 w-4" />
            </Button>
        </div>
    </div>
</template>
