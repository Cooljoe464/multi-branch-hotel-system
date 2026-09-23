<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

interface Asset {
    id: number;
    name: string;
    category: string;
    room_id: number | null;
    last_pm_at: string | null;
    recent_tickets: { id: number; title: string; status: string }[];
}

interface Breach {
    id: number;
    ticket_number: string;
    title: string;
    sla_due_at: string;
    asset: { name: string } | null;
    assignee: { name: string } | null;
}

interface Room {
    id: number;
    number: string;
}

const props = defineProps<{
    branch: { id: number; name: string };
    assets: { data: Asset[] };
    breaches: Breach[];
    rooms: Room[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Assets', href: '#' },
        ],
    },
});

const form = useForm({
    name: '',
    category: 'hvac',
    room_id: undefined as number | undefined,
    every_days: undefined as number | undefined,
});

const base = `/branches/${props.branch.id}/maintenance`;
</script>

<template>
    <Head title="Assets & PM" />
    <div class="space-y-8 p-6">
        <div>
            <h1 class="text-2xl font-semibold">
                Assets &amp; PM — {{ branch.name }}
            </h1>
            <p class="text-muted-foreground text-sm">
                Asset register, preventive schedules and the SLA breach inbox.
            </p>
        </div>

        <section v-if="breaches.length" class="space-y-3">
            <h2 class="text-lg font-medium text-red-700">
                SLA breaches ({{ breaches.length }})
            </h2>
            <div class="rounded-lg border border-red-300">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-muted-foreground border-b text-left">
                            <th class="p-3">Ticket</th>
                            <th class="p-3">Title</th>
                            <th class="p-3">Asset</th>
                            <th class="p-3">Due</th>
                            <th class="p-3">Assignee</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="b in breaches"
                            :key="b.id"
                            class="border-b last:border-0"
                        >
                            <td class="p-3 font-mono text-xs">
                                {{ b.ticket_number }}
                            </td>
                            <td class="p-3 font-medium">{{ b.title }}</td>
                            <td class="p-3">{{ b.asset?.name ?? '—' }}</td>
                            <td class="p-3 font-mono text-xs">
                                {{ b.sla_due_at }}
                            </td>
                            <td class="p-3">{{ b.assignee?.name ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="space-y-3">
            <h2 class="text-lg font-medium">Register asset</h2>
            <form
                @submit.prevent="
                    form.post(`${base}/assets`, {
                        onSuccess: () => form.reset(),
                    })
                "
                class="grid grid-cols-2 gap-3 rounded-lg border p-4 md:grid-cols-4"
            >
                <div class="grid gap-2">
                    <Label>Name</Label><Input v-model="form.name" required />
                </div>
                <div class="grid gap-2">
                    <Label>Category</Label>
                    <select
                        v-model="form.category"
                        class="rounded-md border p-2 text-sm"
                    >
                        <option value="hvac">HVAC</option>
                        <option value="plumbing">Plumbing</option>
                        <option value="electrical">Electrical</option>
                        <option value="furniture">Furniture</option>
                        <option value="appliance">Appliance</option>
                        <option value="structural">Structural</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="grid gap-2">
                    <Label>Room (optional)</Label>
                    <select
                        v-model="form.room_id"
                        class="rounded-md border p-2 text-sm"
                    >
                        <option :value="undefined">No fixed room</option>
                        <option v-for="r in rooms" :key="r.id" :value="r.id">
                            {{ r.number }}
                        </option>
                    </select>
                </div>
                <div class="grid gap-2">
                    <Label>PM every (days, blank = none)</Label
                    ><Input
                        v-model.number="form.every_days"
                        type="number"
                        min="1"
                    />
                </div>
                <div class="col-span-2 flex items-end md:col-span-4">
                    <Button type="submit">Register</Button>
                </div>
            </form>
        </section>

        <section class="space-y-3">
            <h2 class="text-lg font-medium">Assets</h2>
            <div class="rounded-lg border">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-muted-foreground border-b text-left">
                            <th class="p-3">Name</th>
                            <th class="p-3">Category</th>
                            <th class="p-3">Last PM</th>
                            <th class="p-3">Open tickets</th>
                            <th class="p-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="a in assets.data"
                            :key="a.id"
                            class="border-b last:border-0"
                        >
                            <td class="p-3 font-medium">{{ a.name }}</td>
                            <td class="p-3">{{ a.category }}</td>
                            <td class="p-3 font-mono text-xs">
                                {{ a.last_pm_at ?? 'never' }}
                            </td>
                            <td class="p-3 font-mono text-xs">
                                {{ a.recent_tickets.length }}
                            </td>
                            <td class="p-3 text-right">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    @click="
                                        router.delete(`${base}/assets/${a.id}`)
                                    "
                                    >Delete</Button
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
