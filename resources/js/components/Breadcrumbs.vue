<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

type Props = {
    breadcrumbs: BreadcrumbItemType[];
};

defineProps<Props>();
</script>

<template>
    <nav aria-label="Breadcrumb">
        <ol class="inline-flex items-center space-x-1 md:space-x-2">
            <li
                v-for="(item, index) in breadcrumbs"
                :key="index"
                class="inline-flex items-center"
            >
                <svg
                    v-if="index > 0"
                    class="mx-1 h-3 w-3 text-gray-400"
                    aria-hidden="true"
                    xmlns="http://www.w3.org/2000/svg"
                    fill="none"
                    viewBox="0 0 6 10"
                >
                    <path
                        stroke="currentColor"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="m1 9 4-4-4-4"
                    />
                </svg>
                <Link
                    v-if="index < breadcrumbs.length - 1"
                    :href="item.href"
                    class="inline-flex items-center text-sm font-medium text-gray-700 hover:text-blue-600 dark:text-gray-400 dark:hover:text-white"
                >
                    {{ item.title }}
                </Link>
                <span
                    v-else
                    aria-current="page"
                    class="text-sm font-medium text-gray-500 dark:text-gray-400"
                >
                    {{ item.title }}
                </span>
            </li>
        </ol>
    </nav>
</template>
