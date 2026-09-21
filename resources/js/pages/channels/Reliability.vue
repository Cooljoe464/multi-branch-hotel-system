<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

interface Provider {
    id: number;
    provider: string;
    is_active: boolean;
}

interface Mapping {
    id: number;
    channel: string;
    channel_provider_id: number;
    room_type_id: number;
    rate_plan_id: number;
    channel_room_code: string;
    channel_rate_code: string;
}

interface Message {
    id: number;
    channel: string;
    kind: string;
    status: string;
    attempts: number;
    last_error: string | null;
    updated_at: string;
}

interface Run {
    id: number;
    stay_date: string;
    status: string;
    channel_provider: { provider: string };
    diff: Array<Record<string, string | number | null>>;
}

interface Unmapped {
    id: number;
    channel_rate_code: string | null;
    channel_provider: { provider: string };
    room_type: { name: string } | null;
    rate_plan: { code: string } | null;
}

const props = defineProps<{
    messages: { data: Message[] };
    providers: Provider[];
    mappings: Mapping[];
    runs: Run[];
    unmappedRates: Unmapped[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Channel Reliability', href: '#' },
        ],
    },
});

const pushForm = useForm({
    channel_provider_id: null as number | null,
    from: '',
    to: '',
});
const mapForm = useForm({
    channel_provider_id: null as number | null,
    room_type_id: '',
    rate_plan_id: '',
    channel_room_code: '',
    channel_rate_code: '',
});

function replay(id: number) {
    router.post(`/channels/messages/${id}/replay`);
}
</script>

<template>
    <Head title="Channel Reliability" />
    <div class="space-y-8 p-6">
        <div>
            <h1 class="text-2xl font-semibold">Channel Reliability</h1>
            <p class="text-muted-foreground text-sm">
                ARI outbox, code mappings, reconciliation drift and unmapped
                rates.
            </p>
        </div>

        <section class="space-y-3">
            <h2 class="text-lg font-medium">Failure inbox</h2>
            <form
                @submit.prevent="pushForm.post('/channels/messages')"
                class="flex flex-wrap items-end gap-3 rounded-lg border p-4"
            >
                <div class="grid gap-2">
                    <Label>Provider</Label>
                    <Select
                        :model-value="
                            pushForm.channel_provider_id
                                ? String(pushForm.channel_provider_id)
                                : ''
                        "
                        @update:model-value="
                            pushForm.channel_provider_id =
                                $event === '' ? null : Number($event)
                        "
                    >
                        <SelectTrigger
                            ><SelectValue placeholder="Provider"
                        /></SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="p in providers"
                                :key="p.id"
                                :value="String(p.id)"
                                >{{ p.provider }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                </div>
                <div class="grid gap-2">
                    <Label>From</Label
                    ><Input v-model="pushForm.from" type="date" required />
                </div>
                <div class="grid gap-2">
                    <Label>To</Label
                    ><Input v-model="pushForm.to" type="date" required />
                </div>
                <Button type="submit">Queue ARI push</Button>
            </form>
            <div class="rounded-lg border">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-muted-foreground border-b text-left">
                            <th class="p-3">Channel</th>
                            <th class="p-3">Kind</th>
                            <th class="p-3">Status</th>
                            <th class="p-3">Attempts</th>
                            <th class="p-3">Last error</th>
                            <th class="p-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="m in messages.data"
                            :key="m.id"
                            class="border-b last:border-0"
                        >
                            <td class="p-3 font-medium">{{ m.channel }}</td>
                            <td class="p-3">{{ m.kind }}</td>
                            <td class="p-3 font-mono text-xs">
                                {{ m.status }}
                            </td>
                            <td class="p-3">{{ m.attempts }}</td>
                            <td class="p-3 text-xs">
                                {{ m.last_error ?? '—' }}
                            </td>
                            <td class="p-3 text-right">
                                <Button
                                    v-if="m.status !== 'acked'"
                                    variant="outline"
                                    size="sm"
                                    @click="replay(m.id)"
                                    >Replay</Button
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="space-y-3">
            <h2 class="text-lg font-medium">Code mappings</h2>
            <form
                @submit.prevent="mapForm.post('/channels/mappings')"
                class="grid grid-cols-2 gap-3 rounded-lg border p-4 md:grid-cols-5"
            >
                <div class="grid gap-2">
                    <Label>Provider</Label>
                    <Select
                        :model-value="
                            mapForm.channel_provider_id
                                ? String(mapForm.channel_provider_id)
                                : ''
                        "
                        @update:model-value="
                            mapForm.channel_provider_id =
                                $event === '' ? null : Number($event)
                        "
                    >
                        <SelectTrigger
                            ><SelectValue placeholder="Provider"
                        /></SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="p in providers"
                                :key="p.id"
                                :value="String(p.id)"
                                >{{ p.provider }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                </div>
                <div class="grid gap-2">
                    <Label>Room type id</Label
                    ><Input v-model="mapForm.room_type_id" required />
                </div>
                <div class="grid gap-2">
                    <Label>Rate plan id</Label
                    ><Input v-model="mapForm.rate_plan_id" required />
                </div>
                <div class="grid gap-2">
                    <Label>OTA room code</Label
                    ><Input v-model="mapForm.channel_room_code" required />
                </div>
                <div class="grid gap-2">
                    <Label>OTA rate code</Label
                    ><Input v-model="mapForm.channel_rate_code" required />
                </div>
                <div class="col-span-2 flex items-end md:col-span-5">
                    <Button type="submit">Save mapping</Button>
                </div>
            </form>
            <div class="rounded-lg border">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-muted-foreground border-b text-left">
                            <th class="p-3">Channel</th>
                            <th class="p-3">OTA room / rate</th>
                            <th class="p-3">PMS type / plan</th>
                            <th class="p-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="m in mappings"
                            :key="m.id"
                            class="border-b last:border-0"
                        >
                            <td class="p-3 font-medium">{{ m.channel }}</td>
                            <td class="p-3 font-mono text-xs">
                                {{ m.channel_room_code }} /
                                {{ m.channel_rate_code }}
                            </td>
                            <td class="p-3 font-mono text-xs">
                                {{ m.room_type_id }} / {{ m.rate_plan_id }}
                            </td>
                            <td class="p-3 text-right">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    @click="
                                        router.delete(
                                            `/channels/mappings/${m.id}`,
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

        <section class="space-y-3">
            <h2 class="text-lg font-medium">Reconciliation</h2>
            <div class="rounded-lg border">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-muted-foreground border-b text-left">
                            <th class="p-3">Stay date</th>
                            <th class="p-3">Provider</th>
                            <th class="p-3">Status</th>
                            <th class="p-3">Drift entries</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="r in runs"
                            :key="r.id"
                            class="border-b last:border-0"
                        >
                            <td class="p-3 font-medium">{{ r.stay_date }}</td>
                            <td class="p-3">
                                {{ r.channel_provider.provider }}
                            </td>
                            <td class="p-3 font-mono text-xs">
                                {{ r.status }}
                            </td>
                            <td class="p-3 text-xs">
                                {{ r.diff ? r.diff.length : 0 }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section v-if="unmappedRates.length" class="space-y-3">
            <h2 class="text-lg font-medium">Needs mapping</h2>
            <div class="rounded-lg border">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-muted-foreground border-b text-left">
                            <th class="p-3">Provider</th>
                            <th class="p-3">OTA rate code</th>
                            <th class="p-3">PMS type / plan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="u in unmappedRates"
                            :key="u.id"
                            class="border-b last:border-0"
                        >
                            <td class="p-3 font-medium">
                                {{ u.channel_provider.provider }}
                            </td>
                            <td class="p-3 font-mono text-xs">
                                {{ u.channel_rate_code ?? '—' }}
                            </td>
                            <td class="p-3 font-mono text-xs">
                                {{ u.room_type?.name ?? '?' }} /
                                {{ u.rate_plan?.code ?? '?' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
