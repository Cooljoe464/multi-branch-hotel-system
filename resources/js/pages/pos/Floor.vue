<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

interface DiningTable {
    id: number;
    code: string;
    shape: string;
    seats: number;
    status: string;
    position: { x: number; y: number } | null;
}

interface TabLine {
    name: string;
    quantity: number;
    price: number;
    course?: string;
}

interface Tab {
    id: number;
    outlet: string;
    status: string;
    course: string;
    subtotal: number;
    total: number;
    items: TabLine[];
    dining_table_id: number | null;
    dining_table: { code: string } | null;
    reservation: { guest_name: string } | null;
}

type OfflineLine = {
    name: string;
    quantity: number;
    price: number;
};

type OfflinePayload = {
    offline_nonce: string;
    reservation_id: number | null;
    dining_table_id: number | null;
    course: string;
    items: OfflineLine[];
};

interface Modifier {
    id: number;
    name: string;
    price_delta_minor: number;
}

interface HappyHour {
    id: number;
    cron_window: string;
    discount_bps: number;
}

const props = defineProps<{
    outlet: { id: number; code: string; name: string; branch_id: number };
    tables: DiningTable[];
    tabs: Tab[];
    modifiers: Modifier[];
    happyHours: HappyHour[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'POS Floor', href: '#' },
        ],
    },
});

const selectedTab = ref<number | null>(null);

const tableForm = useForm({ code: '', shape: 'square', seats: 2, x: 0, y: 0 });
const tabForm = useForm({
    reservation_id: undefined as number | undefined,
    dining_table_id: null as number | null,
    covers: 2,
});
const lineForm = useForm({
    name: '',
    quantity: 1,
    price: 0,
    course: 'main',
    modifier_ids: [] as number[],
});
const splitForm = useForm({
    legs: [{ percent_bps: 5000 }, { percent_bps: 5000 }] as {
        percent_bps?: number;
        amount_minor?: number;
    }[],
});
const modifierForm = useForm({ name: '', price_delta_minor: 0 });
const happyForm = useForm({
    cron_window: 'FRI 17:00-19:00',
    discount_bps: 2000,
});

const offlineKey = `pos_offline_${props.outlet.id}`;
const queuedCount = ref(0);

function refreshQueued() {
    try {
        queuedCount.value = (
            JSON.parse(localStorage.getItem(offlineKey) ?? '[]') as unknown[]
        ).length;
    } catch {
        queuedCount.value = 0;
    }
}

refreshQueued();

function queueOffline() {
    const payload: OfflinePayload = {
        offline_nonce: `offline-${Date.now()}-${Math.random().toString(36).slice(2)}`,
        reservation_id: tabForm.reservation_id ?? null,
        dining_table_id: tabForm.dining_table_id,
        course: 'main',
        items: [
            {
                name: lineForm.name,
                quantity: lineForm.quantity,
                price: lineForm.price,
            },
        ],
    };
    const queue = JSON.parse(
        localStorage.getItem(offlineKey) ?? '[]',
    ) as OfflinePayload[];
    queue.push(payload);
    localStorage.setItem(offlineKey, JSON.stringify(queue));
    refreshQueued();
}

function replayOffline() {
    const queue = JSON.parse(
        localStorage.getItem(offlineKey) ?? '[]',
    ) as OfflinePayload[];
    router.post(
        `/pos/${props.outlet.id}/offline-replay`,
        { payloads: queue },
        {
            onSuccess: () => {
                localStorage.removeItem(offlineKey);
                refreshQueued();
            },
        },
    );
}

function setLegBps(i: number, v: string | number) {
    const leg = splitForm.legs[i] as { percent_bps?: number };
    leg.percent_bps = Number(v);
}
</script>

<template>
    <Head :title="`Floor — ${outlet.code}`" />
    <div class="space-y-8 p-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold">
                    Floor — {{ outlet.code }}
                </h1>
                <p class="text-muted-foreground text-sm">
                    Tabs, courses, splits and the offline queue.
                </p>
            </div>
            <div
                v-if="queuedCount > 0"
                class="flex items-center gap-2 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm"
            >
                <span>{{ queuedCount }} charges queued offline</span>
                <Button size="sm" @click="replayOffline">Sync now</Button>
            </div>
        </div>

        <section class="space-y-3">
            <h2 class="text-lg font-medium">Floor plan</h2>
            <div class="bg-muted/30 relative min-h-64 rounded-lg border p-4">
                <button
                    v-for="t in tables"
                    :key="t.id"
                    type="button"
                    class="absolute rounded-lg border p-3 text-sm font-medium"
                    :class="
                        t.status === 'seated'
                            ? 'border-red-400 bg-red-50'
                            : 'border-green-400 bg-green-50'
                    "
                    :style="{
                        left: `${t.position?.x ?? 10}px`,
                        top: `${t.position?.y ?? 10}px`,
                    }"
                    @click="tabForm.dining_table_id = t.id"
                >
                    {{ t.code }} · {{ t.seats }} seats
                </button>
            </div>
            <form
                @submit.prevent="
                    tableForm.post(`/pos/${outlet.id}/tables`, {
                        onSuccess: () => tableForm.reset(),
                    })
                "
                class="flex flex-wrap items-end gap-3 rounded-lg border p-4"
            >
                <div class="grid gap-2">
                    <Label>Code</Label
                    ><Input v-model="tableForm.code" required />
                </div>
                <div class="grid gap-2">
                    <Label>Seats</Label
                    ><Input
                        v-model.number="tableForm.seats"
                        type="number"
                        min="1"
                    />
                </div>
                <div class="grid gap-2">
                    <Label>X</Label
                    ><Input v-model.number="tableForm.x" type="number" />
                </div>
                <div class="grid gap-2">
                    <Label>Y</Label
                    ><Input v-model.number="tableForm.y" type="number" />
                </div>
                <Button type="submit">Add table</Button>
            </form>
        </section>

        <section class="space-y-3">
            <h2 class="text-lg font-medium">Open tabs</h2>
            <form
                @submit.prevent="tabForm.post(`/pos/${outlet.id}/tabs`)"
                class="flex flex-wrap items-end gap-3 rounded-lg border p-4"
            >
                <div class="grid gap-2">
                    <Label>Reservation id</Label
                    ><Input
                        v-model.number="tabForm.reservation_id"
                        type="number"
                        required
                    />
                </div>
                <div class="grid gap-2">
                    <Label>Covers</Label
                    ><Input
                        v-model.number="tabForm.covers"
                        type="number"
                        min="1"
                    />
                </div>
                <Button type="submit"
                    >Open tab{{
                        tabForm.dining_table_id ? ' (selected table)' : ''
                    }}</Button
                >
                <Button type="button" variant="outline" @click="queueOffline"
                    >Queue offline instead</Button
                >
            </form>
            <div class="grid gap-3 md:grid-cols-2">
                <div
                    v-for="tab in tabs"
                    :key="tab.id"
                    class="rounded-lg border p-4"
                    :class="selectedTab === tab.id ? 'ring-primary ring-2' : ''"
                >
                    <button
                        type="button"
                        class="w-full text-left"
                        @click="
                            selectedTab = selectedTab === tab.id ? null : tab.id
                        "
                    >
                        <div class="font-medium">
                            Tab #{{ tab.id }} —
                            {{ tab.reservation?.guest_name ?? 'Walk-in' }}
                        </div>
                        <div class="text-muted-foreground text-sm">
                            {{ tab.dining_table?.code ?? 'No table' }} ·
                            {{ tab.total }} minor · {{ tab.status }}
                        </div>
                    </button>
                    <div
                        v-if="selectedTab === tab.id"
                        class="mt-3 space-y-2 border-t pt-3"
                    >
                        <ul class="text-sm">
                            <li v-for="(line, i) in tab.items" :key="i">
                                {{ line.quantity }}x {{ line.name }} —
                                {{ line.price }} ({{ line.course ?? 'main' }})
                            </li>
                        </ul>
                        <form
                            @submit.prevent="
                                lineForm.post(`/pos/tabs/${tab.id}/items`, {
                                    onSuccess: () => lineForm.reset(),
                                })
                            "
                            class="grid grid-cols-4 gap-2"
                        >
                            <Input
                                v-model="lineForm.name"
                                placeholder="Item"
                                required
                            />
                            <Input
                                v-model.number="lineForm.quantity"
                                type="number"
                                min="1"
                            />
                            <Input
                                v-model.number="lineForm.price"
                                type="number"
                                min="0"
                                placeholder="Price minor"
                            />
                            <Button type="submit" size="sm">Add</Button>
                        </form>
                        <div class="flex flex-wrap gap-1">
                            <Button
                                v-for="course in ['starter', 'main', 'dessert']"
                                :key="course"
                                size="sm"
                                variant="outline"
                                @click="
                                    router.post(`/pos/tabs/${tab.id}/fire`, {
                                        course,
                                    })
                                "
                                >Fire {{ course }}</Button
                            >
                        </div>
                        <form
                            @submit.prevent="
                                splitForm.post(`/pos/tabs/${tab.id}/split`)
                            "
                            class="flex flex-wrap items-end gap-2"
                        >
                            <div
                                v-for="(_, i) in splitForm.legs"
                                :key="i"
                                class="grid gap-1"
                            >
                                <Label>Leg {{ i + 1 }} % (bps)</Label>
                                <Input
                                    :model-value="
                                        splitForm.legs[i]?.percent_bps
                                    "
                                    type="number"
                                    class="w-28"
                                    @update:model-value="(v) => setLegBps(i, v)"
                                />
                            </div>
                            <Button type="submit" size="sm">Split</Button>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                @click="router.post(`/pos/tabs/${tab.id}/post`)"
                                >Post to folio</Button
                            >
                        </form>
                    </div>
                </div>
            </div>
        </section>

        <section class="grid gap-6 md:grid-cols-2">
            <div class="space-y-3">
                <h2 class="text-lg font-medium">Modifiers</h2>
                <form
                    @submit.prevent="
                        modifierForm.post(
                            `/branches/${outlet.branch_id}/modifiers`,
                            { onSuccess: () => modifierForm.reset() },
                        )
                    "
                    class="flex items-end gap-2"
                >
                    <div class="grid gap-2">
                        <Label>Name</Label
                        ><Input v-model="modifierForm.name" required />
                    </div>
                    <div class="grid gap-2">
                        <Label>Delta (minor)</Label
                        ><Input
                            v-model.number="modifierForm.price_delta_minor"
                            type="number"
                        />
                    </div>
                    <Button type="submit">Add</Button>
                </form>
                <ul class="space-y-1 text-sm">
                    <li
                        v-for="m in modifiers"
                        :key="m.id"
                        class="flex justify-between rounded border p-2"
                    >
                        <span
                            >{{ m.name }} ({{
                                m.price_delta_minor >= 0 ? '+' : ''
                            }}{{ m.price_delta_minor }})</span
                        >
                        <button
                            type="button"
                            class="text-red-600"
                            @click="
                                router.delete(
                                    `/branches/${outlet.branch_id}/modifiers/${m.id}`,
                                )
                            "
                        >
                            Delete
                        </button>
                    </li>
                </ul>
            </div>
            <div class="space-y-3">
                <h2 class="text-lg font-medium">Happy hours</h2>
                <form
                    @submit.prevent="
                        happyForm.post(`/pos/${outlet.id}/happy-hours`, {
                            onSuccess: () => happyForm.reset(),
                        })
                    "
                    class="flex items-end gap-2"
                >
                    <div class="grid gap-2">
                        <Label>Window (e.g. FRI 17:00-19:00)</Label
                        ><Input v-model="happyForm.cron_window" required />
                    </div>
                    <div class="grid gap-2">
                        <Label>Discount (bps)</Label
                        ><Input
                            v-model.number="happyForm.discount_bps"
                            type="number"
                            min="0"
                        />
                    </div>
                    <Button type="submit">Add</Button>
                </form>
                <ul class="space-y-1 text-sm">
                    <li
                        v-for="h in happyHours"
                        :key="h.id"
                        class="flex justify-between rounded border p-2"
                    >
                        <span
                            >{{ h.cron_window }} —
                            {{ (h.discount_bps / 100).toFixed(0) }}%</span
                        >
                        <button
                            type="button"
                            class="text-red-600"
                            @click="
                                router.delete(
                                    `/pos/${outlet.id}/happy-hours/${h.id}`,
                                )
                            "
                        >
                            Delete
                        </button>
                    </li>
                </ul>
            </div>
        </section>
    </div>
</template>
