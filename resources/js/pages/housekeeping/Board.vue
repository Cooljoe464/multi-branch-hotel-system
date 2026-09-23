<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

interface Task {
    id: number;
    kind: string;
    credits: number;
    status: string;
    inspection_score: number | null;
    room: { number: string };
    assignee: { name: string } | null;
}

interface Load {
    assignee_id: number;
    credits: number;
    tasks: number;
    assignee: { name: string } | null;
}

interface Room {
    id: number;
    number: string;
    status: string;
    condition: string;
    condition_reason: string | null;
}

const props = defineProps<{
    branch: { id: number; name: string };
    tasks: { data: Task[] };
    load: Load[];
    rooms: Room[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Housekeeping Board', href: '#' },
        ],
    },
});

const base = `/branches/${props.branch.id}/housekeeping`;

const taskForm = useForm({
    room_id: null as number | null,
    kind: 'checkout_clean',
    credits: 10,
});
const assignForm = useForm({ assignee_id: undefined as number | undefined });
const completeForm = useForm({
    inspection_score: undefined as number | undefined,
});
const conditionForm = useForm({
    room_id: null as number | null,
    condition: 'clean',
    reason: '',
});
const oooForm = useForm({
    room_id: null as number | null,
    from_date: '',
    to_date: '',
    reason: '',
});
const minibarForm = useForm({
    room_id: null as number | null,
    items: [{ name: '', qty: 1, unit_price_minor: 0 }],
});

function assign(id: number) {
    assignForm.post(`${base}/tasks/${id}/assign`);
}

function complete(id: number) {
    completeForm.post(`${base}/tasks/${id}/complete`);
}

function postMinibar() {
    minibarForm.post(`${base}/rooms/${minibarForm.room_id}/minibar`);
}
</script>

<template>
    <Head title="Housekeeping Board" />
    <div class="space-y-8 p-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold">
                    Housekeeping — {{ branch.name }}
                </h1>
                <p class="text-muted-foreground text-sm">
                    Credit-weighted tasks, room conditions and minibar posting.
                </p>
            </div>
            <Button
                variant="outline"
                @click="router.post(`${base}/auto-allocate`)"
                >Auto-allocate open tasks</Button
            >
        </div>

        <section class="space-y-3">
            <h2 class="text-lg font-medium">Attendant load (credits)</h2>
            <div class="rounded-lg border">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-muted-foreground border-b text-left">
                            <th class="p-3">Attendant</th>
                            <th class="p-3">Open tasks</th>
                            <th class="p-3">Credits</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="l in load"
                            :key="l.assignee_id"
                            class="border-b last:border-0"
                        >
                            <td class="p-3 font-medium">
                                {{ l.assignee?.name ?? `#${l.assignee_id}` }}
                            </td>
                            <td class="p-3">{{ l.tasks }}</td>
                            <td class="p-3 font-mono text-xs">
                                {{ l.credits }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="space-y-3">
            <h2 class="text-lg font-medium">Tasks</h2>
            <form
                @submit.prevent="
                    taskForm.post(`${base}/tasks`, {
                        onSuccess: () => taskForm.reset(),
                    })
                "
                class="flex flex-wrap items-end gap-3 rounded-lg border p-4"
            >
                <div class="grid gap-2">
                    <Label>Room</Label>
                    <select
                        v-model="taskForm.room_id"
                        class="rounded-md border p-2 text-sm"
                        required
                    >
                        <option :value="null" disabled>Select room</option>
                        <option v-for="r in rooms" :key="r.id" :value="r.id">
                            {{ r.number }} ({{ r.condition }})
                        </option>
                    </select>
                </div>
                <div class="grid gap-2">
                    <Label>Kind</Label>
                    <select
                        v-model="taskForm.kind"
                        class="rounded-md border p-2 text-sm"
                    >
                        <option value="checkout_clean">
                            Checkout clean (15)
                        </option>
                        <option value="stayover">Stayover (10)</option>
                        <option value="turndown">Turn-down (5)</option>
                        <option value="inspection">Inspection (5)</option>
                        <option value="minibar_check">Minibar check (3)</option>
                    </select>
                </div>
                <div class="grid gap-2">
                    <Label>Credits</Label
                    ><Input
                        v-model.number="taskForm.credits"
                        type="number"
                        min="1"
                    />
                </div>
                <Button type="submit">Create task</Button>
            </form>
            <div class="rounded-lg border">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-muted-foreground border-b text-left">
                            <th class="p-3">Room</th>
                            <th class="p-3">Kind</th>
                            <th class="p-3">Credits</th>
                            <th class="p-3">Status</th>
                            <th class="p-3">Assignee</th>
                            <th class="p-3">Score</th>
                            <th class="p-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="t in tasks.data"
                            :key="t.id"
                            class="border-b last:border-0"
                        >
                            <td class="p-3 font-medium">{{ t.room.number }}</td>
                            <td class="p-3">{{ t.kind }}</td>
                            <td class="p-3">{{ t.credits }}</td>
                            <td class="p-3 font-mono text-xs">
                                {{ t.status }}
                            </td>
                            <td class="p-3">{{ t.assignee?.name ?? '—' }}</td>
                            <td class="p-3 font-mono text-xs">
                                {{ t.inspection_score ?? '—' }}
                            </td>
                            <td class="p-3">
                                <div class="flex justify-end gap-1">
                                    <Input
                                        v-model.number="assignForm.assignee_id"
                                        type="number"
                                        placeholder="User id"
                                        class="w-24"
                                    />
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        @click="assign(t.id)"
                                        >Assign</Button
                                    >
                                    <Input
                                        v-model.number="
                                            completeForm.inspection_score
                                        "
                                        type="number"
                                        placeholder="Score"
                                        class="w-20"
                                    />
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        @click="complete(t.id)"
                                        >Done</Button
                                    >
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="grid gap-6 md:grid-cols-2">
            <div class="space-y-3">
                <h2 class="text-lg font-medium">Room conditions</h2>
                <form
                    @submit.prevent="
                        conditionForm.post(
                            `${base}/rooms/${conditionForm.room_id ?? ''}/condition`,
                        )
                    "
                    class="grid grid-cols-2 gap-3 rounded-lg border p-4"
                >
                    <div class="grid gap-2">
                        <Label>Room</Label>
                        <select
                            v-model="conditionForm.room_id"
                            class="rounded-md border p-2 text-sm"
                            required
                        >
                            <option :value="null" disabled>Select room</option>
                            <option
                                v-for="r in rooms"
                                :key="r.id"
                                :value="r.id"
                            >
                                {{ r.number }} ({{ r.condition }})
                            </option>
                        </select>
                    </div>
                    <div class="grid gap-2">
                        <Label>Condition</Label>
                        <select
                            v-model="conditionForm.condition"
                            class="rounded-md border p-2 text-sm"
                        >
                            <option value="clean">Clean</option>
                            <option value="dirty">Dirty</option>
                            <option value="inspected">Inspected</option>
                            <option value="ooo">Out of order</option>
                            <option value="oos">Out of service</option>
                        </select>
                    </div>
                    <div class="grid gap-2">
                        <Label>Reason</Label
                        ><Input v-model="conditionForm.reason" />
                    </div>
                    <div class="flex items-end">
                        <Button type="submit">Set condition</Button>
                    </div>
                </form>
                <form
                    @submit.prevent="
                        oooForm.post(
                            `${base}/rooms/${oooForm.room_id}/out-of-order`,
                            { onSuccess: () => oooForm.reset() },
                        )
                    "
                    class="grid grid-cols-2 gap-3 rounded-lg border p-4"
                >
                    <div class="grid gap-2">
                        <Label>Room (OOO range)</Label>
                        <select
                            v-model="oooForm.room_id"
                            class="rounded-md border p-2 text-sm"
                            required
                        >
                            <option :value="null" disabled>Select room</option>
                            <option
                                v-for="r in rooms"
                                :key="r.id"
                                :value="r.id"
                            >
                                {{ r.number }}
                            </option>
                        </select>
                    </div>
                    <div class="grid gap-2">
                        <Label>Reason</Label><Input v-model="oooForm.reason" />
                    </div>
                    <div class="grid gap-2">
                        <Label>From</Label
                        ><Input
                            v-model="oooForm.from_date"
                            type="date"
                            required
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label>To</Label
                        ><Input
                            v-model="oooForm.to_date"
                            type="date"
                            required
                        />
                    </div>
                    <div class="col-span-2 flex items-end">
                        <Button type="submit">Take out of order</Button>
                    </div>
                </form>
            </div>
            <div class="space-y-3">
                <h2 class="text-lg font-medium">Minibar posting</h2>
                <form
                    @submit.prevent="postMinibar"
                    class="grid gap-3 rounded-lg border p-4"
                >
                    <div class="grid gap-2">
                        <Label>Room (checked-in)</Label>
                        <select
                            v-model="minibarForm.room_id"
                            class="rounded-md border p-2 text-sm"
                            required
                        >
                            <option :value="null" disabled>Select room</option>
                            <option
                                v-for="r in rooms"
                                :key="r.id"
                                :value="r.id"
                            >
                                {{ r.number }} ({{ r.status }})
                            </option>
                        </select>
                    </div>
                    <div
                        v-for="(item, i) in minibarForm.items"
                        :key="i"
                        class="grid grid-cols-3 gap-2"
                    >
                        <Input v-model="item.name" placeholder="Item" />
                        <Input
                            v-model.number="item.qty"
                            type="number"
                            min="1"
                            placeholder="Qty"
                        />
                        <Input
                            v-model.number="item.unit_price_minor"
                            type="number"
                            min="0"
                            placeholder="Price minor"
                        />
                    </div>
                    <div class="flex gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            @click="
                                minibarForm.items.push({
                                    name: '',
                                    qty: 1,
                                    unit_price_minor: 0,
                                })
                            "
                            >Add line</Button
                        >
                        <Button type="submit">Post to folio</Button>
                    </div>
                </form>
            </div>
        </section>
    </div>
</template>
