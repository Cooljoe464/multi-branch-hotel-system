<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
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
import Pagination from '@/components/ui/pagination/Pagination.vue';
import { formatDate } from '@/lib/dates';
import { formatCurrency as formatCurrencyRaw, getCurrencySymbol } from '@/lib/format';
import type { RateOverride, RoomType } from '@/types/hms';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Rate Overrides', href: '/rate-overrides' },
        ],
    },
});

const props = defineProps<{
    overrides: {
        data: RateOverride[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    roomTypes: RoomType[];
}>();

const page = usePage();
const branchSymbol = computed(() => (page.props.branch?.current as any)?.currency_symbol || '$');
const resolveSymbol = (code?: string) => getCurrencySymbol(code || 'NGN') || branchSymbol.value;
const formatCurrency = (amount: number, currencyCode?: string) => formatCurrencyRaw(amount, resolveSymbol(currencyCode));

const showCreate = ref(false);

const form = useForm({
    room_type_id: null as number | null,
    start_date: '',
    end_date: '',
    rate_override: null as number | null,
    mlos: null as number | null,
    cta: false,
    ctd: false,
    notes: '',
});

function store() {
    form.post('/rate-overrides', {
        onSuccess: () => {
            showCreate.value = false;
            form.reset();
        },
    });
}

function destroy(id: number) {
    if (confirm('Delete this rate override?')) {
        router.delete(`/rate-overrides/${id}`);
    }
}

function goToPage(page: number) {
    router.get('/rate-overrides', { page }, { preserveState: true, replace: true });
}
</script>

<template>
    <Head title="Rate Overrides" />

    <div class="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-4">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-foreground">Rate Overrides</h1>
                <p class="text-sm text-muted-foreground">Date-based rate rules and stay restrictions</p>
            </div>
            <Button @click="showCreate = !showCreate">
                {{ showCreate ? 'Cancel' : 'New Override' }}
            </Button>
        </div>

        <div v-if="showCreate" class="rounded-xl border bg-card p-6 shadow-sm">
            <h2 class="text-lg font-semibold text-foreground mb-4">Create Rate Override</h2>
            <form @submit.prevent="store" class="grid gap-4 md:grid-cols-3">
                <div class="grid gap-2">
                    <Label>Room Type (optional)</Label>
                    <Select
                        :model-value="form.room_type_id === null ? 'none' : String(form.room_type_id)"
                        @update:model-value="form.room_type_id = $event === 'none' || $event === null ? null : Number($event)"
                    >
                        <SelectTrigger>
                            <SelectValue placeholder="All Room Types" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="none">All Room Types</SelectItem>
                            <SelectItem v-for="rt in roomTypes" :key="rt.id" :value="String(rt.id)">
                                {{ rt.name }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p v-if="form.errors.room_type_id" class="text-sm text-destructive">{{ form.errors.room_type_id }}</p>
                </div>
                <div class="grid gap-2">
                    <Label for="start_date">Start Date</Label>
                    <DatePicker
                        id="start_date"
                        v-model="form.start_date"
                        required
                        :class="form.errors.start_date ? 'border-destructive' : ''"
                    />
                    <p v-if="form.errors.start_date" class="text-sm text-destructive">{{ form.errors.start_date }}</p>
                </div>
                <div class="grid gap-2">
                    <Label for="end_date">End Date</Label>
                    <DatePicker
                        id="end_date"
                        v-model="form.end_date"
                        required
                        :class="form.errors.end_date ? 'border-destructive' : ''"
                    />
                    <p v-if="form.errors.end_date" class="text-sm text-destructive">{{ form.errors.end_date }}</p>
                </div>
                <div class="grid gap-2">
                    <Label for="rate_override">Rate Override (cents)</Label>
                    <Input
                        id="rate_override"
                        v-model.number="form.rate_override"
                        type="number"
                        min="0"
                        placeholder="Use base rate"
                        :class="form.errors.rate_override ? 'border-destructive' : ''"
                    />
                    <p v-if="form.errors.rate_override" class="text-sm text-destructive">{{ form.errors.rate_override }}</p>
                </div>
                <div class="grid gap-2">
                    <Label for="mlos">MLOS (nights)</Label>
                    <Input
                        id="mlos"
                        v-model.number="form.mlos"
                        type="number"
                        min="1"
                        max="30"
                        placeholder="No restriction"
                        :class="form.errors.mlos ? 'border-destructive' : ''"
                    />
                    <p v-if="form.errors.mlos" class="text-sm text-destructive">{{ form.errors.mlos }}</p>
                </div>
                <div class="flex items-end gap-4">
                    <label class="flex items-center gap-2 text-sm text-foreground">
                        <input v-model="form.cta" type="checkbox" class="rounded" />
                        Closed to Arrival
                    </label>
                    <label class="flex items-center gap-2 text-sm text-foreground">
                        <input v-model="form.ctd" type="checkbox" class="rounded" />
                        Closed to Departure
                    </label>
                </div>
                <div class="md:col-span-3">
                    <div class="grid gap-2">
                        <Label for="notes">Notes</Label>
                        <Input
                            id="notes"
                            v-model="form.notes"
                            type="text"
                            placeholder="Optional notes"
                            :class="form.errors.notes ? 'border-destructive' : ''"
                        />
                        <p v-if="form.errors.notes" class="text-sm text-destructive">{{ form.errors.notes }}</p>
                    </div>
                </div>
                <div class="md:col-span-3 flex justify-end">
                    <Button type="submit" :disabled="form.processing">
                        Create Override
                    </Button>
                </div>
            </form>
        </div>

        <div class="rounded-xl border bg-card shadow-sm">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4 p-4">
                <div
                    v-for="override in overrides.data"
                    :key="override.id"
                    class="rounded-lg border bg-card p-5 shadow-sm hover:shadow-md transition-shadow"
                >
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-sm font-semibold text-foreground">
                            {{ roomTypes.find(rt => rt.id === override.room_type_id)?.name ?? 'All Room Types' }}
                        </span>
                        <Badge :variant="override.is_active ? 'default' : 'secondary'">
                            {{ override.is_active ? 'Active' : 'Inactive' }}
                        </Badge>
                    </div>
                    <div class="space-y-2 text-sm mb-3">
                        <div>
                            <span class="text-muted-foreground">Date Range</span>
                            <p class="text-foreground">{{ formatDate(override.start_date) }} to {{ formatDate(override.end_date) }}</p>
                        </div>
                        <div>
                            <span class="text-muted-foreground">Rate Override</span>
                            <p class="text-foreground">
                                {{ override.rate_override ? formatCurrency(override.rate_override) : 'Base Rate' }}
                            </p>
                        </div>
                        <div>
                            <span class="text-muted-foreground">MLOS</span>
                            <p class="text-foreground">{{ override.mlos ? `${override.mlos} night${override.mlos > 1 ? 's' : ''}` : 'No restriction' }}</p>
                        </div>
                        <div>
                            <span class="text-muted-foreground">CTA / CTD</span>
                            <p class="text-foreground">
                                {{ override.cta ? 'CTA' : '' }}{{ override.cta && override.ctd ? ' / ' : '' }}{{ override.ctd ? 'CTD' : '' }}{{ !override.cta && !override.ctd ? 'None' : '' }}
                            </p>
                        </div>
                    </div>
                    <div v-if="override.notes" class="text-xs text-muted-foreground border-t pt-2 mb-2">
                        {{ override.notes }}
                    </div>
                    <div class="flex justify-end border-t pt-2">
                        <button class="font-medium text-sm text-red-600 hover:underline dark:text-red-500" @click="destroy(override.id)">Delete</button>
                    </div>
                </div>
                <div v-if="overrides.data.length === 0" class="col-span-full py-12 text-center text-muted-foreground">
                    No rate overrides configured.
                </div>
            </div>
            <div class="px-4 pb-4">
                <Pagination :data="overrides" label="overrides" @page-change="goToPage" />
            </div>
        </div>
    </div>
</template>
