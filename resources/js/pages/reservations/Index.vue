<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, router, Link, usePage } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import DatePicker from '@/components/ui/date-picker/DatePicker.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { formatDate } from '@/lib/dates';
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

interface Room {
    id: number;
    number: string;
    floor: string;
}

interface RoomType {
    id: number;
    name: string;
    code: string;
}

interface Reservation {
    id: number;
    confirmation_number: string;
    guest_name: string;
    guest_email: string | null;
    guest_phone: string | null;
    adults: number;
    children: number;
    check_in_date: string;
    check_out_date: string;
    status: string;
    room_rate: number;
    total_amount: number;
    amount_paid: number;
    payment_status: string;
    room: Room | null;
    room_type: RoomType;
    created_at: string;
}

const props = defineProps<{
    reservations: {
        data: Reservation[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    filters: {
        status?: string;
        date?: string;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Reservations', href: '/reservations' },
        ],
    },
});

const filterForm = ref({
    status: props.filters.status || 'all',
    date: props.filters.date || '',
});

const statusBadgeVariant: Record<string, string> = {
    pending:
        'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300',
    confirmed: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300',
    reserved:
        'bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-300',
    checked_in:
        'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300',
    checked_out: 'bg-muted text-muted-foreground',
    cancelled: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300',
};

const applyFilters = () => {
    router.get('/reservations', filterForm.value, {
        preserveState: true,
        replace: true,
    });
};

const goToPage = (page: number) => {
    router.get(
        '/reservations',
        { ...filterForm.value, page },
        {
            preserveState: true,
            replace: true,
        },
    );
};
</script>

<template>
    <div class="p-4 md:p-6">
        <div
            class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
        >
            <h1 class="text-foreground text-2xl font-bold">Reservations</h1>
            <Link href="/reservations/create">
                <Button>New Reservation</Button>
            </Link>
        </div>

        <!-- Filters -->
        <div class="mb-4 flex flex-col gap-3 sm:flex-row">
            <div class="w-full sm:w-auto">
                <Select
                    v-model="filterForm.status"
                    @update:model-value="applyFilters"
                    aria-label="Filter by status"
                >
                    <SelectTrigger class="w-full">
                        <SelectValue placeholder="All Statuses" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All Statuses</SelectItem>
                        <SelectItem value="pending">Pending</SelectItem>
                        <SelectItem value="confirmed">Confirmed</SelectItem>
                        <SelectItem value="reserved">Reserved</SelectItem>
                        <SelectItem value="checked_in">Checked In</SelectItem>
                        <SelectItem value="checked_out">Checked Out</SelectItem>
                        <SelectItem value="cancelled">Cancelled</SelectItem>
                    </SelectContent>
                </Select>
            </div>
            <div class="w-full sm:w-auto">
                <DatePicker
                    v-model="filterForm.date"
                    aria-label="Filter by date"
                    placeholder="Filter by date"
                    @change="applyFilters"
                />
            </div>
        </div>

        <!-- Mobile: Card View -->
        <div class="space-y-3 md:hidden">
            <div
                v-for="reservation in reservations.data"
                :key="reservation.id"
                class="bg-card rounded-lg border p-4"
            >
                <div class="mb-2 flex items-start justify-between gap-2">
                    <span class="text-foreground text-sm font-medium">{{
                        reservation.confirmation_number
                    }}</span>
                    <Badge
                        :class="statusBadgeVariant[reservation.status] ?? ''"
                        variant="outline"
                    >
                        {{ reservation.status.replace('_', ' ') }}
                    </Badge>
                </div>
                <div class="text-foreground mb-1 text-sm">
                    {{ reservation.guest_name }}
                </div>
                <div
                    v-if="reservation.guest_email"
                    class="text-muted-foreground mb-2 text-xs"
                >
                    {{ reservation.guest_email }}
                </div>
                <div class="text-muted-foreground mb-1 text-sm">
                    Room: {{ reservation.room?.number || 'Unassigned' }} •
                    {{ reservation.room_type.name }}
                </div>
                <div class="text-muted-foreground mb-2 text-sm">
                    In: {{ formatDate(reservation.check_in_date) }} • Out:
                    {{ formatDate(reservation.check_out_date) }}
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-foreground text-sm font-medium">{{
                        formatCurrency(reservation.total_amount)
                    }}</span>
                    <Link
                        :href="`/reservations/${reservation.id}`"
                        class="text-sm font-medium text-blue-600 hover:underline dark:text-blue-500"
                    >
                        View →
                    </Link>
                </div>
            </div>
            <div
                v-if="reservations.data.length === 0"
                class="bg-card text-muted-foreground rounded-lg border p-8 text-center"
            >
                No reservations found.
            </div>
        </div>

        <!-- Desktop: Table View -->
        <div class="hidden overflow-x-auto rounded-md border md:block">
            <table class="w-full caption-bottom text-sm">
                <thead class="bg-muted/50 border-b [&_tr]:border-b">
                    <tr>
                        <th
                            class="text-muted-foreground h-10 px-2 text-left align-middle font-medium"
                        >
                            Confirmation
                        </th>
                        <th
                            class="text-muted-foreground h-10 px-2 text-left align-middle font-medium"
                        >
                            Guest
                        </th>
                        <th
                            class="text-muted-foreground h-10 px-2 text-left align-middle font-medium"
                        >
                            Room
                        </th>
                        <th
                            class="text-muted-foreground h-10 px-2 text-left align-middle font-medium"
                        >
                            Type
                        </th>
                        <th
                            class="text-muted-foreground h-10 px-2 text-left align-middle font-medium"
                        >
                            Check-in
                        </th>
                        <th
                            class="text-muted-foreground h-10 px-2 text-left align-middle font-medium"
                        >
                            Check-out
                        </th>
                        <th
                            class="text-muted-foreground h-10 px-2 text-left align-middle font-medium"
                        >
                            Status
                        </th>
                        <th
                            class="text-muted-foreground h-10 px-2 text-left align-middle font-medium"
                        >
                            <span class="sr-only">Total</span>
                            <span class="flex justify-end">Total</span>
                        </th>
                        <th
                            class="text-muted-foreground h-10 px-2 text-left align-middle font-medium"
                        >
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="[&_tr:last-child]:border-0">
                    <tr
                        v-for="reservation in reservations.data"
                        :key="reservation.id"
                        class="hover:bg-muted/50 border-b transition-colors"
                    >
                        <td
                            class="text-foreground p-2 align-middle font-medium whitespace-nowrap"
                        >
                            {{ reservation.confirmation_number }}
                        </td>
                        <td class="p-2 align-middle">
                            {{ reservation.guest_name }}
                            <div class="text-muted-foreground text-xs">
                                {{ reservation.guest_email }}
                            </div>
                        </td>
                        <td class="p-2 align-middle">
                            {{ reservation.room?.number || 'Unassigned' }}
                        </td>
                        <td class="p-2 align-middle">
                            {{ reservation.room_type.name }}
                        </td>
                        <td class="p-2 align-middle">
                            {{ formatDate(reservation.check_in_date) }}
                        </td>
                        <td class="p-2 align-middle">
                            {{ formatDate(reservation.check_out_date) }}
                        </td>
                        <td class="p-2 align-middle">
                            <Badge
                                :class="
                                    statusBadgeVariant[reservation.status] ?? ''
                                "
                                variant="outline"
                            >
                                {{ reservation.status.replace('_', ' ') }}
                            </Badge>
                        </td>
                        <td class="p-2 text-right align-middle">
                            {{ formatCurrency(reservation.total_amount) }}
                        </td>
                        <td class="p-2 text-right align-middle">
                            <Link
                                :href="`/reservations/${reservation.id}`"
                                class="font-medium text-blue-600 hover:underline dark:text-blue-500"
                            >
                                View
                            </Link>
                        </td>
                    </tr>
                    <tr v-if="reservations.data.length === 0">
                        <td
                            colspan="9"
                            class="text-muted-foreground p-2 py-8 text-center align-middle"
                        >
                            No reservations found.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div
            v-if="reservations.last_page > 1"
            class="mt-4 flex flex-col items-center justify-between gap-3 sm:flex-row"
        >
            <p class="text-muted-foreground text-sm">
                Showing
                {{
                    (reservations.current_page - 1) * reservations.per_page + 1
                }}
                to
                {{
                    Math.min(
                        reservations.current_page * reservations.per_page,
                        reservations.total,
                    )
                }}
                of {{ reservations.total }} reservations
            </p>
            <div class="flex items-center gap-1">
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="reservations.current_page <= 1"
                    aria-label="Previous page"
                    @click="goToPage(reservations.current_page - 1)"
                >
                    <ChevronLeft class="h-4 w-4" />
                </Button>
                <Button
                    v-for="page in reservations.last_page"
                    :key="page"
                    variant="outline"
                    size="sm"
                    :class="
                        page === reservations.current_page
                            ? 'bg-primary text-primary-foreground'
                            : ''
                    "
                    @click="goToPage(page)"
                >
                    {{ page }}
                </Button>
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="
                        reservations.current_page >= reservations.last_page
                    "
                    aria-label="Next page"
                    @click="goToPage(reservations.current_page + 1)"
                >
                    <ChevronRight class="h-4 w-4" />
                </Button>
            </div>
        </div>
    </div>
</template>
