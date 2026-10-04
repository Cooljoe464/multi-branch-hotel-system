<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

interface Outlet {
    id: number;
    name: string;
    code: string;
    type: string;
    tax_rate?: number;
}
interface Reservation {
    id: number;
    guest_name: string;
    room_number: string;
    confirmation_number: string;
}
interface MenuItem {
    id: number;
    name: string;
    price: number;
    category: string;
}
interface OrderItem {
    menu_item_id: number | null;
    name: string;
    quantity: number;
    price: number;
    source: 'catalog' | 'custom';
}

const props = defineProps<{
    outlet: Outlet;
    reservations: Reservation[];
    menuItems: MenuItem[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'POS', href: '/pos' },
            { title: 'Terminal', href: '#' },
        ],
    },
});

const guestMode = ref<'reservation' | 'walk_in'>('reservation');
const selectedReservationId = ref('');
const guestTitle = ref('Mr.');
const guestName = ref('');
const titleOptions = ['Mr.', 'Mrs.', 'Miss', 'Ms.', 'Dr.', 'Prof.'];

const selectedItemId = ref('');
const quantity = ref(1);
const customMode = ref(false);
const customName = ref('');
const customPrice = ref(0);
const orderItems = ref<OrderItem[]>([]);

const selectedItem = computed(() => {
    if (!selectedItemId.value) return null;
    return (
        props.menuItems.find((m) => String(m.id) === selectedItemId.value) ??
        null
    );
});

const subtotal = computed(() =>
    orderItems.value.reduce((sum, item) => sum + item.price * item.quantity, 0),
);
const taxRate = computed(() => props.outlet.tax_rate ?? 0);
const tax = computed(() => Math.round(subtotal.value * (taxRate.value / 100)));
const total = computed(() => subtotal.value + tax.value);

const categoryBadgeClass: Record<string, string> = {
    food: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300',
    drink: 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-300',
    laundry:
        'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-300',
    service:
        'bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-300',
};

import { formatCurrency, getCurrencySymbol } from '@/lib/format';
const page = usePage();
const branchSymbol = computed(
    () => (page.props.branch?.current as any)?.currency_symbol || '$',
);
const currencySymbol = computed(() => branchSymbol.value);
const resolveSymbol = (code?: string) =>
    getCurrencySymbol(code || 'NGN') || branchSymbol.value;
const formatPrice = (cents: number, currencyCode?: string) =>
    formatCurrency(cents, resolveSymbol(currencyCode));

const addItemFromCatalog = () => {
    if (!selectedItem.value) return;
    orderItems.value.push({
        menu_item_id: selectedItem.value.id,
        name: selectedItem.value.name,
        quantity: quantity.value,
        price: selectedItem.value.price,
        source: 'catalog',
    });
    selectedItemId.value = '';
    quantity.value = 1;
};

const addCustomItem = () => {
    if (!customName.value.trim() || customPrice.value <= 0) return;
    orderItems.value.push({
        menu_item_id: null,
        name: customName.value.trim(),
        quantity: quantity.value,
        price: Math.round(customPrice.value * 100),
        source: 'custom',
    });
    customName.value = '';
    customPrice.value = 0;
    quantity.value = 1;
};

const removeItem = (index: number) => orderItems.value.splice(index, 1);

const canSubmit = computed(() => {
    if (orderItems.value.length === 0) return false;
    if (guestMode.value === 'reservation') return !!selectedReservationId.value;
    return guestName.value.trim().length > 0;
});

const submit = () => {
    if (!canSubmit.value) return;
    const payload: {
        mode: string;
        items: Array<{
            menu_item_id: number | null;
            name: string;
            quantity: number;
            price: number;
        }>;
        reservation_id?: number;
        guest_title?: string;
        guest_name?: string;
    } = {
        mode: guestMode.value,
        items: orderItems.value.map((i) => ({
            menu_item_id: i.menu_item_id,
            name: i.name,
            quantity: i.quantity,
            price: i.price,
        })),
    };
    if (guestMode.value === 'reservation') {
        payload.reservation_id = parseInt(selectedReservationId.value);
    } else {
        payload.guest_title = guestTitle.value;
        payload.guest_name = guestName.value.trim();
    }
    router.post(`/pos/${props.outlet.id}/charge`, payload, {
        onSuccess: () => {
            selectedReservationId.value = '';
            guestName.value = '';
            orderItems.value = [];
        },
    });
};
</script>

<template>
    <Head title="POS Terminal" />
    <div class="space-y-6 p-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-foreground text-2xl font-bold">
                    {{ outlet.name }} — POS Terminal
                </h1>
                <p class="text-muted-foreground text-sm">
                    {{ outlet.type.replace('_', ' ') }} &bull; {{ outlet.code }}
                </p>
            </div>
            <Link
                href="/pos"
                class="text-muted-foreground hover:text-foreground text-sm"
                >Back to Outlets</Link
            >
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div class="space-y-4 lg:col-span-2">
                <div
                    class="bg-card border-border space-y-4 rounded-lg border p-4"
                >
                    <div class="flex gap-4">
                        <button
                            type="button"
                            class="flex-1 rounded-lg border px-4 py-3 text-sm font-medium transition-colors"
                            :class="
                                guestMode === 'reservation'
                                    ? 'border-primary bg-primary/10 text-primary'
                                    : 'border-border text-muted-foreground hover:bg-muted'
                            "
                            @click="guestMode = 'reservation'"
                        >
                            Reservation
                        </button>
                        <button
                            type="button"
                            class="flex-1 rounded-lg border px-4 py-3 text-sm font-medium transition-colors"
                            :class="
                                guestMode === 'walk_in'
                                    ? 'border-primary bg-primary/10 text-primary'
                                    : 'border-border text-muted-foreground hover:bg-muted'
                            "
                            @click="guestMode = 'walk_in'"
                        >
                            Walk-in
                        </button>
                    </div>

                    <div v-if="guestMode === 'reservation'" class="grid gap-2">
                        <Label>Guest Reservation</Label>
                        <Select v-model="selectedReservationId">
                            <SelectTrigger class="w-full">
                                <SelectValue
                                    placeholder="Select a checked-in guest"
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="r in reservations"
                                    :key="r.id"
                                    :value="String(r.id)"
                                >
                                    {{ r.guest_name }} — Room
                                    {{ r.room_number }} ({{
                                        r.confirmation_number
                                    }})
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <p
                            v-if="reservations.length === 0"
                            class="text-muted-foreground text-xs"
                        >
                            No checked-in reservations found.
                        </p>
                    </div>

                    <div
                        v-else
                        class="grid grid-cols-[140px_1fr] items-end gap-3"
                    >
                        <div class="grid gap-2">
                            <Label>Title</Label>
                            <Select v-model="guestTitle">
                                <SelectTrigger class="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="t in titleOptions"
                                        :key="t"
                                        :value="t"
                                        >{{ t }}</SelectItem
                                    >
                                </SelectContent>
                            </Select>
                        </div>
                        <div class="grid gap-2">
                            <Label>Guest Name</Label>
                            <Input
                                v-model="guestName"
                                placeholder="Enter guest name"
                                class="w-full"
                            />
                        </div>
                    </div>
                </div>

                <div
                    class="bg-card border-border space-y-4 rounded-lg border p-4"
                >
                    <div class="flex items-center justify-between">
                        <h2 class="text-foreground font-semibold">Add Items</h2>
                        <Button
                            variant="ghost"
                            size="sm"
                            @click="customMode = !customMode"
                        >
                            {{ customMode ? 'Catalog' : 'Custom Item' }}
                        </Button>
                    </div>

                    <div
                        v-if="!customMode"
                        class="grid grid-cols-1 items-end gap-3 sm:grid-cols-[1fr_100px_auto]"
                    >
                        <div class="grid gap-2">
                            <Label>Menu Item</Label>
                            <Select v-model="selectedItemId">
                                <SelectTrigger class="w-full">
                                    <SelectValue placeholder="Select an item" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="item in menuItems"
                                        :key="item.id"
                                        :value="String(item.id)"
                                    >
                                        <span class="flex items-center gap-2">
                                            <span>{{ item.name }}</span>
                                            <Badge
                                                variant="outline"
                                                :class="[
                                                    'px-1.5 py-0 text-[10px]',
                                                    categoryBadgeClass[
                                                        item.category
                                                    ] ?? '',
                                                ]"
                                            >
                                                {{ item.category }}
                                            </Badge>
                                            <span
                                                class="text-muted-foreground ml-auto"
                                                >{{
                                                    formatPrice(item.price)
                                                }}</span
                                            >
                                        </span>
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <p
                                v-if="menuItems.length === 0"
                                class="text-muted-foreground text-xs"
                            >
                                No menu items configured for this outlet.
                            </p>
                        </div>
                        <div class="grid gap-2">
                            <Label>Qty</Label>
                            <Input
                                v-model.number="quantity"
                                type="number"
                                min="1"
                                class="w-full"
                            />
                        </div>
                        <Button
                            @click="addItemFromCatalog"
                            :disabled="!selectedItem || quantity < 1"
                            >Add</Button
                        >
                    </div>

                    <div
                        v-else
                        class="grid grid-cols-1 items-end gap-3 sm:grid-cols-[1fr_120px_120px_auto]"
                    >
                        <div class="grid gap-2">
                            <Label>Item Name</Label>
                            <Input
                                v-model="customName"
                                placeholder="e.g. Special Request"
                                class="w-full"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label>Price ({{ currencySymbol }})</Label>
                            <Input
                                v-model.number="customPrice"
                                type="number"
                                min="0"
                                step="0.01"
                                placeholder="0.00"
                                class="w-full"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label>Qty</Label>
                            <Input
                                v-model.number="quantity"
                                type="number"
                                min="1"
                                class="w-full"
                            />
                        </div>
                        <Button
                            @click="addCustomItem"
                            :disabled="
                                !customName.trim() ||
                                customPrice <= 0 ||
                                quantity < 1
                            "
                            >Add</Button
                        >
                    </div>
                </div>

                <div
                    v-if="orderItems.length > 0"
                    class="bg-card border-border rounded-lg border p-4"
                >
                    <h2 class="text-foreground mb-3 font-semibold">
                        Current Order ({{ orderItems.length }} items)
                    </h2>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-muted/50">
                                <tr class="border-border border-b">
                                    <th
                                        class="text-muted-foreground h-12 px-4 text-left font-medium"
                                    >
                                        Item
                                    </th>
                                    <th
                                        class="text-muted-foreground h-12 px-4 text-center font-medium"
                                    >
                                        Qty
                                    </th>
                                    <th
                                        class="text-muted-foreground h-12 px-4 text-right font-medium"
                                    >
                                        Unit Price
                                    </th>
                                    <th
                                        class="text-muted-foreground h-12 px-4 text-right font-medium"
                                    >
                                        Total
                                    </th>
                                    <th
                                        class="text-muted-foreground h-12 px-4 font-medium"
                                    ></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="(item, i) in orderItems"
                                    :key="i"
                                    class="border-border/50 border-b"
                                >
                                    <td class="text-foreground py-2">
                                        {{ item.name }}
                                        <Badge
                                            v-if="item.source === 'custom'"
                                            variant="outline"
                                            class="ml-2 px-1.5 py-0 text-[10px]"
                                            >custom</Badge
                                        >
                                    </td>
                                    <td
                                        class="text-foreground py-2 text-center"
                                    >
                                        {{ item.quantity }}
                                    </td>
                                    <td
                                        class="text-muted-foreground py-2 text-right"
                                    >
                                        {{ formatPrice(item.price) }}
                                    </td>
                                    <td
                                        class="text-foreground py-2 text-right font-medium"
                                    >
                                        {{
                                            formatPrice(
                                                item.price * item.quantity,
                                            )
                                        }}
                                    </td>
                                    <td class="py-2 text-right">
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            class="text-destructive h-6 w-6 p-0"
                                            @click="removeItem(i)"
                                            >&#x2715;</Button
                                        >
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div
                class="bg-card border-border h-fit rounded-lg border p-4 lg:sticky lg:top-6"
            >
                <h2 class="text-foreground mb-4 font-semibold">
                    Order Summary
                </h2>
                <div
                    v-if="guestMode === 'walk_in' && guestName"
                    class="text-muted-foreground border-border mb-3 border-b pb-3 text-sm"
                >
                    Guest: {{ guestTitle }} {{ guestName }}
                </div>
                <div
                    v-else-if="
                        guestMode === 'reservation' && selectedReservationId
                    "
                    class="text-muted-foreground border-border mb-3 border-b pb-3 text-sm"
                >
                    Guest:
                    {{
                        reservations.find(
                            (r) => String(r.id) === selectedReservationId,
                        )?.guest_name ?? ''
                    }}
                </div>
                <div
                    v-if="orderItems.length === 0"
                    class="text-muted-foreground py-6 text-center text-sm"
                >
                    No items added yet.
                </div>
                <template v-else>
                    <div class="mb-4 space-y-2">
                        <div
                            v-for="(item, i) in orderItems"
                            :key="i"
                            class="flex justify-between text-sm"
                        >
                            <span class="text-muted-foreground"
                                >{{ item.name }} &times;{{
                                    item.quantity
                                }}</span
                            >
                            <span class="text-foreground font-medium">{{
                                formatPrice(item.price * item.quantity)
                            }}</span>
                        </div>
                    </div>
                    <div class="border-border space-y-1 border-t pt-3">
                        <div class="flex justify-between text-sm">
                            <span class="text-muted-foreground">Subtotal</span>
                            <span class="text-foreground">{{
                                formatPrice(subtotal)
                            }}</span>
                        </div>
                        <div class="flex justify-between text-sm">
                            <span class="text-muted-foreground"
                                >Tax ({{ taxRate }}%)</span
                            >
                            <span class="text-foreground">{{
                                formatPrice(tax)
                            }}</span>
                        </div>
                        <div
                            class="border-border flex justify-between border-t pt-1 text-base font-bold"
                        >
                            <span class="text-foreground">Total</span>
                            <span class="text-foreground">{{
                                formatPrice(total)
                            }}</span>
                        </div>
                    </div>
                </template>
                <Button
                    class="mt-4 w-full"
                    :disabled="!canSubmit"
                    @click="submit"
                    >Post Charge</Button
                >
            </div>
        </div>
    </div>
</template>
