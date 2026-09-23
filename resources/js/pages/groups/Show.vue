<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

interface Night {
    id: number;
    stay_date: string;
    blocked: number;
    picked_up: number;
    room_type: { name: string; code: string };
}

interface GuestBooking {
    id: number;
    guest_name: string;
    confirmation_number: string;
    check_in_date: string;
    check_out_date: string;
    status: string;
}

interface Beo {
    id: number;
    event_date: string;
    agreed_total_minor: number;
    status: string;
    schedule: Record<string, string> | null;
    function_space: { name: string };
}

interface Block {
    id: number;
    name: string;
    code: string;
    cutoff_date: string;
    status: string;
    nights: Night[];
    reservations: GuestBooking[];
    beos: Beo[];
}

interface Space {
    id: number;
    name: string;
}

const props = defineProps<{
    branch: { id: number; name: string };
    block: Block;
    spaces: Space[];
    roomTypes: { id: number; name: string; code: string }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Groups', href: '/groups' },
            { title: 'Block', href: '#' },
        ],
    },
});

const base = `/branches/${props.branch.id}/groups/${props.block.id}`;

const pickupForm = useForm({
    room_type_id: null as number | null,
    guest_name: '',
    guest_email: '',
    check_in_date: '',
    check_out_date: '',
    room_rate: 0,
    adults: 2,
});

const beoForm = useForm({
    function_space_id: null as number | null,
    event_date: '',
    agreed_total_minor: 0,
});

const importForm = useForm({ file: null as File | null });

function submitImport() {
    importForm.post(`${base}/rooming-list`, {
        onSuccess: () => importForm.reset(),
    });
}

function printBeo() {
    window.print();
}
</script>

<template>
    <Head :title="`Block ${block.code}`" />
    <div class="space-y-8 p-6">
        <div
            class="flex flex-wrap items-end justify-between gap-3 print:hidden"
        >
            <div>
                <h1 class="text-2xl font-semibold">
                    {{ block.code }} — {{ block.name }}
                </h1>
                <p class="text-muted-foreground text-sm">
                    Cut-off {{ block.cutoff_date }} · {{ block.status }}
                </p>
            </div>
            <div class="flex gap-2">
                <Button
                    variant="outline"
                    @click="router.post(`${base}/release`)"
                    >Release unpicked + cancel</Button
                >
            </div>
        </div>

        <section class="space-y-3">
            <h2 class="text-lg font-medium">Held nights</h2>
            <div class="rounded-lg border">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-muted-foreground border-b text-left">
                            <th class="p-3">Date</th>
                            <th class="p-3">Type</th>
                            <th class="p-3">Blocked</th>
                            <th class="p-3">Picked up</th>
                            <th class="p-3">Left</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="n in block.nights"
                            :key="n.id"
                            class="border-b last:border-0"
                        >
                            <td class="p-3 font-medium">{{ n.stay_date }}</td>
                            <td class="p-3">{{ n.room_type.code }}</td>
                            <td class="p-3">{{ n.blocked }}</td>
                            <td class="p-3">{{ n.picked_up }}</td>
                            <td class="p-3 font-mono text-xs">
                                {{ n.blocked - n.picked_up }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="space-y-3 print:hidden">
            <h2 class="text-lg font-medium">Pick up a room</h2>
            <form
                @submit.prevent="
                    pickupForm.post(`${base}/pickup`, {
                        onSuccess: () => pickupForm.reset(),
                    })
                "
                class="grid grid-cols-2 gap-3 rounded-lg border p-4 md:grid-cols-4"
            >
                <div class="grid gap-2">
                    <Label>Room type</Label>
                    <select
                        v-model="pickupForm.room_type_id"
                        class="rounded-md border p-2 text-sm"
                        required
                    >
                        <option :value="null" disabled>Select type</option>
                        <option
                            v-for="t in roomTypes"
                            :key="t.id"
                            :value="t.id"
                        >
                            {{ t.code }} — {{ t.name }}
                        </option>
                    </select>
                </div>
                <div class="grid gap-2">
                    <Label>Guest name</Label
                    ><Input v-model="pickupForm.guest_name" required />
                </div>
                <div class="grid gap-2">
                    <Label>Guest email</Label
                    ><Input v-model="pickupForm.guest_email" type="email" />
                </div>
                <div class="grid gap-2">
                    <Label>Nightly rate (minor)</Label
                    ><Input
                        v-model.number="pickupForm.room_rate"
                        type="number"
                        min="0"
                        required
                    />
                </div>
                <div class="grid gap-2">
                    <Label>Check-in</Label
                    ><Input
                        v-model="pickupForm.check_in_date"
                        type="date"
                        required
                    />
                </div>
                <div class="grid gap-2">
                    <Label>Check-out</Label
                    ><Input
                        v-model="pickupForm.check_out_date"
                        type="date"
                        required
                    />
                </div>
                <div class="col-span-2 flex items-end md:col-span-2">
                    <Button type="submit">Pick up</Button>
                </div>
            </form>
        </section>

        <section class="space-y-3">
            <h2 class="text-lg font-medium">
                Picked-up reservations ({{ block.reservations.length }})
            </h2>
            <div class="rounded-lg border">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-muted-foreground border-b text-left">
                            <th class="p-3">Guest</th>
                            <th class="p-3">Confirmation</th>
                            <th class="p-3">Stay</th>
                            <th class="p-3">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="r in block.reservations"
                            :key="r.id"
                            class="border-b last:border-0"
                        >
                            <td class="p-3 font-medium">{{ r.guest_name }}</td>
                            <td class="p-3 font-mono text-xs">
                                {{ r.confirmation_number }}
                            </td>
                            <td class="p-3 font-mono text-xs">
                                {{ r.check_in_date }} → {{ r.check_out_date }}
                            </td>
                            <td class="p-3 font-mono text-xs">
                                {{ r.status }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="space-y-3">
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-medium">Banquet event orders</h2>
                <Button variant="outline" size="sm" @click="printBeo"
                    >Print BEOs</Button
                >
            </div>
            <form
                @submit.prevent="
                    beoForm.post(`${base}/beos`, {
                        onSuccess: () => beoForm.reset(),
                    })
                "
                class="grid grid-cols-2 gap-3 rounded-lg border p-4 md:grid-cols-4 print:hidden"
            >
                <div class="grid gap-2">
                    <Label>Space</Label>
                    <select
                        v-model="beoForm.function_space_id"
                        class="rounded-md border p-2 text-sm"
                        required
                    >
                        <option :value="null" disabled>Select space</option>
                        <option v-for="s in spaces" :key="s.id" :value="s.id">
                            {{ s.name }}
                        </option>
                    </select>
                </div>
                <div class="grid gap-2">
                    <Label>Event date</Label
                    ><Input v-model="beoForm.event_date" type="date" required />
                </div>
                <div class="grid gap-2">
                    <Label>Agreed total (minor)</Label
                    ><Input
                        v-model.number="beoForm.agreed_total_minor"
                        type="number"
                        min="0"
                        required
                    />
                </div>
                <div class="flex items-end">
                    <Button type="submit">Draft BEO</Button>
                </div>
            </form>
            <div
                v-for="beo in block.beos"
                :key="beo.id"
                class="rounded-lg border p-4"
            >
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <div class="font-medium">
                            BEO #{{ beo.id }} — {{ beo.function_space.name }} ·
                            {{ beo.event_date }}
                        </div>
                        <div class="text-muted-foreground text-sm">
                            Agreed {{ beo.agreed_total_minor }} minor ·
                            {{ beo.status }}
                        </div>
                    </div>
                    <Button
                        v-if="beo.status !== 'posted'"
                        class="print:hidden"
                        size="sm"
                        @click="
                            router.post(
                                `/branches/${branch.id}/groups/beos/${beo.id}/post`,
                            )
                        "
                        >Post to master folio</Button
                    >
                </div>
            </div>
        </section>

        <section class="space-y-3 print:hidden">
            <h2 class="text-lg font-medium">Rooming-list import</h2>
            <form
                @submit.prevent="submitImport"
                class="flex flex-wrap items-end gap-3 rounded-lg border p-4"
            >
                <div class="grid gap-2">
                    <Label
                        >CSV / Excel (guest_name, guest_email, room_type_code,
                        check_in_date, check_out_date, adults)</Label
                    ><Input
                        type="file"
                        @change="
                            (e: Event) => {
                                importForm.file = ((
                                    e.target as HTMLInputElement
                                ).files?.[0] ?? null) as File | null;
                            }
                        "
                        required
                    />
                </div>
                <Button type="submit">Queue import</Button>
            </form>
        </section>
    </div>
</template>

<style>
@media print {
    aside,
    nav,
    header {
        display: none !important;
    }
}
</style>
