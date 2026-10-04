<script setup lang="ts">
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

interface Branch {
    id: number;
    name: string;
}
interface Room {
    id: number;
    number: string;
    floor: string | null;
    status: string;
}
interface Attendant {
    id: number;
    name: string;
}
interface Assignment {
    attendant_id: number | null;
    kind: string;
    credits: number;
    pinned?: boolean;
}

const props = defineProps<{
    branch: Branch;
    date: string;
    schedule: {
        id: number;
        status: string;
        assignments: Record<string, Assignment>;
    } | null;
    rooms: Room[];
    attendants: Attendant[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'HK Schedule', href: '/housekeeping' },
        ],
    },
});

const date = ref(props.date);
const attendantName = (id: number | null) =>
    props.attendants.find((a) => a.id === id)?.name ?? 'Unassigned';
const roomById = (id: string) => props.rooms.find((r) => String(r.id) === id);

const reload = () =>
    router.get(`/branches/${props.branch.id}/housekeeping/schedule`, {
        date: date.value,
    });
const regenerate = () =>
    router.post(`/branches/${props.branch.id}/housekeeping/schedule/plan`, {
        date: date.value,
    });
const togglePin = (roomId: string) => {
    if (!props.schedule) return;
    const row = props.schedule.assignments[roomId];
    router.post(
        `/branches/${props.branch.id}/housekeeping/schedule/${props.schedule.id}/pin`,
        {
            room_id: parseInt(roomId),
            pinned: !(row?.pinned ?? false),
        },
    );
};
const assignPin = (roomId: string, attendantId: string) => {
    if (!props.schedule) return;
    router.post(
        `/branches/${props.branch.id}/housekeeping/schedule/${props.schedule.id}/pin`,
        {
            room_id: parseInt(roomId),
            attendant_id: parseInt(attendantId),
            pinned: true,
        },
    );
};
const publish = () => {
    if (!props.schedule) return;
    router.post(
        `/branches/${props.branch.id}/housekeeping/schedule/${props.schedule.id}/publish`,
    );
};

const totals = (attendantId: number) =>
    Object.values(props.schedule?.assignments ?? {})
        .filter((a) => a.attendant_id === attendantId)
        .reduce((sum, a) => sum + a.credits, 0);
</script>

<template>
    <Head title="HK Schedule" />
    <div class="p-6">
        <div class="mb-6 flex items-center justify-between">
            <h1 class="text-foreground text-2xl font-bold">
                Housekeeping — {{ branch.name }}
            </h1>
            <div class="flex items-end gap-3">
                <div class="grid gap-2">
                    <Label>Work date</Label><Input v-model="date" type="date" />
                </div>
                <Button variant="outline" @click="reload">Load</Button>
                <Button variant="outline" @click="regenerate"
                    >Regenerate</Button
                >
                <Button
                    v-if="schedule && schedule.status === 'draft'"
                    @click="publish"
                    >Publish</Button
                >
            </div>
        </div>

        <div
            v-if="!schedule"
            class="border-border text-muted-foreground rounded-lg border p-8 text-center text-sm"
        >
            No schedule for {{ date }} yet — press Regenerate to draft one.
        </div>

        <template v-else>
            <p class="text-muted-foreground mb-3 text-sm">
                Status: <Badge variant="outline">{{ schedule.status }}</Badge> ·
                pins survive regeneration.
            </p>
            <div class="mb-6 flex flex-wrap gap-2">
                <Badge v-for="a in attendants" :key="a.id" variant="outline"
                    >{{ a.name }}: {{ totals(a.id) }} cr</Badge
                >
            </div>
            <div class="border-border rounded-lg border">
                <table class="w-full caption-bottom text-sm">
                    <thead class="bg-muted/50">
                        <tr>
                            <th
                                class="text-muted-foreground h-12 px-4 text-left font-medium"
                            >
                                Room
                            </th>
                            <th
                                class="text-muted-foreground h-12 px-4 text-left font-medium"
                            >
                                Kind
                            </th>
                            <th
                                class="text-muted-foreground h-12 px-4 text-left font-medium"
                            >
                                Credits
                            </th>
                            <th
                                class="text-muted-foreground h-12 px-4 text-left font-medium"
                            >
                                Attendant
                            </th>
                            <th
                                class="text-muted-foreground h-12 px-4 text-right font-medium"
                            >
                                Actions
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(row, roomId) in schedule.assignments"
                            :key="roomId"
                            class="border-border hover:bg-muted/50 border-t"
                        >
                            <td class="text-foreground p-4">
                                {{ roomById(roomId)?.number ?? `#${roomId}` }}
                            </td>
                            <td class="p-4">
                                <Badge variant="outline">{{ row.kind }}</Badge>
                            </td>
                            <td class="text-muted-foreground p-4">
                                {{ row.credits }}
                            </td>
                            <td class="p-4">
                                <Select
                                    :model-value="
                                        row.attendant_id
                                            ? String(row.attendant_id)
                                            : ''
                                    "
                                    @update:model-value="
                                        (v) => assignPin(roomId, String(v))
                                    "
                                >
                                    <SelectTrigger class="w-48"
                                        ><SelectValue
                                            :placeholder="
                                                attendantName(row.attendant_id)
                                            "
                                    /></SelectTrigger>
                                    <SelectContent
                                        ><SelectItem
                                            v-for="a in attendants"
                                            :key="a.id"
                                            :value="String(a.id)"
                                            >{{ a.name }}</SelectItem
                                        ></SelectContent
                                    >
                                </Select>
                            </td>
                            <td class="p-4 text-right">
                                <Button
                                    size="sm"
                                    :variant="
                                        row.pinned ? 'default' : 'outline'
                                    "
                                    @click="togglePin(roomId)"
                                    >{{ row.pinned ? 'Pinned' : 'Pin' }}</Button
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>
    </div>
</template>
