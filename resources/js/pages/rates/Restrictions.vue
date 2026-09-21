<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

interface Plan {
    id: number;
    name: string;
    code: string;
}

interface RoomType {
    id: number;
    name: string;
}

interface RestrictionRow {
    id: number;
    stay_date: string;
    room_type_id: number | null;
    min_los: number | null;
    max_los: number | null;
    cta: boolean;
    ctd: boolean;
    stop_sell: boolean;
    min_advance_hours: number | null;
}

const props = defineProps<{
    branch: { id: number; name: string; code: string };
    plans: Plan[];
    roomTypes: RoomType[];
    filters: { rate_plan_id: number | null; from: string; to: string };
    rows: RestrictionRow[];
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }, { title: 'Restrictions', href: '#' }] } });

const form = useForm({
    rate_plan_id: props.filters.rate_plan_id,
    room_type_id: '' as string | number,
    from: props.filters.from,
    to: props.filters.to,
    min_los: null as number | null,
    max_los: null as number | null,
    cta: false,
    ctd: false,
    stop_sell: false,
    min_advance_hours: null as number | null,
    clear: false,
});

function submit() {
    form.post(`/branches/${props.branch.id}/restrictions`);
}

function reload() {
    router.get(`/branches/${props.branch.id}/restrictions`, { rate_plan_id: form.rate_plan_id, from: form.from, to: form.to }, { preserveState: true });
}
</script>

<template>
    <Head title="Rate Restrictions" />
    <div class="space-y-6 p-6">
        <div>
            <h1 class="text-2xl font-semibold">Rate Restrictions — {{ branch.name }}</h1>
            <p class="text-sm text-muted-foreground">Min/Max LOS, close-to-arrival/departure, stop-sell and minimum advance per night.</p>
        </div>

        <form @submit.prevent="submit" class="grid grid-cols-2 gap-3 rounded-lg border p-4 md:grid-cols-4">
            <div class="grid gap-2">
                <Label>Rate plan</Label>
                <Select :model-value="String(form.rate_plan_id ?? '')" @update:model-value="form.rate_plan_id = Number($event)">
                    <SelectTrigger><SelectValue placeholder="Plan" /></SelectTrigger>
                    <SelectContent>
                        <SelectItem v-for="p in plans" :key="p.id" :value="String(p.id)">{{ p.name }}</SelectItem>
                    </SelectContent>
                </Select>
            </div>
            <div class="grid gap-2">
                <Label>Room type (blank = all)</Label>
                <Select :model-value="String(form.room_type_id ?? '')" @update:model-value="form.room_type_id = $event === '' ? '' : Number($event)">
                    <SelectTrigger><SelectValue placeholder="All types" /></SelectTrigger>
                    <SelectContent>
                        <SelectItem v-for="t in roomTypes" :key="t.id" :value="String(t.id)">{{ t.name }}</SelectItem>
                    </SelectContent>
                </Select>
            </div>
            <div class="grid gap-2">
                <Label>From</Label>
                <Input v-model="form.from" type="date" required />
            </div>
            <div class="grid gap-2">
                <Label>To</Label>
                <Input v-model="form.to" type="date" required />
            </div>
            <div class="grid gap-2">
                <Label>Min LOS</Label>
                <Input v-model.number="form.min_los" type="number" min="1" />
            </div>
            <div class="grid gap-2">
                <Label>Max LOS</Label>
                <Input v-model.number="form.max_los" type="number" min="1" />
            </div>
            <div class="grid gap-2">
                <Label>Min advance (hours)</Label>
                <Input v-model.number="form.min_advance_hours" type="number" min="0" />
            </div>
            <div class="flex items-end gap-4">
                <label class="flex items-center gap-1 text-sm"><input v-model="form.cta" type="checkbox" /> CTA</label>
                <label class="flex items-center gap-1 text-sm"><input v-model="form.ctd" type="checkbox" /> CTD</label>
                <label class="flex items-center gap-1 text-sm"><input v-model="form.stop_sell" type="checkbox" /> Stop-sell</label>
            </div>
            <div class="col-span-2 flex gap-2 md:col-span-4">
                <Button type="submit">Apply to range</Button>
                <Button type="button" variant="outline" @click="reload">Reload grid</Button>
            </div>
        </form>

        <div class="rounded-lg border">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b text-left text-muted-foreground">
                        <th class="p-3">Date</th>
                        <th class="p-3">Type</th>
                        <th class="p-3">Min/Max LOS</th>
                        <th class="p-3">Flags</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in rows" :key="r.id" class="border-b last:border-0">
                        <td class="p-3 font-medium">{{ r.stay_date }}</td>
                        <td class="p-3">{{ r.room_type_id ?? 'all' }}</td>
                        <td class="p-3">{{ r.min_los ?? '—' }} / {{ r.max_los ?? '—' }}</td>
                        <td class="p-3 font-mono text-xs">{{ [r.cta ? 'CTA' : null, r.ctd ? 'CTD' : null, r.stop_sell ? 'STOP' : null].filter(Boolean).join(' ') || '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
