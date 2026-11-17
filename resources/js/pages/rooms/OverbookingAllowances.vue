<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Overbooking allowances', href: '#' },
        ],
    },
});

const props = defineProps<{
    branch: { id: number; name: string };
    allowances: {
        id: number;
        room_type: string | null;
        starts_on: string;
        ends_on: string;
        allowed_count: number;
        allowed_sources: string[];
        reason: string;
        revoked_at: string | null;
    }[];
    roomTypes: { id: number; name: string }[];
    twoFactorEnabled: boolean;
}>();

const sources = [
    { key: 'front_desk', label: 'Front desk' },
    { key: 'public_api', label: 'Public API' },
    { key: 'ota', label: 'OTA channels' },
];

const blank = () => ({
    room_type_id: props.roomTypes[0]?.id ?? '',
    starts_on: '',
    ends_on: '',
    allowed_count: 1,
    allowed_sources: ['front_desk'] as string[],
    reason: '',
});

const form = ref(blank());
const errors = ref<Record<string, string>>({});
const saving = ref(false);
const base = `/branches/${props.branch.id}/overbooking-allowances`;

const toggleSource = (key: string) => {
    form.value.allowed_sources = form.value.allowed_sources.includes(key)
        ? form.value.allowed_sources.filter((s) => s !== key)
        : [...form.value.allowed_sources, key];
};

const create = () => {
    saving.value = true;
    router.post(base, form.value, {
        preserveScroll: true,
        onSuccess: () => {
            form.value = blank();
            errors.value = {};
        },
        onError: (e) => {
            errors.value = e;
        },
        onFinish: () => {
            saving.value = false;
        },
    });
};

const revoke = (id: number) => {
    router.delete(`${base}/${id}`, {
        preserveScroll: true,
        onError: (e) => {
            errors.value = e;
        },
    });
};
</script>

<template>
    <Head title="Overbooking allowances" />

    <div class="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-4">
        <div>
            <h1 class="text-foreground text-2xl font-bold">Overbooking allowances</h1>
            <p class="text-muted-foreground text-sm">
                Controlled, audited headroom above physical room count for {{ branch.name }}.
            </p>
        </div>

        <div
            v-if="!twoFactorEnabled"
            class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm dark:bg-amber-950"
        >
            Confirmed two-factor authentication is required to create or revoke allowances.
            <a href="/settings/security" class="underline">Set it up</a>.
        </div>

        <p v-if="errors.room_id" class="text-destructive text-sm">{{ errors.room_id }}</p>

        <div class="border-border overflow-x-auto rounded-lg border">
            <table class="w-full text-sm">
                <thead class="bg-muted text-left">
                    <tr>
                        <th class="p-3">Room type</th>
                        <th class="p-3">Dates</th>
                        <th class="p-3">Extra rooms</th>
                        <th class="p-3">Sources</th>
                        <th class="p-3">Reason</th>
                        <th class="p-3" />
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="allowances.length === 0">
                        <td colspan="6" class="text-muted-foreground p-6 text-center">
                            No allowances yet.
                        </td>
                    </tr>
                    <tr v-for="allowance in allowances" :key="allowance.id" class="border-border border-t">
                        <td class="p-3">{{ allowance.room_type }}</td>
                        <td class="p-3">{{ allowance.starts_on }} → {{ allowance.ends_on }}</td>
                        <td class="p-3">{{ allowance.allowed_count }}</td>
                        <td class="p-3">
                            <Badge
                                v-for="source in allowance.allowed_sources"
                                :key="source"
                                variant="outline"
                                class="mr-1"
                            >
                                {{ source }}
                            </Badge>
                        </td>
                        <td class="p-3">{{ allowance.reason }}</td>
                        <td class="p-3 text-right">
                            <Badge v-if="allowance.revoked_at" variant="outline">Revoked</Badge>
                            <Button v-else size="sm" variant="destructive" @click="revoke(allowance.id)">
                                Revoke
                            </Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <form class="border-border grid gap-4 rounded-lg border p-4 md:grid-cols-2" @submit.prevent="create">
            <h2 class="text-foreground text-lg font-semibold md:col-span-2">New allowance</h2>

            <div>
                <Label for="ob-room-type">Room type</Label>
                <select
                    id="ob-room-type"
                    v-model="form.room_type_id"
                    class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                >
                    <option v-for="roomType in roomTypes" :key="roomType.id" :value="roomType.id">
                        {{ roomType.name }}
                    </option>
                </select>
                <p v-if="errors.room_type_id" class="text-destructive mt-1 text-xs">{{ errors.room_type_id }}</p>
            </div>

            <div>
                <Label for="ob-count">Extra rooms allowed</Label>
                <Input id="ob-count" v-model="form.allowed_count" type="number" min="1" />
                <p v-if="errors.allowed_count" class="text-destructive mt-1 text-xs">{{ errors.allowed_count }}</p>
            </div>

            <div>
                <Label for="ob-start">From</Label>
                <Input id="ob-start" v-model="form.starts_on" type="date" />
                <p v-if="errors.starts_on" class="text-destructive mt-1 text-xs">{{ errors.starts_on }}</p>
            </div>

            <div>
                <Label for="ob-end">To</Label>
                <Input id="ob-end" v-model="form.ends_on" type="date" />
                <p v-if="errors.ends_on" class="text-destructive mt-1 text-xs">{{ errors.ends_on }}</p>
            </div>

            <div class="md:col-span-2">
                <Label>Allowed sources</Label>
                <div class="mt-1 flex flex-wrap gap-4">
                    <label v-for="source in sources" :key="source.key" class="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            :checked="form.allowed_sources.includes(source.key)"
                            @change="toggleSource(source.key)"
                        />
                        {{ source.label }}
                    </label>
                </div>
                <p v-if="errors.allowed_sources" class="text-destructive mt-1 text-xs">{{ errors.allowed_sources }}</p>
            </div>

            <div class="md:col-span-2">
                <Label for="ob-reason">Reason (audited)</Label>
                <Input id="ob-reason" v-model="form.reason" placeholder="Approved event overflow" />
                <p v-if="errors.reason" class="text-destructive mt-1 text-xs">{{ errors.reason }}</p>
            </div>

            <div class="flex justify-end md:col-span-2">
                <Button type="submit" :disabled="saving || !twoFactorEnabled">
                    {{ saving ? 'Saving…' : 'Create allowance' }}
                </Button>
            </div>
        </form>
    </div>
</template>
