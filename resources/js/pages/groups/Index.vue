<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

interface Block {
    id: number;
    name: string;
    code: string;
    cutoff_date: string;
    status: string;
    held_nights: number;
    picked_nights: number;
    reservations_count: number;
}

interface Space {
    id: number;
    name: string;
    capacity: number | null;
}

interface RoomType {
    id: number;
    name: string;
    code: string;
}

const props = defineProps<{
    branch: { id: number; name: string; code: string };
    blocks: { data: Block[] };
    spaces: Space[];
    roomTypes: RoomType[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Groups', href: '#' },
        ],
    },
});

const form = useForm({
    name: '',
    code: '',
    cutoff_date: '',
    attrition_pct: 0,
    room_type_id: null as number | null,
    from: '',
    to: '',
    blocked: 10,
});

const spaceForm = useForm({
    name: '',
    capacity: undefined as number | undefined,
});

const base = `/branches/${props.branch.id}/groups`;

function submit() {
    form.transform((data) => ({
        ...data,
        holds: data.room_type_id
            ? [
                  {
                      room_type_id: data.room_type_id,
                      from: data.from,
                      to: data.to,
                      blocked: data.blocked,
                  },
              ]
            : [],
    })).post(base, { onSuccess: () => form.reset() });
}
</script>

<template>
    <Head title="Group Blocks" />
    <div class="space-y-6 p-6">
        <div>
            <h1 class="text-2xl font-semibold">
                Group Blocks — {{ branch.name }}
            </h1>
            <p class="text-muted-foreground text-sm">
                Held inventory, pickup progress and cut-off countdowns.
            </p>
        </div>

        <form
            @submit.prevent="submit"
            class="grid grid-cols-2 gap-3 rounded-lg border p-4 md:grid-cols-4"
        >
            <div class="grid gap-2">
                <Label>Name</Label><Input v-model="form.name" required />
            </div>
            <div class="grid gap-2">
                <Label>Code</Label><Input v-model="form.code" required />
            </div>
            <div class="grid gap-2">
                <Label>Cut-off date</Label
                ><Input v-model="form.cutoff_date" type="date" required />
            </div>
            <div class="grid gap-2">
                <Label>Attrition %</Label
                ><Input
                    v-model.number="form.attrition_pct"
                    type="number"
                    min="0"
                    max="100"
                />
            </div>
            <div class="grid gap-2">
                <Label>Room type</Label>
                <select
                    v-model="form.room_type_id"
                    class="rounded-md border p-2 text-sm"
                    required
                >
                    <option :value="null" disabled>Select type</option>
                    <option v-for="t in roomTypes" :key="t.id" :value="t.id">
                        {{ t.code }} — {{ t.name }}
                    </option>
                </select>
            </div>
            <div class="grid gap-2">
                <Label>Hold from</Label
                ><Input v-model="form.from" type="date" required />
            </div>
            <div class="grid gap-2">
                <Label>Hold to</Label
                ><Input v-model="form.to" type="date" required />
            </div>
            <div class="grid gap-2">
                <Label>Rooms / night</Label
                ><Input v-model.number="form.blocked" type="number" min="1" />
            </div>
            <div class="col-span-2 flex items-end md:col-span-4">
                <Button type="submit">Create block + hold</Button>
            </div>
        </form>

        <div class="rounded-lg border">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-muted-foreground border-b text-left">
                        <th class="p-3">Code</th>
                        <th class="p-3">Name</th>
                        <th class="p-3">Cut-off</th>
                        <th class="p-3">Picked / held</th>
                        <th class="p-3">Status</th>
                        <th class="p-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="b in blocks.data"
                        :key="b.id"
                        class="border-b last:border-0"
                    >
                        <td class="p-3 font-medium">{{ b.code }}</td>
                        <td class="p-3">{{ b.name }}</td>
                        <td class="p-3 font-mono text-xs">
                            {{ b.cutoff_date }}
                        </td>
                        <td class="p-3 font-mono text-xs">
                            {{ b.picked_nights }} / {{ b.held_nights }} ({{
                                b.reservations_count
                            }}
                            res)
                        </td>
                        <td class="p-3 font-mono text-xs">{{ b.status }}</td>
                        <td class="p-3 text-right">
                            <Button
                                variant="outline"
                                size="sm"
                                @click="router.get(`${base}/${b.id}`)"
                                >Open</Button
                            >
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <section class="space-y-3">
            <h2 class="text-lg font-medium">Function spaces</h2>
            <form
                @submit.prevent="
                    spaceForm.post(
                        `/branches/${props.branch.id}/function-spaces`,
                        { onSuccess: () => spaceForm.reset() },
                    )
                "
                class="flex flex-wrap items-end gap-3 rounded-lg border p-4"
            >
                <div class="grid gap-2">
                    <Label>Name</Label
                    ><Input v-model="spaceForm.name" required />
                </div>
                <div class="grid gap-2">
                    <Label>Capacity</Label
                    ><Input
                        v-model.number="spaceForm.capacity"
                        type="number"
                        min="0"
                    />
                </div>
                <Button type="submit">Add space</Button>
            </form>
            <div class="rounded-lg border">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-muted-foreground border-b text-left">
                            <th class="p-3">Name</th>
                            <th class="p-3">Capacity</th>
                            <th class="p-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="s in spaces"
                            :key="s.id"
                            class="border-b last:border-0"
                        >
                            <td class="p-3 font-medium">{{ s.name }}</td>
                            <td class="p-3">{{ s.capacity ?? '—' }}</td>
                            <td class="p-3 text-right">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    @click="
                                        router.delete(
                                            `/branches/${props.branch.id}/function-spaces/${s.id}`,
                                        )
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
