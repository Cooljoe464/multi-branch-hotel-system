<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Pagination from '@/components/ui/pagination/Pagination.vue';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import type { YieldRule, RoomType } from '@/types/hms';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Yield Rules', href: '/yield-rules' },
        ],
    },
});

const props = defineProps<{
    rules: {
        data: YieldRule[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    roomTypes: RoomType[];
}>();

const showCreate = ref(false);

const form = useForm({
    room_type_id: null as number | null,
    min_occupancy_pct: 50,
    max_occupancy_pct: 70,
    rate_multiplier: 1.15,
    mlos_override: null as number | null,
    cta_override: null as boolean | null,
    priority: 0,
});

function store() {
    form.post('/yield-rules', {
        onSuccess: () => {
            showCreate.value = false;
            form.reset();
        },
    });
}

function destroy(id: number) {
    if (confirm('Delete this yield rule?')) {
        router.delete(`/yield-rules/${id}`);
    }
}

function toggleActive(rule: YieldRule) {
    router.put(`/yield-rules/${rule.id}`, {
        is_active: !rule.is_active,
    });
}

function goToPage(page: number) {
    router.get(
        '/yield-rules',
        { page },
        { preserveState: true, replace: true },
    );
}
</script>

<template>
    <Head title="Yield Rules" />

    <div
        class="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-4"
    >
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-foreground text-2xl font-bold">Yield Rules</h1>
                <p class="text-muted-foreground text-sm">
                    Occupancy-based dynamic pricing rules
                </p>
            </div>
            <button
                class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700"
                @click="showCreate = !showCreate"
            >
                {{ showCreate ? 'Cancel' : 'New Rule' }}
            </button>
        </div>

        <div v-if="showCreate" class="bg-card rounded-xl border p-6 shadow-sm">
            <h2 class="text-foreground mb-4 text-lg font-semibold">
                Create Yield Rule
            </h2>
            <form @submit.prevent="store" class="grid gap-4 md:grid-cols-3">
                <div class="grid gap-2">
                    <Label>Room Type (optional)</Label>
                    <Select v-model="form.room_type_id">
                        <SelectTrigger class="w-full">
                            <SelectValue placeholder="All Room Types" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="null"
                                >All Room Types</SelectItem
                            >
                            <SelectItem
                                v-for="rt in roomTypes"
                                :key="rt.id"
                                :value="String(rt.id)"
                            >
                                {{ rt.name }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>
                <div class="grid gap-2">
                    <Label>Min Occupancy %</Label>
                    <Input
                        v-model.number="form.min_occupancy_pct"
                        type="number"
                        min="0"
                        max="100"
                    />
                </div>
                <div class="grid gap-2">
                    <Label>Max Occupancy %</Label>
                    <Input
                        v-model.number="form.max_occupancy_pct"
                        type="number"
                        min="0"
                        max="100"
                    />
                </div>
                <div class="grid gap-2">
                    <Label>Rate Multiplier</Label>
                    <Input
                        v-model.number="form.rate_multiplier"
                        type="number"
                        step="0.05"
                        min="0.5"
                        max="3.0"
                    />
                </div>
                <div class="grid gap-2">
                    <Label>MLOS Override</Label>
                    <Input
                        :model-value="form.mlos_override ?? undefined"
                        @update:model-value="
                            form.mlos_override =
                                $event === '' ? null : Number($event)
                        "
                        type="number"
                        min="1"
                        max="30"
                        placeholder="No override"
                    />
                </div>
                <div class="grid gap-2">
                    <Label>Priority</Label>
                    <Input
                        v-model.number="form.priority"
                        type="number"
                        min="0"
                    />
                </div>
                <div class="flex justify-end md:col-span-3">
                    <Button type="submit" :disabled="form.processing">
                        Create Rule
                    </Button>
                </div>
            </form>
        </div>

        <div class="bg-card rounded-xl border shadow-sm">
            <div
                class="grid grid-cols-1 gap-4 p-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
            >
                <div
                    v-for="rule in rules.data"
                    :key="rule.id"
                    class="bg-card rounded-lg border p-5 shadow-sm transition-shadow hover:shadow-md"
                >
                    <div class="mb-3 flex items-center justify-between">
                        <span class="text-foreground text-sm font-semibold">
                            {{
                                roomTypes.find(
                                    (rt) => rt.id === rule.room_type_id,
                                )?.name ?? 'All Room Types'
                            }}
                        </span>
                        <Badge
                            :class="
                                rule.is_active
                                    ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400'
                                    : 'bg-muted text-muted-foreground'
                            "
                            class="cursor-pointer"
                            @click="toggleActive(rule)"
                        >
                            {{ rule.is_active ? 'Active' : 'Inactive' }}
                        </Badge>
                    </div>
                    <div class="mb-3 space-y-2 text-sm">
                        <div>
                            <span class="text-muted-foreground"
                                >Occupancy Range</span
                            >
                            <p class="text-foreground">
                                {{ rule.min_occupancy_pct }}% -
                                {{ rule.max_occupancy_pct }}%
                            </p>
                        </div>
                        <div>
                            <span class="text-muted-foreground"
                                >Rate Multiplier</span
                            >
                            <p class="text-foreground">
                                {{ rule.rate_multiplier }}x
                            </p>
                        </div>
                        <div>
                            <span class="text-muted-foreground"
                                >MLOS Override</span
                            >
                            <p class="text-foreground">
                                {{
                                    rule.mlos_override
                                        ? `${rule.mlos_override} night${rule.mlos_override > 1 ? 's' : ''}`
                                        : 'None'
                                }}
                            </p>
                        </div>
                        <div>
                            <span class="text-muted-foreground"
                                >CTA Override</span
                            >
                            <p class="text-foreground">
                                {{ rule.cta_override ? 'Yes' : 'No' }}
                            </p>
                        </div>
                        <div>
                            <span class="text-muted-foreground">Priority</span>
                            <p class="text-foreground">{{ rule.priority }}</p>
                        </div>
                    </div>
                    <div class="flex justify-end border-t pt-2">
                        <button
                            class="text-sm text-red-600 hover:text-red-800 dark:text-red-400 dark:hover:text-red-300"
                            @click="destroy(rule.id)"
                        >
                            Delete
                        </button>
                    </div>
                </div>
                <div
                    v-if="rules.data.length === 0"
                    class="text-muted-foreground col-span-full py-12 text-center"
                >
                    No yield rules configured.
                </div>
            </div>
            <div class="px-4 pb-4">
                <Pagination
                    :data="rules"
                    label="rules"
                    @page-change="goToPage"
                />
            </div>
        </div>
    </div>
</template>
