<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ArrowLeft } from '@lucide/vue';

interface Outlet {
    id: number;
    name: string;
    code: string;
    type: string;
    is_active: boolean;
}

const props = defineProps<{ outlets: Outlet[] }>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'POS', href: '/pos' },
        ],
    },
});

const typeIcon: Record<string, string> = {
    restaurant: '🍽️',
    bar: '🍸',
    spa: '💆',
    gift_shop: '🎁',
    laundry: '👔',
};

const goBack = () => {
    window.history.back();
};
</script>

<template>
    <Head title="Point of Sale" />
    <div class="p-6">
        <div class="mb-6 flex items-center gap-3">
            <Button
                variant="ghost"
                size="sm"
                @click="goBack()"
                class="text-muted-foreground hover:text-foreground gap-1"
            >
                <ArrowLeft class="size-4" /> Back
            </Button>
            <h1 class="text-foreground text-2xl font-bold">Point of Sale</h1>
        </div>
        <div
            class="grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4"
        >
            <button
                v-for="outlet in outlets"
                :key="outlet.id"
                @click="router.get(`/pos/${outlet.id}`)"
                class="bg-card border-border rounded-lg border p-6 text-left shadow transition-shadow hover:shadow-md"
            >
                <div class="mb-2 text-4xl">
                    {{ typeIcon[outlet.type] || '📦' }}
                </div>
                <div class="text-foreground text-lg font-bold">
                    {{ outlet.name }}
                </div>
                <div class="text-muted-foreground text-sm">
                    {{ outlet.type.replace('_', ' ') }}
                </div>
            </button>
            <div
                v-if="outlets.length === 0"
                class="text-muted-foreground col-span-full py-12 text-center"
            >
                No outlets configured.
            </div>
        </div>
    </div>
</template>
