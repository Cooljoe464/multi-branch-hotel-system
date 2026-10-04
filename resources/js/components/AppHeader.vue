<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { LayoutGrid, Menu, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import {
    DropdownMenu,
    DropdownMenuTrigger,
    DropdownMenuContent,
} from '@/components/ui/dropdown-menu';
import AppLogo from '@/components/AppLogo.vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import PropertySwitcher from '@/components/PropertySwitcher.vue';
import UserMenuContent from '@/components/UserMenuContent.vue';
import { useCan } from '@/composables/useCan';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { getInitials } from '@/composables/useInitials';
import { dashboard } from '@/routes';
import type { BreadcrumbItem, NavItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

const props = withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

const page = usePage();
const auth = computed(() => page.props.auth);
const { isCurrentUrl } = useCurrentUrl();
const { hasRole } = useCan();
const mobileMenuOpen = ref(false);

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
];
</script>

<template>
    <div>
        <nav class="border-border bg-background border-b">
            <div
                class="mx-auto flex h-16 max-w-7xl flex-wrap items-center justify-between px-4"
            >
                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        class="text-muted-foreground hover:bg-accent rounded-lg p-2 lg:hidden"
                        aria-label="Open menu"
                        :aria-expanded="mobileMenuOpen"
                        @click="mobileMenuOpen = !mobileMenuOpen"
                    >
                        <Menu v-if="!mobileMenuOpen" class="h-5 w-5" />
                        <X v-else class="h-5 w-5" />
                    </button>
                    <Link :href="dashboard()" class="flex items-center gap-x-2">
                        <AppLogo />
                    </Link>
                </div>

                <div class="hidden lg:block">
                    <ul class="flex items-center gap-1">
                        <li v-for="item in mainNavItems" :key="item.title">
                            <Link
                                :href="item.href"
                                class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition-colors"
                                :class="
                                    isCurrentUrl(item.href)
                                        ? 'bg-accent text-accent-foreground'
                                        : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground'
                                "
                            >
                                <component
                                    :is="item.icon"
                                    v-if="item.icon"
                                    class="h-4 w-4"
                                />
                                {{ item.title }}
                            </Link>
                        </li>
                    </ul>
                </div>

                <div class="flex items-center gap-2">
                    <PropertySwitcher
                        v-if="
                            hasRole('Global Admin') || hasRole('Property Owner')
                        "
                    />
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <button
                                type="button"
                                class="hover:bg-accent focus-visible:ring-ring rounded-full p-0.5 focus:outline-none focus-visible:ring-2"
                                :aria-label="`Account: ${auth.user?.name}`"
                                aria-haspopup="menu"
                            >
                                <Avatar class="size-8">
                                    <AvatarImage
                                        v-if="auth.user?.avatar"
                                        :src="auth.user.avatar"
                                        :alt="auth.user?.name ?? 'User'"
                                    />
                                    <AvatarFallback class="text-xs font-medium">
                                        {{ getInitials(auth.user?.name) }}
                                    </AvatarFallback>
                                </Avatar>
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-56">
                            <UserMenuContent :user="auth.user" />
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </div>

            <div
                v-if="mobileMenuOpen"
                class="border-border border-t px-4 py-3 lg:hidden"
            >
                <ul class="space-y-1">
                    <li v-for="item in mainNavItems" :key="item.title">
                        <Link
                            :href="item.href"
                            class="text-foreground hover:bg-accent flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium"
                            @click="mobileMenuOpen = false"
                        >
                            <component
                                :is="item.icon"
                                v-if="item.icon"
                                class="h-5 w-5"
                            />
                            {{ item.title }}
                        </Link>
                    </li>
                </ul>
            </div>
        </nav>

        <div
            v-if="props.breadcrumbs.length > 0"
            class="border-border bg-background flex w-full border-b"
        >
            <div
                class="text-muted-foreground mx-auto flex h-12 w-full max-w-7xl items-center justify-start px-4"
            >
                <Breadcrumbs :breadcrumbs="breadcrumbs" />
            </div>
        </div>
    </div>
</template>
