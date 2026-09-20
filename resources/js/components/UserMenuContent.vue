<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { LogOut, Settings } from '@lucide/vue';
import UserInfo from '@/components/UserInfo.vue';
import { logout } from '@/routes';
import { edit } from '@/routes/profile';
import type { User } from '@/types';

type Props = {
    user: User;
};

const handleLogout = () => {
    router.flushAll();
};

defineProps<Props>();
</script>

<template>
    <div class="px-4 py-3 text-sm text-gray-900 dark:text-white">
        <UserInfo :user="user" :show-email="true" />
    </div>
    <ul
        class="py-2 text-sm text-gray-700 dark:text-gray-200"
        aria-label="Account menu"
    >
        <li>
            <Link
                :href="edit()"
                prefetch
                class="flex items-center gap-2 px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-600 dark:hover:text-white"
            >
                <Settings class="h-4 w-4" />
                Settings
            </Link>
        </li>
        <li>
            <Link
                :href="logout()"
                as="button"
                type="button"
                class="flex w-full items-center gap-2 px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-600 dark:hover:text-white"
                data-test="logout-button"
                @click="handleLogout"
            >
                <LogOut class="h-4 w-4" />
                Log out
            </Link>
        </li>
    </ul>
</template>
