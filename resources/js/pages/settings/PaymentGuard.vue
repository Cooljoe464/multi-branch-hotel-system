<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Payment Guard', href: '/settings/payment-guard' },
        ],
    },
});

const props = defineProps<{
    branch: { id: number; name: string };
    currentMode: string;
}>();

const selectedMode = ref(props.currentMode);
const saving = ref(false);

const modes = [
    {
        value: 'pay_first',
        label: 'Pre-Pay Mode',
        description:
            'Guests must complete dynamic QR payment before orders are dispatched to Kitchen/Laundry screens.',
        icon: '💳',
        color: 'border-emerald-500 dark:border-emerald-700 bg-emerald-50 dark:bg-emerald-950',
    },
    {
        value: 'pay_after',
        label: 'Post-Pay Mode',
        description:
            'Bypasses upfront payment walls. Orders dispatch immediately and charges post as unpaid debits to room folios.',
        icon: '🏠',
        color: 'border-blue-500 dark:border-blue-700 bg-blue-50 dark:bg-blue-950',
    },
];

const save = () => {
    saving.value = true;
    router.put(
        '/settings/payment-guard',
        {
            payment_guard_mode: selectedMode.value,
        },
        {
            onFinish: () => {
                saving.value = false;
            },
        },
    );
};
</script>

<template>
    <Head title="Payment Guard Settings" />

    <div
        class="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-4"
    >
        <div>
            <h1 class="text-foreground text-2xl font-bold">
                Payment Guard Policy
            </h1>
            <p class="text-muted-foreground text-sm">
                Configure order fulfillment rules for {{ branch.name }}
            </p>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <div
                v-for="mode in modes"
                :key="mode.value"
                class="cursor-pointer rounded-xl border-2 p-6 transition-all"
                :class="[
                    selectedMode === mode.value
                        ? `${mode.color} shadow-md`
                        : 'border-border bg-card hover:shadow-sm',
                ]"
                @click="selectedMode = mode.value"
            >
                <div class="mb-3 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="text-2xl">{{ mode.icon }}</span>
                        <h3 class="text-foreground text-lg font-semibold">
                            {{ mode.label }}
                        </h3>
                    </div>
                    <Badge
                        v-if="selectedMode === mode.value"
                        variant="default"
                        class="bg-emerald-600 dark:bg-emerald-700"
                    >
                        Selected
                    </Badge>
                </div>
                <p class="text-muted-foreground text-sm">
                    {{ mode.description }}
                </p>
            </div>
        </div>

        <div class="flex justify-end">
            <Button
                :disabled="saving || selectedMode === currentMode"
                @click="save"
            >
                {{ saving ? 'Saving...' : 'Save Changes' }}
            </Button>
        </div>
    </div>
</template>
