<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import LocaleSwitcher from '@/components/LocaleSwitcher.vue';
import { t } from '@/lib/locale';

interface Reservation {
    id: number;
    confirmation_number: string;
    guest_name: string;
}

interface Outlet {
    id: number;
    name: string;
    code: string;
    type: string;
}

interface MenuItem {
    id: number;
    name: string;
    price: number;
    category: string;
    description: string | null;
}

interface CartItem {
    menu_item_id: number;
    name: string;
    price: number;
    quantity: number;
    notes: string;
}

const props = defineProps<{
    reservation: Reservation;
    outlet: Outlet;
    menuItems: MenuItem[];
}>();

const cart = ref<CartItem[]>([]);
const orderNotes = ref('');
const showCart = ref(false);
const isSubmitting = ref(false);

const groupedMenu = computed(() => {
    const groups: Record<string, MenuItem[]> = {};
    for (const item of props.menuItems) {
        if (!groups[item.category]) groups[item.category] = [];
        groups[item.category].push(item);
    }
    return groups;
});

const cartTotal = computed(() => cart.value.reduce((sum, item) => sum + item.price * item.quantity, 0));
const cartCount = computed(() => cart.value.reduce((sum, item) => sum + item.quantity, 0));

import { formatCurrency, getCurrencySymbol } from '@/lib/format';
const page = usePage();
const branchSymbol = computed(() => (page.props.branch?.current as any)?.currency_symbol || '$');
const resolveSymbol = (code?: string) => getCurrencySymbol(code || 'NGN') || branchSymbol.value;
const formatPrice = (cents: number, currencyCode?: string) => formatCurrency(cents, resolveSymbol(currencyCode));

const addToCart = (menuItem: MenuItem) => {
    const existing = cart.value.find(i => i.menu_item_id === menuItem.id);
    if (existing) {
        existing.quantity++;
    } else {
        cart.value.push({
            menu_item_id: menuItem.id,
            name: menuItem.name,
            price: menuItem.price,
            quantity: 1,
            notes: '',
        });
    }
};

const updateQuantity = (menuItemId: number, delta: number) => {
    const item = cart.value.find(i => i.menu_item_id === menuItemId);
    if (!item) return;
    item.quantity += delta;
    if (item.quantity <= 0) {
        cart.value = cart.value.filter(i => i.menu_item_id !== menuItemId);
    }
};

const removeFromCart = (menuItemId: number) => {
    cart.value = cart.value.filter(i => i.menu_item_id !== menuItemId);
};

const submitOrder = () => {
    if (cart.value.length === 0 || isSubmitting.value) return;
    isSubmitting.value = true;

    router.post(`/guest/order/${props.reservation.confirmation_number}`, {
        outlet_code: props.outlet.code,
        items: cart.value.map(i => ({
            menu_item_id: i.menu_item_id,
            quantity: i.quantity,
            notes: i.notes || null,
        })),
    }, {
        onFinish: () => { isSubmitting.value = false; },
    });
};

const typeIcon: Record<string, string> = { restaurant: '\uD83C\uDF7D\uFE0F', bar: '\uD83C\uDF78', spa: '\uD83D\uDC86', gift_shop: '\uD83C\uDF81', laundry: '\uD83D\uDC54' };
</script>

<template>
<Head :title="`${outlet.name} - ${t('portal.order_title')}`" />

<div class="min-h-screen bg-background">
    <div class="bg-card border-b border-border px-4 py-3 sm:px-6">
        <div class="mx-auto max-w-2xl">
            <div class="flex items-center justify-between">
                <div>
                    <Link :href="`/guest/order/${reservation.confirmation_number}`" class="text-xs text-muted-foreground hover:text-foreground">&larr; {{ t('portal.all_outlets') }}</Link>
                    <h1 class="text-lg font-bold text-foreground">{{ typeIcon[outlet.type] || '' }} {{ outlet.name }}</h1>
                </div>
                <div class="flex items-center gap-2">
                    <LocaleSwitcher />
                    <button
                        @click="showCart = !showCart"
                        class="relative rounded-lg border border-border px-3 py-2 text-sm font-medium text-foreground hover:bg-muted"
                    >
                        {{ t('portal.cart') }}
                        <span v-if="cartCount > 0" class="absolute -right-2 -top-2 flex h-5 w-5 items-center justify-center rounded-full bg-primary text-xs text-primary-foreground">
                            {{ cartCount }}
                        </span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="mx-auto max-w-2xl px-4 py-4 sm:px-6">
        <div v-if="menuItems.length === 0" class="rounded-lg border border-border bg-card p-8 text-center">
            <p class="text-muted-foreground">{{ t('portal.no_items') }}</p>
        </div>

        <template v-else>
            <div v-for="(items, category) in groupedMenu" :key="category" class="mb-6">
                <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-muted-foreground">{{ category }}</h2>
                <div class="space-y-2">
                    <div
                        v-for="item in items"
                        :key="item.id"
                        class="flex items-center justify-between rounded-lg border border-border bg-card p-3"
                    >
                        <div class="flex-1 pr-3">
                            <div class="font-medium text-foreground">{{ item.name }}</div>
                            <div v-if="item.description" class="text-xs text-muted-foreground">{{ item.description }}</div>
                            <div class="mt-1 text-sm font-semibold text-foreground">{{ formatPrice(item.price) }}</div>
                        </div>
                        <button
                            @click="addToCart(item)"
                            class="shrink-0 rounded-lg bg-primary px-3 py-1.5 text-xs font-medium text-primary-foreground hover:bg-primary/90"
                        >
                            {{ t('portal.add') }}
                        </button>
                    </div>
                </div>
            </div>
        </template>
    </div>

    <div
        v-if="showCart"
        class="fixed inset-0 z-50 flex items-end justify-center bg-black/50 sm:items-center"
        @click.self="showCart = false"
    >
        <div class="w-full max-w-lg rounded-t-2xl bg-card border border-border p-4 sm:rounded-2xl max-h-[80vh] overflow-y-auto">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-lg font-bold text-foreground">{{ t('portal.your_order') }}</h2>
                <button @click="showCart = false" class="text-muted-foreground hover:text-foreground">&times;</button>
            </div>

            <div v-if="cart.length === 0" class="py-6 text-center text-sm text-muted-foreground">
                {{ t('portal.cart_empty') }}
            </div>

            <template v-else>
                <div class="space-y-3 mb-4">
                    <div v-for="item in cart" :key="item.menu_item_id" class="flex items-center gap-3">
                        <div class="flex-1">
                            <div class="text-sm font-medium text-foreground">{{ item.name }}</div>
                            <div class="text-xs text-muted-foreground">{{ formatPrice(item.price) }} {{ t('portal.each') }}</div>
                        </div>
                        <div class="flex items-center gap-2">
                            <button @click="updateQuantity(item.menu_item_id, -1)" class="h-7 w-7 rounded border border-border text-sm font-medium hover:bg-muted">-</button>
                            <span class="w-6 text-center text-sm text-foreground">{{ item.quantity }}</span>
                            <button @click="updateQuantity(item.menu_item_id, 1)" class="h-7 w-7 rounded border border-border text-sm font-medium hover:bg-muted">+</button>
                        </div>
                        <div class="w-16 text-right text-sm font-medium text-foreground">{{ formatPrice(item.price * item.quantity) }}</div>
                        <button @click="removeFromCart(item.menu_item_id)" class="text-destructive hover:text-destructive/80 text-xs">&times;</button>
                    </div>
                </div>

                <div class="border-t border-border pt-3 mb-4">
                    <div class="flex justify-between text-base font-bold">
                        <span class="text-foreground">{{ t('common.total') }}</span>
                        <span class="text-foreground">{{ formatPrice(cartTotal) }}</span>
                    </div>
                    <p class="mt-1 text-xs text-muted-foreground">{{ t('portal.charged_to_room') }}</p>
                </div>

                <button
                    @click="submitOrder"
                    :disabled="isSubmitting"
                    class="w-full rounded-lg bg-primary py-3 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
                >
                    {{ isSubmitting ? t('portal.placing') : t('portal.place_order') }}
                </button>
            </template>
        </div>
    </div>
</div>
</template>
