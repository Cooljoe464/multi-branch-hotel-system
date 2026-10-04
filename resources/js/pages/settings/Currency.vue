<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref, reactive, watch } from 'vue';
import { Loader2 } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import Heading from '@/components/Heading.vue';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Currency settings',
                href: '/settings/currency',
            },
        ],
    },
});

const CURRENCY_MAP: Record<string, string> = {
    USD: '$',
    EUR: '€',
    GBP: '£',
    CAD: 'C$',
    AUD: 'A$',
    SGD: 'S$',
    INR: '₹',
    AED: 'د.إ',
    NGN: '₦',
};

const CURRENCIES = [
    'NGN',
    'USD',
    'EUR',
    'GBP',
    'CAD',
    'AUD',
    'SGD',
    'INR',
    'AED',
];

const page = usePage();
const globalCurrency = computed(
    () =>
        page.props.globalCurrency as {
            currency_code: string;
            currency_symbol: string;
        },
);
const branches = computed(
    () =>
        page.props.branches as Array<{
            id: number;
            name: string;
            currency_code: string;
            currency_symbol: string;
        }>,
);
const rates = computed(
    () =>
        page.props.rates as Array<{
            id: number;
            base_code: string;
            quote_code: string;
            rate_date: string;
            rate: number;
            source: string;
        }>,
);

const form = reactive({
    currency_code: globalCurrency.value.currency_code,
    currency_symbol: globalCurrency.value.currency_symbol,
    branch_currencies: branches.value.map((b) => ({
        id: b.id,
        name: b.name,
        currency_code: b.currency_code,
        currency_symbol: b.currency_symbol,
    })),
});

const processing = ref(false);
const rateForm = reactive({
    base_code: 'USD',
    quote_code: 'NGN',
    rate_date: new Date().toISOString().split('T')[0],
    rate: 1500,
    source: 'manual',
});

function onGlobalCurrencyChange(value: string) {
    form.currency_code = value;
    form.currency_symbol = CURRENCY_MAP[value] ?? '$';
}

function onBranchCurrencyChange(index: number, value: string) {
    form.branch_currencies[index].currency_code = value;
    form.branch_currencies[index].currency_symbol = CURRENCY_MAP[value] ?? '$';
}

function submit() {
    processing.value = true;

    router.put(
        '/settings/currency',
        {
            currency_code: form.currency_code,
            currency_symbol: form.currency_symbol,
            branch_currencies: form.branch_currencies.map((b) => ({
                id: b.id,
                currency_code: b.currency_code,
                currency_symbol: b.currency_symbol,
            })),
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                processing.value = false;
            },
            onError: () => {
                processing.value = false;
            },
        },
    );
}

function submitRate() {
    router.post(
        '/settings/fx-rates',
        { ...rateForm },
        { preserveScroll: true },
    );
}

function deleteRate(id: number) {
    router.delete(`/settings/fx-rates/${id}`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Currency settings" />

    <h1 class="sr-only">Currency settings</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Currency"
            description="Set the global default currency and override per branch"
        />

        <form @submit.prevent="submit" class="space-y-8">
            <!-- Global Default -->
            <div class="space-y-4">
                <div class="grid gap-2">
                    <Label>Global Default Currency</Label>
                    <Select
                        :model-value="form.currency_code"
                        @update:model-value="onGlobalCurrencyChange"
                    >
                        <SelectTrigger class="w-full max-w-md">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="code in CURRENCIES"
                                :key="code"
                                :value="code"
                            >
                                {{ code }} ({{ CURRENCY_MAP[code] }})
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p class="text-muted-foreground text-sm">
                        This is the fallback currency used when a branch has not
                        set its own.
                    </p>
                </div>
            </div>

            <!-- Per-Branch Overrides -->
            <div class="space-y-4">
                <Heading
                    variant="small"
                    title="Branch Overrides"
                    description="Set a different currency for each branch. Leave as the global default to inherit."
                />

                <div class="space-y-3">
                    <div
                        v-for="(branch, index) in form.branch_currencies"
                        :key="branch.id"
                        class="border-border flex items-center gap-4 rounded-lg border p-4"
                    >
                        <span
                            class="text-foreground min-w-[140px] text-sm font-medium"
                            >{{ branch.name }}</span
                        >
                        <Select
                            :model-value="branch.currency_code"
                            @update:model-value="
                                onBranchCurrencyChange(index, $event)
                            "
                        >
                            <SelectTrigger class="w-full max-w-[200px]">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="code in CURRENCIES"
                                    :key="code"
                                    :value="code"
                                >
                                    {{ code }} ({{ CURRENCY_MAP[code] }})
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <span class="text-muted-foreground text-sm">{{
                            branch.currency_symbol
                        }}</span>
                    </div>
                </div>
            </div>

            <div class="flex items-center gap-4">
                <Button type="submit" :disabled="processing">
                    <Loader2
                        v-if="processing"
                        class="mr-2 size-4 animate-spin"
                    />
                    Save
                </Button>
            </div>
        </form>

        <Heading
            variant="small"
            title="Exchange Rates"
            description="Daily reference rates used for foreign-currency conversion (1 unit of base buys the quoted units)"
        />

        <form
            @submit.prevent="submitRate"
            class="border-border flex flex-wrap items-end gap-3 rounded-lg border p-4"
        >
            <div class="grid gap-2">
                <Label>Base</Label>
                <Select v-model="rateForm.base_code">
                    <SelectTrigger class="w-[120px]"
                        ><SelectValue
                    /></SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="code in CURRENCIES"
                            :key="code"
                            :value="code"
                            >{{ code }}</SelectItem
                        >
                    </SelectContent>
                </Select>
            </div>
            <div class="grid gap-2">
                <Label>Quote</Label>
                <Select v-model="rateForm.quote_code">
                    <SelectTrigger class="w-[120px]"
                        ><SelectValue
                    /></SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="code in CURRENCIES"
                            :key="code"
                            :value="code"
                            >{{ code }}</SelectItem
                        >
                    </SelectContent>
                </Select>
            </div>
            <div class="grid gap-2">
                <Label>Date</Label>
                <Input
                    v-model="rateForm.rate_date"
                    type="date"
                    class="w-[160px]"
                />
            </div>
            <div class="grid gap-2">
                <Label>Rate</Label>
                <Input
                    v-model.number="rateForm.rate"
                    type="number"
                    min="0.000001"
                    step="any"
                    class="w-[160px]"
                />
            </div>
            <Button type="submit">Save Rate</Button>
        </form>

        <div class="border-border rounded-lg border">
            <table class="w-full caption-bottom text-sm">
                <thead class="bg-muted/50">
                    <tr>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Pair
                        </th>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Date
                        </th>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Rate
                        </th>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Source
                        </th>
                        <th
                            class="text-muted-foreground h-12 px-4 text-right font-medium"
                        >
                            Actions
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="r in rates"
                        :key="r.id"
                        class="border-border border-t"
                    >
                        <td class="text-foreground p-4 font-mono">
                            {{ r.base_code }}/{{ r.quote_code }}
                        </td>
                        <td class="text-muted-foreground p-4">
                            {{ r.rate_date }}
                        </td>
                        <td class="text-foreground p-4">{{ r.rate }}</td>
                        <td class="text-muted-foreground p-4">
                            {{ r.source }}
                        </td>
                        <td class="p-4 text-right">
                            <Button
                                size="sm"
                                variant="outline"
                                @click="deleteRate(r.id)"
                                >Delete</Button
                            >
                        </td>
                    </tr>
                    <tr v-if="rates.length === 0">
                        <td
                            colspan="5"
                            class="text-muted-foreground p-4 text-center"
                        >
                            No rates yet.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
