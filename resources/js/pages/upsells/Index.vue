<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

interface Offer {
    id: number;
    kind: string;
    rules: {
        fee_minor: number;
        cutoff_hour: number;
        inventory_guard: boolean;
        target_room_type_id: number | null;
    } | null;
    active: boolean;
}

interface RoomType {
    id: number;
    name: string;
    code: string;
}

const props = defineProps<{
    branch: { id: number; name: string };
    offers: Offer[];
    roomTypes: RoomType[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Upsells', href: '#' },
        ],
    },
});

const form = useForm({
    kind: 'late_checkout',
    fee_minor: 5000,
    cutoff_hour: 12,
    inventory_guard: true,
    target_room_type_id: undefined as number | undefined,
});

const base = `/branches/${props.branch.id}/upsells`;
</script>

<template>
    <Head title="Upsell Offers" />
    <div class="space-y-6 p-6">
        <div>
            <h1 class="text-2xl font-semibold">Upsells — {{ branch.name }}</h1>
            <p class="text-muted-foreground text-sm">
                Priced early check-ins, late checkouts and upgrades. Inventory
                guards re-check live at acceptance.
            </p>
        </div>

        <form
            @submit.prevent="form.post(base, { onSuccess: () => form.reset() })"
            class="grid grid-cols-2 gap-3 rounded-lg border p-4 md:grid-cols-5"
        >
            <div class="grid gap-2">
                <Label>Kind</Label>
                <select
                    v-model="form.kind"
                    class="rounded-md border p-2 text-sm"
                >
                    <option value="early_checkin">Early check-in</option>
                    <option value="late_checkout">Late checkout</option>
                    <option value="upgrade">Upgrade</option>
                </select>
            </div>
            <div class="grid gap-2">
                <Label>Fee (minor)</Label
                ><Input
                    v-model.number="form.fee_minor"
                    type="number"
                    min="0"
                    required
                />
            </div>
            <div class="grid gap-2">
                <Label>Cutoff hour</Label
                ><Input
                    v-model.number="form.cutoff_hour"
                    type="number"
                    min="0"
                    max="23"
                />
            </div>
            <div class="grid gap-2">
                <Label>Upgrade target</Label>
                <select
                    v-model="form.target_room_type_id"
                    class="rounded-md border p-2 text-sm"
                >
                    <option :value="undefined">None</option>
                    <option v-for="t in roomTypes" :key="t.id" :value="t.id">
                        {{ t.code }} — {{ t.name }}
                    </option>
                </select>
            </div>
            <div class="flex items-end">
                <Button type="submit">Save offer</Button>
            </div>
        </form>

        <div class="rounded-lg border">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-muted-foreground border-b text-left">
                        <th class="p-3">Kind</th>
                        <th class="p-3">Fee</th>
                        <th class="p-3">Target</th>
                        <th class="p-3">Active</th>
                        <th class="p-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="o in offers"
                        :key="o.id"
                        class="border-b last:border-0"
                    >
                        <td class="p-3 font-medium">{{ o.kind }}</td>
                        <td class="p-3 font-mono text-xs">
                            {{ o.rules?.fee_minor ?? 0 }}
                        </td>
                        <td class="p-3 font-mono text-xs">
                            {{ o.rules?.target_room_type_id ?? '—' }}
                        </td>
                        <td class="p-3 font-mono text-xs">
                            {{ o.active ? 'yes' : 'no' }}
                        </td>
                        <td class="p-3 text-right">
                            <Button
                                variant="outline"
                                size="sm"
                                @click="
                                    router.put(`${base}/${o.id}`, {
                                        active: !o.active,
                                    })
                                "
                                >{{ o.active ? 'Disable' : 'Enable' }}</Button
                            >
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
