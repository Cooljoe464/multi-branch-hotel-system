<script setup lang="ts">
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';

interface Branch {
    id: number;
    name: string;
    code: string;
}
interface Tier {
    id: number;
    name: string;
    code: string;
    price_minor: number;
    rate_up_kbps: number;
    rate_down_kbps: number;
    device_limit: number;
    is_active: boolean;
}

const props = defineProps<{
    branch: Branch;
    tiers: Tier[];
    nas_configured: boolean;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Hotspot', href: '/front-desk' }] },
});

const form = ref({
    name: '',
    code: '',
    price_minor: 0,
    rate_up_kbps: 2048,
    rate_down_kbps: 4096,
    device_limit: 2,
});

const save = () =>
    router.post(`/branches/${props.branch.id}/hotspot/tiers`, {
        ...form.value,
    });
const download = () => {
    window.location.href = `/branches/${props.branch.id}/hotspot/export`;
};
</script>

<template>
    <Head title="Hotspot" />
    <div class="p-6">
        <h1 class="text-foreground mb-2 text-2xl font-bold">
            Hotspot — {{ branch.name }}
        </h1>
        <p class="text-muted-foreground mb-6 text-sm">
            Guest Wi-Fi is isolated on its own VLAN + Hotspot. Staff stays on
            PSK.
            <Badge v-if="nas_configured" variant="outline"
                >Tunnel configured</Badge
            >
            <Badge v-else variant="outline">Tunnel pending</Badge>
        </p>

        <h2 class="text-foreground mb-3 text-lg font-semibold">Wi-Fi tiers</h2>
        <div class="mb-6 space-y-1">
            <div
                v-for="t in tiers"
                :key="t.id"
                class="border-border flex items-center justify-between rounded-lg border p-3 text-sm"
            >
                <span class="text-foreground font-medium"
                    >{{ t.name }}
                    <span class="text-muted-foreground font-mono"
                        >({{ t.code }})</span
                    ></span
                >
                <span class="flex items-center gap-2">
                    <span class="font-mono"
                        >{{ t.rate_up_kbps }}k/{{ t.rate_down_kbps }}k ·
                        {{ t.device_limit }} devices · {{ t.price_minor }}</span
                    >
                    <Badge variant="outline">{{
                        t.is_active ? 'active' : 'off'
                    }}</Badge>
                </span>
            </div>
        </div>

        <h2 class="text-foreground mb-3 text-lg font-semibold">
            Add / update tier
        </h2>
        <div class="mb-6 grid max-w-2xl grid-cols-2 gap-3">
            <div class="grid gap-2">
                <Label>Name</Label
                ><Input v-model="form.name" placeholder="Premium High-Speed" />
            </div>
            <div class="grid gap-2">
                <Label>Code</Label
                ><Input v-model="form.code" placeholder="premium" />
            </div>
            <div class="grid gap-2">
                <Label>Price (minor)</Label
                ><Input v-model.number="form.price_minor" type="number" />
            </div>
            <div class="grid gap-2">
                <Label>Device limit</Label
                ><Input v-model.number="form.device_limit" type="number" />
            </div>
            <div class="grid gap-2">
                <Label>Up kbps</Label
                ><Input v-model.number="form.rate_up_kbps" type="number" />
            </div>
            <div class="grid gap-2">
                <Label>Down kbps</Label
                ><Input v-model.number="form.rate_down_kbps" type="number" />
            </div>
        </div>
        <div class="mb-8 flex gap-3">
            <Button @click="save">Save tier</Button>
            <Button variant="outline" @click="download"
                >Download .rsc isolation export</Button
            >
        </div>
    </div>
</template>
