<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ArrowLeft } from '@lucide/vue';

interface Outlet { id: number; name: string; code: string; type: string; is_active: boolean; }

const props = defineProps<{ outlets: Outlet[]; }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }, { title: 'POS', href: '/pos' }] } });

const typeIcon: Record<string, string> = { restaurant: '🍽️', bar: '🍸', spa: '💆', gift_shop: '🎁', laundry: '👔' };

const goBack = () => {
    window.history.back();
};
</script>

<template>
    <Head title="Point of Sale" />
<div class="p-6">
    <div class="mb-6 flex items-center gap-3">
        <Button variant="ghost" size="sm" @click="goBack()" class="gap-1 text-muted-foreground hover:text-foreground">
            <ArrowLeft class="size-4" /> Back
        </Button>
        <h1 class="text-2xl font-bold text-foreground">Point of Sale</h1>
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
        <button v-for="outlet in outlets" :key="outlet.id" @click="router.get(`/pos/${outlet.id}`)" class="bg-card rounded-lg shadow border border-border p-6 hover:shadow-md transition-shadow text-left">
            <div class="text-4xl mb-2">{{ typeIcon[outlet.type] || '📦' }}</div>
            <div class="font-bold text-foreground text-lg">{{ outlet.name }}</div>
            <div class="text-sm text-muted-foreground">{{ outlet.type.replace('_', ' ') }}</div>
        </button>
        <div v-if="outlets.length === 0" class="col-span-full text-center text-muted-foreground py-12">No outlets configured.</div>
    </div>
</div>
</template>
