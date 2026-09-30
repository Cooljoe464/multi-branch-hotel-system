<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    Activity,
    BarChart3,
    BedDouble,
    Building2,
    Calculator,
    CalendarDays,
    CalendarRange,
    ChevronRight,
    Database,
    DollarSign,
    FileText,
    FileSpreadsheet,
    LayoutGrid,
    MessagesSquare,
    Plus,
    ReceiptText,
    Settings,
    Shield,
    Sparkles,
    Tablet,
    Terminal,
    TriangleAlert,
    TrendingUp,
    Users,
    Wifi,
    Wrench,
    X,
    UtensilsCrossed,
    MapPin,
    Package,
    ArrowLeftRight,
    Banknote,
    Monitor,
    CookingPot,
    WashingMachine,
    Globe,
    Map,
    ShieldAlert,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import {
    DropdownMenu,
    DropdownMenuTrigger,
    DropdownMenuContent,
} from '@/components/ui/dropdown-menu';
import { Collapsible, CollapsibleTrigger, CollapsibleContent } from '@/components/ui/collapsible';
import AppLogo from '@/components/AppLogo.vue';
import UserMenuContent from '@/components/UserMenuContent.vue';
import { useCan } from '@/composables/useCan';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { getInitials } from '@/composables/useInitials';
import { useSidebar } from '@/composables/useSidebar';
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

const { can, hasRole } = useCan();
const { isCurrentUrl, isCurrentOrParentUrl } = useCurrentUrl();
const { sidebarOpen, mobileSidebarOpen, closeMobileSidebar } = useSidebar();
const page = usePage();
const auth = computed(() => page.props.auth);

const branchId = computed(() => page.props.branch?.current?.id ?? null);
const branchLink = (path: string) => (branchId.value !== null ? `/branches/${branchId.value}${path}` : '#');

const isFrontDesk = computed(() => hasRole('front_desk'));

const allNavItems = computed<NavItem[]>(() => [
    {
        title: 'Dashboard',
        href: isFrontDesk.value ? '/front-desk' : dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Operations',
        href: '/tape-chart',
        icon: BedDouble,
        children: [
            { title: 'Tape Chart', href: '/tape-chart', icon: CalendarRange, permission: 'tape_chart.view' },
            { title: 'Reservations', href: '/reservations', icon: CalendarDays, permission: 'reservations.view' },
            { title: 'Rooms', href: '/rooms', icon: BedDouble, permission: 'rooms.view' },
            { title: 'Housekeeping', href: '/housekeeping', icon: Sparkles, permission: 'housekeeping.view' },
            { title: 'HK Schedule', href: branchLink('/housekeeping/schedule'), icon: CalendarDays, permission: 'housekeeping.assign', requiresBranch: true },
            { title: 'Maintenance', href: '/maintenance', icon: Wrench, permission: 'maintenance.view' },
            { title: 'Asset Health', href: branchLink('/maintenance/health'), icon: Activity, permission: 'maintenance.manage_assets', requiresBranch: true },
            { title: 'Tablets', href: branchLink('/tablets'), icon: Tablet, permission: 'rooms.manage', requiresBranch: true },
        ],
    },
    {
        title: 'Finance',
        href: '/folios',
        icon: ReceiptText,
        children: [
            { title: 'Folios', href: '/folios', icon: ReceiptText, permission: 'folios.view' },
            { title: 'City Ledger', href: '/city-ledger', icon: Banknote, permission: 'city_ledger.view' },
            { title: 'Audit Flags', href: '/audit/flags', icon: ShieldAlert, permission: 'audit.view' },
            { title: 'Anomalies', href: branchLink('/anomalies'), icon: TriangleAlert, permission: 'audit.view', requiresBranch: true },
            { title: 'Accounting', href: branchLink('/accounting'), icon: Calculator, permission: 'accounting_export.view', requiresBranch: true },
        ],
    },
    {
        title: 'Analytics',
        href: '/analytics',
        icon: BarChart3,
        children: [
            { title: 'Analytics', href: '/analytics', icon: BarChart3, permission: 'analytics.view' },
            { title: 'Reports', href: '/reports', icon: FileText, permission: 'reports.view' },
            { title: 'Warehouse', href: '/analytics/warehouse', icon: Database, permission: 'analytics.view' },
            { title: 'Ask', href: branchLink('/reports/ask'), icon: MessagesSquare, permission: 'analytics.view', requiresBranch: true },
        ],
    },
    {
        title: 'Revenue',
        href: '/yield-rules',
        icon: DollarSign,
        children: [
            { title: 'Yield Rules', href: '/yield-rules', icon: DollarSign, permission: 'yield_rules.view' },
            { title: 'Rate Overrides', href: '/rate-overrides', icon: Settings, permission: 'rate_overrides.view' },
            { title: 'Forecast', href: branchLink('/forecast'), icon: TrendingUp, permission: 'analytics.view', requiresBranch: true },
        ],
    },
    {
        title: 'Food & Beverage',
        href: '/menu-items',
        icon: UtensilsCrossed,
        children: [
            { title: 'Menu Items', href: '/menu-items', icon: UtensilsCrossed, permission: 'menu_items.view' },
            { title: 'Outlets', href: '/outlets', icon: MapPin, permission: 'outlets.view' },
            { title: 'KDS', href: '/kds', icon: CookingPot, permission: 'kds.view' },
            { title: 'POS', href: '/pos', icon: Monitor, permission: 'pos.view' },
            { title: 'Inventory', href: '/inventory', icon: Package, permission: 'inventory.view' },
            { title: 'Transfers', href: '/transfers', icon: ArrowLeftRight, permission: 'transfers.view' },
        ],
    },
    {
        title: 'Services',
        href: '/laundry',
        icon: WashingMachine,
        children: [
            { title: 'Laundry', href: '/laundry', icon: WashingMachine, permission: 'laundry.view' },
            { title: 'Channels', href: '/channels', icon: Globe, permission: 'channels.view' },
            { title: 'CRS', href: '/crs', icon: Map, permission: 'crs.view' },
            { title: 'Connectivity', href: branchLink('/connectivity'), icon: Wifi, permission: 'telecom.view', requiresBranch: true },
        ],
    },
    {
        title: 'Guests',
        href: '/guests',
        icon: Users,
        children: [
            { title: 'Do Not Rent', href: branchLink('/dnr'), icon: TriangleAlert, permission: 'guests.manage_dnr', requiresBranch: true },
            { title: 'Merge Duplicates', href: branchLink('/guests/merges'), icon: Users, permission: 'guests.merge', requiresBranch: true },
        ],
    },
    {
        title: 'Administration',
        href: '/admin/branches/create',
        icon: Building2,
        children: [
            { title: 'New Branch', href: '/admin/branches/create', icon: Plus, permission: 'branches.manage' },
            { title: 'Users', href: '/admin/users', icon: Users, permission: 'users.view' },
            { title: 'Roles & Permissions', href: '/admin/roles', icon: Shield, permission: 'roles.view' },
            { title: 'Import Rooms', href: '/admin/import/rooms', icon: FileSpreadsheet, permission: 'branches.manage' },
            { title: 'Import Guests', href: '/admin/import/guests', icon: FileSpreadsheet, permission: 'branches.manage' },
            { title: 'Import Reservations', href: '/admin/import/reservations', icon: FileSpreadsheet, permission: 'branches.manage' },
            { title: 'Payment Guard', href: '/settings/payment-guard', icon: ShieldAlert, permission: 'settings.manage' },
            { title: 'Developers', href: '/developers', icon: Terminal, permission: 'api.view' },
            { title: 'Database', href: '/admin/database', icon: Activity, permission: 'system_health.view' },
        ],
    },
]);

const openGroups = ref<Set<string>>(new Set());

function isGroupActive(group: NavItem): boolean {
    if (!group.children) return false;
    return group.children.some(
        (child) => !child.permission || can(child.permission) ? isCurrentOrParentUrl(child.href) : false,
    );
}

function visibleChildren(group: NavItem): NavItem[] {
    if (!group.children) return [];
    return group.children.filter(
        (child) => (!child.permission || can(child.permission)) && (!child.requiresBranch || branchId.value !== null),
    );
}

function isGroupOpen(group: NavItem): boolean {
    if (openGroups.value.has(group.title)) return true;
    return isGroupActive(group);
}

function toggleGroup(group: NavItem) {
    if (openGroups.value.has(group.title)) {
        openGroups.value.delete(group.title);
    } else {
        openGroups.value.add(group.title);
    }
}

const groupedNavItems = computed(() => {
    return allNavItems.value.filter((item) => {
        if (!item.children) return true;
        return visibleChildren(item).length > 0;
    });
});

function standaloneLinkClasses(href: NavItem['href']): string {
    const base =
        'group flex items-center rounded-lg p-2 text-sm font-medium text-sidebar-foreground hover:bg-sidebar-accent transition-colors';
    return isCurrentUrl(href)
        ? `${base} bg-sidebar-accent text-sidebar-accent-foreground`
        : base;
}

function groupButtonClasses(group: NavItem): string {
    const base =
        'group flex w-full items-center rounded-lg p-2 text-sm font-medium text-sidebar-foreground hover:bg-sidebar-accent transition-colors';
    return isGroupActive(group)
        ? `${base} bg-sidebar-accent text-sidebar-accent-foreground`
        : base;
}

function childLinkClasses(href: NavItem['href']): string {
    const base =
        'group flex items-center rounded-lg py-1.5 pl-9 pr-2 text-sm font-medium text-sidebar-foreground/80 hover:bg-sidebar-accent transition-colors';
    return isCurrentUrl(href)
        ? `${base} bg-sidebar-accent text-sidebar-accent-foreground`
        : base;
}
</script>

<template>
    <aside
        class="fixed inset-y-0 left-0 z-40 w-64 shrink-0 -translate-x-full border-r border-sidebar-border bg-sidebar transition-transform duration-200 ease-in-out lg:static lg:h-screen lg:translate-x-0"
        :class="{
            'translate-x-0': mobileSidebarOpen,
            'lg:w-64': sidebarOpen,
            'lg:w-16': !sidebarOpen,
        }"
        aria-label="Sidebar"
    >
        <div class="flex h-full flex-col overflow-y-auto px-3 py-4">
            <div class="mb-4 flex items-center justify-between px-1">
                <Link
                    :href="dashboard()"
                    class="flex min-w-0 items-center gap-2"
                    :class="{ 'lg:justify-center': !sidebarOpen }"
                >
                    <AppLogo />
                </Link>
                <button
                    type="button"
                    class="rounded-lg p-1.5 text-muted-foreground hover:bg-sidebar-accent lg:hidden"
                    aria-label="Close menu"
                    @click="closeMobileSidebar"
                >
                    <X class="h-5 w-5" />
                </button>
            </div>

            <nav class="flex-1 space-y-1" aria-label="Platform">
                <template v-for="item in groupedNavItems" :key="item.title">
                    <!-- Standalone link (no children) -->
                    <Link
                        v-if="!item.children"
                        :href="item.href"
                        :class="standaloneLinkClasses(item.href)"
                        :title="item.title"
                        @click="closeMobileSidebar"
                    >
                        <component
                            :is="item.icon"
                            class="h-5 w-5 shrink-0 text-sidebar-foreground/60 transition-colors group-hover:text-sidebar-foreground"
                        />
                        <span
                            class="ms-3 flex-1 whitespace-nowrap"
                            :class="{ 'lg:hidden': !sidebarOpen }"
                        >{{ item.title }}</span>
                    </Link>

                    <!-- Group with children -->
                    <Collapsible
                        v-else
                        :open="isGroupOpen(item)"
                    >
                        <CollapsibleTrigger as-child>
                            <button
                                type="button"
                                :class="groupButtonClasses(item)"
                                :title="item.title"
                                @click="toggleGroup(item)"
                            >
                                <component
                                    :is="item.icon"
                                    class="h-5 w-5 shrink-0 text-sidebar-foreground/60 transition-colors group-hover:text-sidebar-foreground"
                                />
                                <span
                                    class="ms-3 flex-1 whitespace-nowrap text-left"
                                    :class="{ 'lg:hidden': !sidebarOpen }"
                                >{{ item.title }}</span>
                                <ChevronRight
                                    class="h-4 w-4 shrink-0 text-sidebar-foreground/40 transition-transform duration-200"
                                    :class="{
                                        'rotate-90': isGroupOpen(item),
                                        'lg:hidden': !sidebarOpen,
                                    }"
                                />
                            </button>
                        </CollapsibleTrigger>
                        <CollapsibleContent>
                            <Link
                                v-for="child in visibleChildren(item)"
                                :key="child.title"
                                :href="child.href"
                                :class="childLinkClasses(child.href)"
                                :title="child.title"
                                @click="closeMobileSidebar"
                            >
                                <component
                                    :is="child.icon"
                                    class="h-4 w-4 shrink-0 text-sidebar-foreground/60 transition-colors group-hover:text-sidebar-foreground"
                                />
                                <span
                                    class="ms-3 flex-1 whitespace-nowrap"
                                    :class="{ 'lg:hidden': !sidebarOpen }"
                                >{{ child.title }}</span>
                            </Link>
                        </CollapsibleContent>
                    </Collapsible>
                </template>
            </nav>

            <div class="mt-4 border-t border-sidebar-border pt-4">
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <button
                            type="button"
                            class="flex w-full items-center gap-2 rounded-lg p-2 text-left hover:bg-sidebar-accent focus:outline-none"
                            :aria-label="`Account: ${auth.user?.name}`"
                            aria-haspopup="menu"
                        >
                            <Avatar class="size-8 shrink-0">
                                <AvatarImage
                                    v-if="auth.user?.avatar"
                                    :src="auth.user.avatar"
                                    :alt="auth.user?.name ?? 'User'"
                                />
                                <AvatarFallback class="text-xs font-medium">
                                    {{ getInitials(auth.user?.name) }}
                                </AvatarFallback>
                            </Avatar>
                            <span
                                class="min-w-0 flex-1"
                                :class="{ 'lg:hidden': !sidebarOpen }"
                            >
                                <span
                                    class="block truncate text-sm font-medium text-sidebar-foreground"
                                >
                                    {{ auth.user?.name }}
                                </span>
                                <span
                                    class="block truncate text-xs text-sidebar-foreground/60"
                                >
                                    {{ auth.user?.email }}
                                </span>
                            </span>
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent side="top" class="w-56">
                        <UserMenuContent :user="auth.user" />
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </div>
    </aside>

    <div
        v-if="mobileSidebarOpen"
        class="fixed inset-0 z-30 bg-black/50 lg:hidden"
        aria-hidden="true"
        @click="closeMobileSidebar"
    />
</template>
