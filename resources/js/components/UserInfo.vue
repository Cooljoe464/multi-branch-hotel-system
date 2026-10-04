<script setup lang="ts">
import { computed } from 'vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/composables/useInitials';
import type { User } from '@/types';

type Props = {
    user: User;
    showEmail?: boolean;
};

const props = withDefaults(defineProps<Props>(), {
    showEmail: false,
});

const { getInitials } = useInitials();

const showAvatar = computed(
    () => props.user.avatar && props.user.avatar !== '',
);
</script>

<template>
    <div class="flex items-center gap-2">
        <Avatar class="h-8 w-8 overflow-hidden rounded-lg">
            <AvatarImage
                v-if="showAvatar"
                :src="user.avatar!"
                :alt="user.name"
            />
            <AvatarFallback>{{ getInitials(user.name) }}</AvatarFallback>
        </Avatar>

        <div class="grid flex-1 text-left text-sm leading-tight">
            <span class="truncate font-medium">{{ user.name }}</span>
            <span
                v-if="showEmail"
                class="text-muted-foreground truncate text-xs"
                >{{ user.email }}</span
            >
        </div>
    </div>
</template>
