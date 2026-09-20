<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref, reactive, watch } from 'vue';
import { Loader2 } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
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

const CURRENCIES = ['NGN', 'USD', 'EUR', 'GBP', 'CAD', 'AUD', 'SGD', 'INR', 'AED'];

const page = usePage();
const globalCurrency = computed(() => page.props.globalCurrency as { currency_code: string; currency_symbol: string });
const branches = computed(() => page.props.branches as Array<{ id: number; name: string; currency_code: string; currency_symbol: string }>);

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

    router.put('/settings/currency', {
        currency_code: form.currency_code,
        currency_symbol: form.currency_symbol,
        branch_currencies: form.branch_currencies.map((b) => ({
            id: b.id,
            currency_code: b.currency_code,
            currency_symbol: b.currency_symbol,
        })),
    }, {
        preserveScroll: true,
        onSuccess: () => {
            processing.value = false;
        },
        onError: () => {
            processing.value = false;
        },
    });
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
                    <Select :model-value="form.currency_code" @update:model-value="onGlobalCurrencyChange">
                        <SelectTrigger class="w-full max-w-md">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="code in CURRENCIES" :key="code" :value="code">
                                {{ code }} ({{ CURRENCY_MAP[code] }})
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p class="text-muted-foreground text-sm">
                        This is the fallback currency used when a branch has not set its own.
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
                        class="flex items-center gap-4 rounded-lg border border-border p-4"
                    >
                        <span class="min-w-[140px] text-sm font-medium text-foreground">{{ branch.name }}</span>
                        <Select :model-value="branch.currency_code" @update:model-value="onBranchCurrencyChange(index, $event)">
                            <SelectTrigger class="w-full max-w-[200px]">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem v-for="code in CURRENCIES" :key="code" :value="code">
                                    {{ code }} ({{ CURRENCY_MAP[code] }})
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <span class="text-sm text-muted-foreground">{{ branch.currency_symbol }}</span>
                    </div>
                </div>
            </div>

            <div class="flex items-center gap-4">
                <Button type="submit" :disabled="processing">
                    <Loader2 v-if="processing" class="mr-2 size-4 animate-spin" />
                    Save
                </Button>
            </div>
        </form>
    </div>
</template>
