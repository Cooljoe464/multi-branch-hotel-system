<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editBranding } from '@/routes/branding';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { NavItem } from '@/types';
import { computed } from 'vue';

const page = usePage();
const permissions = computed(() => page.props.auth.permissions as string[]);
const isGlobalAdmin = computed(() =>
    permissions.value.includes('branches.manage'),
);
const canManageOutlets = computed(() =>
    permissions.value.includes('outlets.manage'),
);

const sidebarNavItems = computed<NavItem[]>(() => {
    const items: NavItem[] = [
        {
            title: 'Profile',
            href: editProfile(),
        },
        {
            title: 'Security',
            href: editSecurity(),
        },
        {
            title: 'Appearance',
            href: editAppearance(),
        },
    ];

    if (isGlobalAdmin.value) {
        items.push({
            title: 'Branding',
            href: editBranding(),
        });
        items.push({
            title: 'Currency',
            href: '/settings/currency',
        });
    }

    if (canManageOutlets.value) {
        items.push({
            title: 'Cashier POS Access',
            href: '/settings/cashier-outlets',
        });
    }

    items.push(
        {
            title: 'Yield Rules',
            href: '/yield-rules',
        },
        {
            title: 'Rate Overrides',
            href: '/rate-overrides',
        },
    );

    return items;
});

const { isCurrentOrParentUrl } = useCurrentUrl();
</script>

<template>
    <div class="px-4 py-6">
        <Heading
            title="Settings"
            description="Manage your profile and account settings"
        />

        <div class="flex flex-col lg:flex-row lg:space-x-12">
            <aside class="w-full max-w-xl lg:w-48">
                <nav
                    class="flex flex-col space-y-1 space-x-0"
                    aria-label="Settings"
                >
                    <Link
                        v-for="item in sidebarNavItems"
                        :key="toUrl(item.href)"
                        :href="item.href"
                        class="border-border bg-background text-foreground hover:bg-accent hover:text-accent-foreground focus:ring-ring inline-flex w-full items-center justify-start gap-2 rounded-lg border px-5 py-2.5 text-sm font-medium focus:z-10 focus:ring-4"
                        :class="{
                            'ring-2 ring-blue-500 dark:ring-blue-500':
                                isCurrentOrParentUrl(item.href),
                        }"
                    >
                        <component :is="item.icon" class="h-4 w-4" />
                        {{ item.title }}
                    </Link>
                </nav>
            </aside>

            <hr class="border-border my-6 lg:hidden" />

            <div class="flex-1">
                <section class="space-y-12">
                    <slot />
                </section>
            </div>
        </div>
    </div>
</template>
