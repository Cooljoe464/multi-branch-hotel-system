<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Checkbox } from '@/components/ui/checkbox';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Cashier POS Access', href: '/settings/cashier-outlets' },
        ],
    },
});

interface Cashier {
    id: number;
    name: string;
    email: string;
    outlet_ids: number[];
}

interface Outlet {
    id: number;
    name: string;
    code: string;
    type: string;
}

const props = defineProps<{
    cashiers: Cashier[];
    outlets: Outlet[];
}>();

const selectedCashier = ref<Cashier | null>(null);
const selectedOutletIds = ref<number[]>([]);
const saving = ref(false);

const typeBadge: Record<string, string> = {
    restaurant:
        'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-300',
    bar: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300',
    spa: 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-300',
    gift_shop:
        'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300',
    laundry: 'bg-teal-100 text-teal-800 dark:bg-teal-900 dark:text-teal-300',
};

const editCashier = (cashier: Cashier) => {
    selectedCashier.value = cashier;
    selectedOutletIds.value = [...cashier.outlet_ids];
};

const toggleOutlet = (outletId: number) => {
    const idx = selectedOutletIds.value.indexOf(outletId);
    if (idx === -1) {
        selectedOutletIds.value.push(outletId);
    } else {
        selectedOutletIds.value.splice(idx, 1);
    }
};

const save = () => {
    if (!selectedCashier.value) return;
    saving.value = true;
    router.put(
        `/settings/cashier-outlets/${selectedCashier.value.id}`,
        {
            outlet_ids: selectedOutletIds.value,
        },
        {
            onFinish: () => {
                saving.value = false;
                selectedCashier.value = null;
            },
        },
    );
};
</script>

<template>
    <Head title="Cashier POS Access" />

    <div
        class="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-4"
    >
        <div>
            <h1 class="text-foreground text-2xl font-bold">
                Cashier POS Access
            </h1>
            <p class="text-muted-foreground text-sm">
                Assign cashiers to specific POS outlets they can access.
            </p>
        </div>

        <div
            v-if="cashiers.length === 0"
            class="text-muted-foreground py-12 text-center"
        >
            No cashiers found. Assign the Cashier role to users first.
        </div>

        <div v-else class="grid gap-4">
            <div
                v-for="cashier in cashiers"
                :key="cashier.id"
                class="bg-card border-border rounded-lg border p-4 transition-shadow hover:shadow-md"
            >
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-foreground font-semibold">
                            {{ cashier.name }}
                        </h3>
                        <p class="text-muted-foreground text-sm">
                            {{ cashier.email }}
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <div class="flex flex-wrap gap-1">
                            <Badge
                                v-for="outletId in cashier.outlet_ids"
                                :key="outletId"
                                variant="outline"
                                class="text-xs"
                            >
                                {{
                                    props.outlets.find((o) => o.id === outletId)
                                        ?.name ?? `Outlet #${outletId}`
                                }}
                            </Badge>
                            <Badge
                                v-if="cashier.outlet_ids.length === 0"
                                variant="secondary"
                                class="text-xs"
                            >
                                No outlets assigned
                            </Badge>
                        </div>
                        <Button
                            variant="outline"
                            size="sm"
                            @click="editCashier(cashier)"
                        >
                            Edit Access
                        </Button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Edit Dialog -->
        <Teleport to="body">
            <div
                v-if="selectedCashier"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
                @click.self="selectedCashier = null"
            >
                <div
                    class="bg-card border-border mx-4 w-full max-w-md rounded-lg border p-6 shadow-lg"
                >
                    <h2 class="text-foreground mb-1 text-lg font-bold">
                        POS Access for {{ selectedCashier.name }}
                    </h2>
                    <p class="text-muted-foreground mb-4 text-sm">
                        Select which POS outlets this cashier can access.
                    </p>

                    <div class="mb-6 space-y-2">
                        <label
                            v-for="outlet in outlets"
                            :key="outlet.id"
                            class="border-border hover:bg-accent/50 flex cursor-pointer items-center gap-3 rounded-lg border p-3 transition-colors"
                        >
                            <Checkbox
                                :checked="selectedOutletIds.includes(outlet.id)"
                                @update:checked="toggleOutlet(outlet.id)"
                            />
                            <div class="flex-1">
                                <div class="text-foreground font-medium">
                                    {{ outlet.name }}
                                </div>
                                <div class="text-muted-foreground text-xs">
                                    {{ outlet.code }} ·
                                    {{ outlet.type.replace('_', ' ') }}
                                </div>
                            </div>
                            <Badge
                                :class="typeBadge[outlet.type]"
                                variant="outline"
                                class="text-xs capitalize"
                            >
                                {{ outlet.type.replace('_', ' ') }}
                            </Badge>
                        </label>
                    </div>

                    <div class="flex justify-end gap-2">
                        <Button
                            variant="outline"
                            @click="selectedCashier = null"
                            >Cancel</Button
                        >
                        <Button @click="save" :disabled="saving">
                            {{ saving ? 'Saving...' : 'Save Access' }}
                        </Button>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>
