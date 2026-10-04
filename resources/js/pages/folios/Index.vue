<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { formatDate } from '@/lib/dates';
import Pagination from '@/components/ui/pagination/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogFooter,
} from '@/components/ui/dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Badge } from '@/components/ui/badge';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Folios', href: '/folios' },
        ],
    },
});

interface Reservation {
    id: number;
    confirmation_number: string;
    guest_name: string;
    room: { number: string } | null;
}

interface Folio {
    id: number;
    folio_number: string;
    type: string;
    status: string;
    description: string | null;
    guest_name: string | null;
    balance: number;
    is_settled: boolean;
    created_at: string;
    closed_at: string | null;
    reservation: Reservation | null;
    parent_folio_id: number | null;
}

const props = defineProps<{
    folios: {
        data: Folio[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    filters: {
        search?: string;
        type?: string;
        status?: string;
    };
}>();

const showCreateModal = ref(false);
const createForm = ref({
    type: 'staff',
    guest_name: '',
    description: '',
});

const filterForm = ref({
    search: props.filters.search || '',
    type: props.filters.type || 'all',
    status: props.filters.status || 'all',
});

let searchTimeout: ReturnType<typeof setTimeout>;
const onSearch = () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        applyFilters();
    }, 300);
};

const applyFilters = () => {
    router.get('/folios', filterForm.value, {
        preserveState: true,
        replace: true,
    });
};

const goToPage = (page: number) => {
    router.get(
        '/folios',
        { ...filterForm.value, page },
        { preserveState: true, replace: true },
    );
};

const submitCreate = () => {
    router.post('/folios', createForm.value, {
        onSuccess: () => {
            showCreateModal.value = false;
            createForm.value = {
                type: 'staff',
                guest_name: '',
                description: '',
            };
        },
    });
};

import {
    formatCurrency as formatCurrencyRaw,
    getCurrencySymbol,
} from '@/lib/format';
const page = usePage();
const branchSymbol = computed(
    () => (page.props.branch?.current as any)?.currency_symbol || '$',
);
const resolveSymbol = (code?: string) =>
    getCurrencySymbol(code || 'NGN') || branchSymbol.value;
const formatCurrency = (amount: number, currencyCode?: string) =>
    formatCurrencyRaw(amount, resolveSymbol(currencyCode));

const getStatusBadgeClass = (status: string) => {
    const classes: Record<string, string> = {
        open: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300',
        closed: 'bg-muted text-muted-foreground',
        transferred:
            'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300',
    };
    return classes[status] || 'bg-muted text-muted-foreground';
};

const getTypeBadgeClass = (type: string) => {
    const classes: Record<string, string> = {
        master: 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-300',
        child: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300',
        individual:
            'bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-300',
        staff: 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-300',
        non_guest:
            'bg-teal-100 text-teal-800 dark:bg-teal-900 dark:text-teal-300',
    };
    return classes[type] || 'bg-muted text-muted-foreground';
};

const getGuestName = (folio: Folio) => {
    if (folio.reservation) return folio.reservation.guest_name;
    if (folio.guest_name) return folio.guest_name;
    return 'No guest';
};
</script>

<template>
    <div class="p-4 md:p-6">
        <div
            class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
        >
            <h1 class="text-foreground text-2xl font-bold">Folios</h1>
            <Button @click="showCreateModal = true">New Folio</Button>
        </div>

        <!-- Search -->
        <div class="mb-4">
            <Input
                v-model="filterForm.search"
                placeholder="Search by folio number, guest name, or confirmation..."
                class="max-w-md"
                @input="onSearch"
            />
        </div>

        <!-- Filters -->
        <div class="mb-4 flex flex-col gap-3 sm:flex-row">
            <Select
                v-model="filterForm.status"
                @update:model-value="applyFilters"
                aria-label="Filter by status"
            >
                <SelectTrigger class="w-full sm:w-[180px]">
                    <SelectValue placeholder="All Statuses" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">All Statuses</SelectItem>
                    <SelectItem value="open">Open</SelectItem>
                    <SelectItem value="closed">Closed</SelectItem>
                    <SelectItem value="transferred">Transferred</SelectItem>
                </SelectContent>
            </Select>
            <Select
                v-model="filterForm.type"
                @update:model-value="applyFilters"
                aria-label="Filter by type"
            >
                <SelectTrigger class="w-full sm:w-[180px]">
                    <SelectValue placeholder="All Types" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">All Types</SelectItem>
                    <SelectItem value="individual">Individual</SelectItem>
                    <SelectItem value="master">Master</SelectItem>
                    <SelectItem value="child">Child</SelectItem>
                    <SelectItem value="staff">Staff</SelectItem>
                    <SelectItem value="non_guest">Non-Guest</SelectItem>
                </SelectContent>
            </Select>
        </div>

        <!-- Mobile: Card View -->
        <div class="space-y-3 md:hidden">
            <Link
                v-for="folio in folios.data"
                :key="folio.id"
                :href="`/folios/${folio.id}`"
                class="bg-card border-border block rounded-lg border p-4 shadow transition-shadow hover:shadow-md"
            >
                <div class="mb-2 flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <div
                            class="font-mono text-sm font-medium text-indigo-600 dark:text-indigo-400"
                        >
                            {{ folio.folio_number }}
                        </div>
                        <div
                            class="text-foreground truncate text-sm font-medium"
                        >
                            {{ getGuestName(folio) }}
                        </div>
                    </div>
                    <Badge
                        class="shrink-0"
                        :class="getStatusBadgeClass(folio.status)"
                    >
                        {{ folio.status }}
                    </Badge>
                </div>
                <div class="flex items-center justify-between text-sm">
                    <Badge :class="getTypeBadgeClass(folio.type)">
                        {{ folio.type.replace('_', ' ') }}
                    </Badge>
                    <span
                        class="font-medium"
                        :class="
                            folio.balance > 0
                                ? 'text-red-600 dark:text-red-400'
                                : folio.balance < 0
                                  ? 'text-green-600 dark:text-green-400'
                                  : 'text-foreground'
                        "
                    >
                        {{ formatCurrency(folio.balance) }}
                    </span>
                </div>
                <div
                    v-if="folio.reservation?.room"
                    class="text-muted-foreground mt-2 text-xs"
                >
                    Room {{ folio.reservation.room.number }} &middot;
                    {{ folio.reservation.confirmation_number }}
                </div>
                <div class="text-muted-foreground mt-1 text-xs">
                    {{ formatDate(folio.created_at) }}
                </div>
            </Link>
            <div
                v-if="folios.data.length === 0"
                class="text-muted-foreground py-8 text-center"
            >
                No folios found.
            </div>
        </div>

        <!-- Desktop: Table View -->
        <div class="bg-card hidden overflow-hidden rounded-lg shadow md:block">
            <table class="divide-border min-w-full divide-y">
                <thead class="bg-muted/50">
                    <tr>
                        <th
                            class="text-muted-foreground px-4 py-3 text-left text-xs font-medium uppercase"
                        >
                            Folio Number
                        </th>
                        <th
                            class="text-muted-foreground px-4 py-3 text-left text-xs font-medium uppercase"
                        >
                            Type
                        </th>
                        <th
                            class="text-muted-foreground px-4 py-3 text-left text-xs font-medium uppercase"
                        >
                            Guest / Reservation
                        </th>
                        <th
                            class="text-muted-foreground px-4 py-3 text-left text-xs font-medium uppercase"
                        >
                            Status
                        </th>
                        <th
                            class="text-muted-foreground px-4 py-3 text-right text-xs font-medium uppercase"
                        >
                            Balance
                        </th>
                        <th
                            class="text-muted-foreground px-4 py-3 text-left text-xs font-medium uppercase"
                        >
                            Created
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-border divide-y">
                    <tr
                        v-for="folio in folios.data"
                        :key="folio.id"
                        class="hover:bg-muted/50"
                    >
                        <td class="px-4 py-3">
                            <Link
                                :href="`/folios/${folio.id}`"
                                class="font-mono text-sm font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
                            >
                                {{ folio.folio_number }}
                            </Link>
                        </td>
                        <td class="px-4 py-3">
                            <Badge :class="getTypeBadgeClass(folio.type)">
                                {{ folio.type.replace('_', ' ') }}
                            </Badge>
                        </td>
                        <td class="px-4 py-3">
                            <div v-if="folio.reservation" class="text-sm">
                                <div class="text-foreground font-medium">
                                    {{ folio.reservation.guest_name }}
                                </div>
                                <div class="text-muted-foreground">
                                    {{ folio.reservation.confirmation_number }}
                                </div>
                                <div
                                    v-if="folio.reservation.room"
                                    class="text-muted-foreground"
                                >
                                    Room {{ folio.reservation.room.number }}
                                </div>
                            </div>
                            <div v-else-if="folio.guest_name" class="text-sm">
                                <div class="text-foreground font-medium">
                                    {{ folio.guest_name }}
                                </div>
                                <div class="text-muted-foreground">
                                    {{
                                        folio.description ||
                                        folio.type.replace('_', ' ')
                                    }}
                                </div>
                            </div>
                            <span
                                v-else
                                class="text-muted-foreground text-sm"
                                >{{ folio.description || 'No guest' }}</span
                            >
                        </td>
                        <td class="px-4 py-3">
                            <Badge :class="getStatusBadgeClass(folio.status)">
                                {{ folio.status }}
                            </Badge>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <span
                                class="text-sm font-medium"
                                :class="
                                    folio.balance > 0
                                        ? 'text-red-600 dark:text-red-400'
                                        : folio.balance < 0
                                          ? 'text-green-600 dark:text-green-400'
                                          : 'text-foreground'
                                "
                            >
                                {{ formatCurrency(folio.balance) }}
                            </span>
                        </td>
                        <td class="text-muted-foreground px-4 py-3 text-sm">
                            {{ formatDate(folio.created_at) }}
                        </td>
                    </tr>
                    <tr v-if="folios.data.length === 0">
                        <td
                            colspan="6"
                            class="text-muted-foreground px-4 py-8 text-center"
                        >
                            No folios found.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div v-if="folios.last_page > 1" class="mt-4">
            <Pagination :data="folios" label="folios" @page-change="goToPage" />
        </div>

        <!-- Create Folio Modal -->
        <Dialog v-model:open="showCreateModal">
            <DialogContent class="sm:max-w-[500px]">
                <DialogHeader>
                    <DialogTitle>Create New Folio</DialogTitle>
                </DialogHeader>
                <form @submit.prevent="submitCreate">
                    <div class="space-y-4 py-4">
                        <div class="grid gap-2">
                            <Label>Folio Type</Label>
                            <Select v-model="createForm.type">
                                <SelectTrigger>
                                    <SelectValue placeholder="Select type" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="staff"
                                        >Staff / House Account</SelectItem
                                    >
                                    <SelectItem value="non_guest"
                                        >Non-Guest / Internal</SelectItem
                                    >
                                </SelectContent>
                            </Select>
                        </div>
                        <div class="grid gap-2">
                            <Label for="guest_name">Guest / Account Name</Label>
                            <Input
                                id="guest_name"
                                v-model="createForm.guest_name"
                                required
                                placeholder="e.g. Hotel Manager, Corporate XYZ"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="description">Description</Label>
                            <Input
                                id="description"
                                v-model="createForm.description"
                                placeholder="Optional description"
                            />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="showCreateModal = false"
                            >Cancel</Button
                        >
                        <Button type="submit">Create Folio</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
