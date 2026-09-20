<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

interface MenuItem { id: number; name: string; price: number; description: string | null; dietary_flags: string[] | null; image_url: string | null; is_available?: boolean; }
interface TabletSession { id: number; confirmation_number: string; room: { number: string }; }

const props = defineProps<{ menuItems: Record<string, MenuItem[]>; session: TabletSession; }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Menu', href: '#' }] } });

const cart = ref<{ menu_item_id: number; name: string; quantity: number; unit_price: number }[]>([]);
const activeCategory = ref(Object.keys(props.menuItems)[0] || 'food');

const addToCart = (item: MenuItem) => {
    if (item.is_available === false) return;
    const existing = cart.value.find(c => c.menu_item_id === item.id);
    if (existing) { existing.quantity++; }
    else { cart.value.push({ menu_item_id: item.id, name: item.name, quantity: 1, unit_price: item.price }); }
};

const removeFromCart = (index: number) => { cart.value.splice(index, 1); };

const total = () => cart.value.reduce((sum, item) => sum + item.unit_price * item.quantity, 0);

const submitOrder = () => {
    if (cart.value.length === 0) return;
    router.post('/tablet/orders', {
        session_id: props.session.id,
        items: cart.value.map(c => ({ menu_item_id: c.menu_item_id, quantity: c.quantity })),
        payment_method: 'room_charge',
    });
};

import { formatCurrency, getCurrencySymbol } from '@/lib/format';
const page = usePage();
const branchSymbol = computed(() => (page.props.branch?.current as any)?.currency_symbol || '$');
const resolveSymbol = (code?: string) => getCurrencySymbol(code || 'NGN') || branchSymbol.value;
const formatPrice = (cents: number, currencyCode?: string) => formatCurrency(cents, resolveSymbol(currencyCode));

let echo: any = null;

onMounted(() => {
    if (typeof window !== 'undefined' && typeof window.initEcho === 'function') {
        echo = window.initEcho();
        echo.channel(`branch.${props.session.id}.menu`).listen('.menu.stock.toggled', (data: any) => {
            const categories = Object.values(props.menuItems).flat();
            const item = categories.find((m: MenuItem) => m.id === data.id);
            if (item) item.is_available = data.is_available;
        });
    }
});

onUnmounted(() => {
    if (echo) echo.disconnect();
});
</script>

<template>
    <Head title="Tablet Menu" />
<div class="min-h-screen bg-background p-4">
    <div class="mb-4 text-center"><h1 class="text-2xl font-bold text-foreground">Room {{ session.room?.number }} - Menu</h1></div>

    <div class="flex gap-2 mb-4 overflow-x-auto pb-2">
        <Button v-for="(items, cat) in menuItems" :key="cat" :variant="activeCategory === cat ? 'default' : 'outline'" @click="activeCategory = cat as string" class="capitalize whitespace-nowrap">{{ cat }}</Button>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 mb-6">
        <button v-for="item in (menuItems[activeCategory] || [])" :key="item.id" @click="addToCart(item)" class="bg-card rounded-lg border border-border p-3 text-left hover:shadow-md transition-shadow">
            <div class="font-medium text-foreground">{{ item.name }}</div>
            <div class="text-sm text-muted-foreground">{{ formatPrice(item.price) }}</div>
            <div v-if="item.dietary_flags?.length" class="flex gap-1 mt-1">
                <Badge v-for="flag in item.dietary_flags" :key="flag" variant="outline" class="text-xs">{{ flag }}</Badge>
            </div>
        </button>
    </div>

    <div v-if="cart.length > 0" class="fixed bottom-0 left-0 right-0 bg-card border-t border-border p-4 shadow-lg">
        <div class="max-w-lg mx-auto">
            <div v-for="(item, i) in cart" :key="i" class="flex justify-between items-center py-1">
                <span class="text-foreground">{{ item.name }} x{{ item.quantity }}</span>
                <div class="flex items-center gap-2"><span class="text-muted-foreground">{{ formatPrice(item.unit_price * item.quantity) }}</span><Button variant="ghost" size="sm" @click="removeFromCart(i)">×</Button></div>
            </div>
            <div class="border-t border-border mt-2 pt-2 flex justify-between font-bold"><span>Total</span><span>{{ formatPrice(total()) }}</span></div>
            <Button class="w-full mt-2" @click="submitOrder">Order & Charge to Room</Button>
        </div>
    </div>
</div>
</template>
