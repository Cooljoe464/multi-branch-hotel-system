<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

interface Outlet { id: number; name: string; code: string; type: string; tax_rate?: number; }
interface Reservation { id: number; guest_name: string; room_number: string; confirmation_number: string; }
interface MenuItem { id: number; name: string; price: number; category: string; }
interface OrderItem { menu_item_id: number | null; name: string; quantity: number; price: number; source: 'catalog' | 'custom'; }

const props = defineProps<{
    outlet: Outlet;
    reservations: Reservation[];
    menuItems: MenuItem[];
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'POS', href: '/pos' }, { title: 'Terminal', href: '#' }] } });

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
    return props.menuItems.find(m => String(m.id) === selectedItemId.value) ?? null;
});

const subtotal = computed(() => orderItems.value.reduce((sum, item) => sum + item.price * item.quantity, 0));
const taxRate = computed(() => (props.outlet as Record<string, unknown>).tax_rate as number ?? 0);
const tax = computed(() => Math.round(subtotal.value * (taxRate.value / 100)));
const total = computed(() => subtotal.value + tax.value);

const categoryBadgeClass: Record<string, string> = {
    food: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300',
    drink: 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-300',
    laundry: 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-300',
    service: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-300',
};

import { formatCurrency, getCurrencySymbol } from '@/lib/format';
const page = usePage();
const branchSymbol = computed(() => (page.props.branch?.current as any)?.currency_symbol || '$');
const resolveSymbol = (code?: string) => getCurrencySymbol(code || 'NGN') || branchSymbol.value;
const formatPrice = (cents: number, currencyCode?: string) => formatCurrency(cents, resolveSymbol(currencyCode));

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
    const payload: Record<string, unknown> = {
        mode: guestMode.value,
        items: orderItems.value.map(i => ({ menu_item_id: i.menu_item_id, name: i.name, quantity: i.quantity, price: i.price })),
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
<div class="p-6 space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-foreground">{{ outlet.name }} — POS Terminal</h1>
            <p class="text-sm text-muted-foreground">{{ outlet.type.replace('_', ' ') }} &bull; {{ outlet.code }}</p>
        </div>
        <Link href="/pos" class="text-sm text-muted-foreground hover:text-foreground">Back to Outlets</Link>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-4">
            <div class="bg-card rounded-lg border border-border p-4 space-y-4">
                <div class="flex gap-4">
                    <button
                        type="button"
                        class="flex-1 rounded-lg border px-4 py-3 text-sm font-medium transition-colors"
                        :class="guestMode === 'reservation' ? 'border-primary bg-primary/10 text-primary' : 'border-border text-muted-foreground hover:bg-muted'"
                        @click="guestMode = 'reservation'"
                    >
                        Reservation
                    </button>
                    <button
                        type="button"
                        class="flex-1 rounded-lg border px-4 py-3 text-sm font-medium transition-colors"
                        :class="guestMode === 'walk_in' ? 'border-primary bg-primary/10 text-primary' : 'border-border text-muted-foreground hover:bg-muted'"
                        @click="guestMode = 'walk_in'"
                    >
                        Walk-in
                    </button>
                </div>

                <div v-if="guestMode === 'reservation'" class="grid gap-2">
                    <Label>Guest Reservation</Label>
                    <Select v-model="selectedReservationId">
                        <SelectTrigger class="w-full">
                            <SelectValue placeholder="Select a checked-in guest" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="r in reservations"
                                :key="r.id"
                                :value="String(r.id)"
                            >
                                {{ r.guest_name }} — Room {{ r.room_number }} ({{ r.confirmation_number }})
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p v-if="reservations.length === 0" class="text-xs text-muted-foreground">No checked-in reservations found.</p>
                </div>

                <div v-else class="grid grid-cols-[140px_1fr] gap-3 items-end">
                    <div class="grid gap-2">
                        <Label>Title</Label>
                        <Select v-model="guestTitle">
                            <SelectTrigger class="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem v-for="t in titleOptions" :key="t" :value="t">{{ t }}</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <div class="grid gap-2">
                        <Label>Guest Name</Label>
                        <Input v-model="guestName" placeholder="Enter guest name" class="w-full" />
                    </div>
                </div>
            </div>

            <div class="bg-card rounded-lg border border-border p-4 space-y-4">
                <div class="flex items-center justify-between">
                    <h2 class="font-semibold text-foreground">Add Items</h2>
                    <Button variant="ghost" size="sm" @click="customMode = !customMode">
                        {{ customMode ? 'Catalog' : 'Custom Item' }}
                    </Button>
                </div>

                <div v-if="!customMode" class="grid grid-cols-1 sm:grid-cols-[1fr_100px_auto] gap-3 items-end">
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
                                        <Badge variant="outline" :class="['text-[10px] px-1.5 py-0', categoryBadgeClass[item.category] ?? '']">
                                            {{ item.category }}
                                        </Badge>
                                        <span class="ml-auto text-muted-foreground">{{ formatPrice(item.price) }}</span>
                                    </span>
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <p v-if="menuItems.length === 0" class="text-xs text-muted-foreground">No menu items configured for this outlet.</p>
                    </div>
                    <div class="grid gap-2">
                        <Label>Qty</Label>
                        <Input v-model.number="quantity" type="number" min="1" class="w-full" />
                    </div>
                    <Button @click="addItemFromCatalog" :disabled="!selectedItem || quantity < 1">Add</Button>
                </div>

                <div v-else class="grid grid-cols-1 sm:grid-cols-[1fr_120px_120px_auto] gap-3 items-end">
                    <div class="grid gap-2">
                        <Label>Item Name</Label>
                        <Input v-model="customName" placeholder="e.g. Special Request" class="w-full" />
                    </div>
                    <div class="grid gap-2">
                        <Label>Price ({{ currencySymbol }})</Label>
                        <Input v-model.number="customPrice" type="number" min="0" step="0.01" placeholder="0.00" class="w-full" />
                    </div>
                    <div class="grid gap-2">
                        <Label>Qty</Label>
                        <Input v-model.number="quantity" type="number" min="1" class="w-full" />
                    </div>
                    <Button @click="addCustomItem" :disabled="!customName.trim() || customPrice <= 0 || quantity < 1">Add</Button>
                </div>
            </div>

            <div v-if="orderItems.length > 0" class="bg-card rounded-lg border border-border p-4">
                <h2 class="font-semibold text-foreground mb-3">Current Order ({{ orderItems.length }} items)</h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/50">
                            <tr class="border-b border-border">
                                <th class="h-12 px-4 font-medium text-muted-foreground text-left">Item</th>
                                <th class="h-12 px-4 font-medium text-muted-foreground text-center">Qty</th>
                                <th class="h-12 px-4 font-medium text-muted-foreground text-right">Unit Price</th>
                                <th class="h-12 px-4 font-medium text-muted-foreground text-right">Total</th>
                                <th class="h-12 px-4 font-medium text-muted-foreground"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(item, i) in orderItems" :key="i" class="border-b border-border/50">
                                <td class="py-2 text-foreground">
                                    {{ item.name }}
                                    <Badge v-if="item.source === 'custom'" variant="outline" class="ml-2 text-[10px] px-1.5 py-0">custom</Badge>
                                </td>
                                <td class="py-2 text-center text-foreground">{{ item.quantity }}</td>
                                <td class="py-2 text-right text-muted-foreground">{{ formatPrice(item.price) }}</td>
                                <td class="py-2 text-right text-foreground font-medium">{{ formatPrice(item.price * item.quantity) }}</td>
                                <td class="py-2 text-right">
                                    <Button variant="ghost" size="sm" class="h-6 w-6 p-0 text-destructive" @click="removeItem(i)">&#x2715;</Button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="bg-card rounded-lg border border-border p-4 h-fit lg:sticky lg:top-6">
            <h2 class="font-semibold text-foreground mb-4">Order Summary</h2>
            <div v-if="guestMode === 'walk_in' && guestName" class="text-sm text-muted-foreground mb-3 pb-3 border-b border-border">
                Guest: {{ guestTitle }} {{ guestName }}
            </div>
            <div v-else-if="guestMode === 'reservation' && selectedReservationId" class="text-sm text-muted-foreground mb-3 pb-3 border-b border-border">
                Guest: {{ reservations.find(r => String(r.id) === selectedReservationId)?.guest_name ?? '' }}
            </div>
            <div v-if="orderItems.length === 0" class="text-sm text-muted-foreground py-6 text-center">No items added yet.</div>
            <template v-else>
                <div class="space-y-2 mb-4">
                    <div v-for="(item, i) in orderItems" :key="i" class="flex justify-between text-sm">
                        <span class="text-muted-foreground">{{ item.name }} &times;{{ item.quantity }}</span>
                        <span class="text-foreground font-medium">{{ formatPrice(item.price * item.quantity) }}</span>
                    </div>
                </div>
                <div class="border-t border-border pt-3 space-y-1">
                    <div class="flex justify-between text-sm">
                        <span class="text-muted-foreground">Subtotal</span>
                        <span class="text-foreground">{{ formatPrice(subtotal) }}</span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-muted-foreground">Tax ({{ taxRate }}%)</span>
                        <span class="text-foreground">{{ formatPrice(tax) }}</span>
                    </div>
                    <div class="flex justify-between text-base font-bold pt-1 border-t border-border">
                        <span class="text-foreground">Total</span>
                        <span class="text-foreground">{{ formatPrice(total) }}</span>
                    </div>
                </div>
            </template>
            <Button class="w-full mt-4" :disabled="!canSubmit" @click="submit">Post Charge</Button>
        </div>
    </div>
</div>
</template>
