<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

interface Supplier {
    id: number;
    name: string;
}

interface Order {
    id: number;
    status: string;
    supplier: { name: string };
    lines: {
        inventory_item_id: number;
        qty: number;
        unit_cost_minor: number;
    }[];
    receipts: { id: number }[];
}

interface Item {
    id: number;
    name: string;
    unit: string;
    current_quantity: number;
    cost_per_unit: number;
}

const props = defineProps<{
    branch: { id: number; name: string };
    suppliers: Supplier[];
    orders: { data: Order[] };
    items: Item[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Costing', href: '#' },
        ],
    },
});

const supplierForm = useForm({ name: '', email: '', phone: '' });
const poForm = useForm({
    supplier_id: null as number | null,
    lines: [
        {
            inventory_item_id: null as number | null,
            qty: 1,
            unit_cost_minor: 0,
        },
    ],
});
const grnForm = useForm({
    lines_received: [
        {
            inventory_item_id: null as number | null,
            qty: 1,
            unit_cost_minor: 0,
        },
    ],
});

const base = `/branches/${props.branch.id}`;

function addPoLine() {
    poForm.lines.push({ inventory_item_id: null, qty: 1, unit_cost_minor: 0 });
}

function addGrnLine() {
    grnForm.lines_received.push({
        inventory_item_id: null,
        qty: 1,
        unit_cost_minor: 0,
    });
}
</script>

<template>
    <Head title="F&B Costing" />
    <div class="space-y-8 p-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold">
                    Costing — {{ branch.name }}
                </h1>
                <p class="text-muted-foreground text-sm">
                    Suppliers, purchase orders, GRNs and weighted costs. Money
                    in minor units.
                </p>
            </div>
            <Button
                variant="outline"
                @click="router.get(`${base}/costing/variance`)"
                >Variance report</Button
            >
        </div>

        <section class="space-y-3">
            <h2 class="text-lg font-medium">Suppliers</h2>
            <form
                @submit.prevent="
                    supplierForm.post(`${base}/suppliers`, {
                        onSuccess: () => supplierForm.reset(),
                    })
                "
                class="flex flex-wrap items-end gap-3 rounded-lg border p-4"
            >
                <div class="grid gap-2">
                    <Label>Name</Label
                    ><Input v-model="supplierForm.name" required />
                </div>
                <div class="grid gap-2">
                    <Label>Email</Label
                    ><Input v-model="supplierForm.email" type="email" />
                </div>
                <div class="grid gap-2">
                    <Label>Phone</Label><Input v-model="supplierForm.phone" />
                </div>
                <Button type="submit">Add supplier</Button>
            </form>
            <ul class="flex flex-wrap gap-2 text-sm">
                <li
                    v-for="s in suppliers"
                    :key="s.id"
                    class="rounded border p-2"
                >
                    {{ s.name }}
                </li>
            </ul>
        </section>

        <section class="space-y-3">
            <h2 class="text-lg font-medium">New purchase order</h2>
            <form
                @submit.prevent="
                    poForm.post(`${base}/purchase-orders`, {
                        onSuccess: () => poForm.reset(),
                    })
                "
                class="space-y-3 rounded-lg border p-4"
            >
                <div class="grid gap-2">
                    <Label>Supplier</Label>
                    <select
                        v-model="poForm.supplier_id"
                        class="rounded-md border p-2 text-sm"
                        required
                    >
                        <option :value="null" disabled>Select supplier</option>
                        <option
                            v-for="s in suppliers"
                            :key="s.id"
                            :value="s.id"
                        >
                            {{ s.name }}
                        </option>
                    </select>
                </div>
                <div
                    v-for="(line, i) in poForm.lines"
                    :key="i"
                    class="grid grid-cols-3 gap-2"
                >
                    <select
                        v-model="line.inventory_item_id"
                        class="rounded-md border p-2 text-sm"
                        required
                    >
                        <option :value="null" disabled>Select item</option>
                        <option
                            v-for="item in items"
                            :key="item.id"
                            :value="item.id"
                        >
                            {{ item.name }} ({{ item.current_quantity }}
                            {{ item.unit }} @ {{ item.cost_per_unit }})
                        </option>
                    </select>
                    <Input
                        v-model.number="line.qty"
                        type="number"
                        min="0.01"
                        step="0.01"
                        placeholder="Qty"
                    />
                    <Input
                        v-model.number="line.unit_cost_minor"
                        type="number"
                        min="0"
                        placeholder="Unit cost minor"
                    />
                </div>
                <div class="flex gap-2">
                    <Button type="button" variant="outline" @click="addPoLine"
                        >Add line</Button
                    >
                    <Button type="submit">Draft PO</Button>
                </div>
            </form>
        </section>

        <section class="space-y-3">
            <h2 class="text-lg font-medium">Purchase orders</h2>
            <div
                v-for="order in orders.data"
                :key="order.id"
                class="space-y-2 rounded-lg border p-4"
            >
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="font-medium">
                        PO #{{ order.id }} — {{ order.supplier.name }} ·
                        {{ order.status }} ({{ order.receipts.length }} GRNs)
                    </div>
                    <div class="flex gap-1">
                        <Button
                            v-if="order.status === 'draft'"
                            size="sm"
                            variant="outline"
                            @click="
                                router.post(
                                    `${base}/purchase-orders/${order.id}/send`,
                                )
                            "
                            >Send</Button
                        >
                        <Button
                            v-if="
                                order.status === 'draft' ||
                                order.status === 'sent' ||
                                order.status === 'partial'
                            "
                            size="sm"
                            variant="outline"
                            @click="
                                router.post(
                                    `${base}/purchase-orders/${order.id}/cancel`,
                                )
                            "
                            >Cancel</Button
                        >
                    </div>
                </div>
                <ul class="font-mono text-xs">
                    <li v-for="(line, i) in order.lines" :key="i">
                        Item #{{ line.inventory_item_id }} × {{ line.qty }} @
                        {{ line.unit_cost_minor }}
                    </li>
                </ul>
                <form
                    v-if="order.status === 'sent' || order.status === 'partial'"
                    @submit.prevent="
                        grnForm.post(
                            `${base}/purchase-orders/${order.id}/receive`,
                            { onSuccess: () => grnForm.reset() },
                        )
                    "
                    class="space-y-2 border-t pt-2"
                >
                    <div
                        v-for="(line, i) in grnForm.lines_received"
                        :key="i"
                        class="grid grid-cols-3 gap-2"
                    >
                        <select
                            v-model="line.inventory_item_id"
                            class="rounded-md border p-2 text-sm"
                            required
                        >
                            <option :value="null" disabled>Select item</option>
                            <option
                                v-for="item in items"
                                :key="item.id"
                                :value="item.id"
                            >
                                {{ item.name }}
                            </option>
                        </select>
                        <Input
                            v-model.number="line.qty"
                            type="number"
                            min="0.01"
                            step="0.01"
                            placeholder="Qty received"
                        />
                        <Input
                            v-model.number="line.unit_cost_minor"
                            type="number"
                            min="0"
                            placeholder="Unit cost minor"
                        />
                    </div>
                    <div class="flex gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            @click="addGrnLine"
                            >Add line</Button
                        >
                        <Button type="submit" size="sm">Receive (GRN)</Button>
                    </div>
                </form>
            </div>
        </section>
    </div>
</template>
