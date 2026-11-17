<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Relocations', href: '/relocations' },
        ],
    },
});

const props = defineProps<{
    branch: { id: number; name: string; currency_code: string };
    needing: {
        id: number;
        confirmation_number: string;
        guest_name: string;
        check_in_date: string;
        guests: number;
    }[];
    relocations: {
        id: number;
        confirmation_number: string;
        guest_name: string;
        destination: string;
        status: string;
        compensation_minor: number;
        currency_code: string;
        reason: string;
    }[];
    destinations: { id: number; name: string; rooms: { id: number; number: string }[] }[];
}>();

const selectedId = ref<number | null>(null);
const form = ref({
    destination_branch_id: props.destinations[0]?.id ?? '',
    destination_room_id: '' as number | '',
    compensation: '',
    reason: '',
});
const errors = ref<Record<string, string>>({});
const saving = ref(false);

const rooms = computed(
    () => props.destinations.find((d) => d.id === Number(form.value.destination_branch_id))?.rooms ?? [],
);

const money = (minor: number, currency: string) =>
    new Intl.NumberFormat(undefined, { style: 'currency', currency }).format(minor / 100);

const relocate = () => {
    if (selectedId.value === null) {
        return;
    }

    saving.value = true;
    router.post(
        `/reservations/${selectedId.value}/relocate`,
        {
            destination_branch_id: form.value.destination_branch_id,
            destination_room_id: form.value.destination_room_id,
            compensation_minor: Math.round(Number(form.value.compensation) * 100),
            reason: form.value.reason,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                selectedId.value = null;
                form.value.compensation = '';
                form.value.reason = '';
                errors.value = {};
            },
            onError: (e) => {
                errors.value = e;
            },
            onFinish: () => {
                saving.value = false;
            },
        },
    );
};
</script>

<template>
    <Head title="Relocations" />

    <div class="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-4">
        <div>
            <h1 class="text-foreground text-2xl font-bold">Relocations</h1>
            <p class="text-muted-foreground text-sm">
                Guests {{ branch.name }} cannot house, sent to a partner property with compensation.
            </p>
        </div>

        <section>
            <h2 class="text-foreground mb-2 text-lg font-semibold">Needs relocation</h2>
            <div class="border-border rounded-lg border">
                <div v-if="needing.length === 0" class="text-muted-foreground p-6 text-center text-sm">
                    No overbooked arrivals are waiting.
                </div>
                <div
                    v-for="reservation in needing"
                    :key="reservation.id"
                    class="border-border flex flex-wrap items-center justify-between gap-3 border-b p-4 last:border-b-0"
                >
                    <div>
                        <span class="text-foreground font-medium">{{ reservation.guest_name }}</span>
                        <span class="text-muted-foreground ml-2 text-xs">
                            {{ reservation.confirmation_number }} · arrives {{ reservation.check_in_date }} · {{ reservation.guests }} guest(s)
                        </span>
                    </div>
                    <Button
                        size="sm"
                        :variant="selectedId === reservation.id ? 'default' : 'outline'"
                        @click="selectedId = reservation.id"
                    >
                        Relocate
                    </Button>
                </div>
            </div>
        </section>

        <form
            v-if="selectedId !== null"
            class="border-border grid gap-4 rounded-lg border p-4 md:grid-cols-2"
            @submit.prevent="relocate"
        >
            <h2 class="text-foreground text-lg font-semibold md:col-span-2">Relocate guest</h2>
            <p v-if="destinations.length === 0" class="text-muted-foreground text-sm md:col-span-2">
                No other active property in this group has rooms available.
            </p>

            <div>
                <Label for="rel-branch">Destination property</Label>
                <select
                    id="rel-branch"
                    v-model="form.destination_branch_id"
                    class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                    @change="form.destination_room_id = ''"
                >
                    <option v-for="destination in destinations" :key="destination.id" :value="destination.id">
                        {{ destination.name }}
                    </option>
                </select>
            </div>

            <div>
                <Label for="rel-room">Room</Label>
                <select
                    id="rel-room"
                    v-model="form.destination_room_id"
                    class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                >
                    <option value="" disabled>Select a room</option>
                    <option v-for="room in rooms" :key="room.id" :value="room.id">{{ room.number }}</option>
                </select>
                <p v-if="errors.destination_room_id" class="text-destructive mt-1 text-xs">
                    {{ errors.destination_room_id }}
                </p>
                <p v-if="errors.room_id" class="text-destructive mt-1 text-xs">{{ errors.room_id }}</p>
            </div>

            <div>
                <Label for="rel-comp">Compensation ({{ branch.currency_code }})</Label>
                <Input id="rel-comp" v-model="form.compensation" type="number" min="0.01" step="0.01" />
                <p v-if="errors.compensation_minor" class="text-destructive mt-1 text-xs">
                    {{ errors.compensation_minor }}
                </p>
            </div>

            <div>
                <Label for="rel-reason">Reason</Label>
                <Input id="rel-reason" v-model="form.reason" placeholder="No physical room at arrival" />
                <p v-if="errors.reason" class="text-destructive mt-1 text-xs">{{ errors.reason }}</p>
            </div>

            <div class="flex justify-end gap-2 md:col-span-2">
                <Button type="button" variant="outline" @click="selectedId = null">Cancel</Button>
                <Button type="submit" :disabled="saving">
                    {{ saving ? 'Saving…' : 'Record relocation' }}
                </Button>
            </div>
        </form>

        <section>
            <h2 class="text-foreground mb-2 text-lg font-semibold">Recorded relocations</h2>
            <div class="border-border rounded-lg border">
                <div v-if="relocations.length === 0" class="text-muted-foreground p-6 text-center text-sm">
                    Nothing recorded yet.
                </div>
                <div
                    v-for="relocation in relocations"
                    :key="relocation.id"
                    class="border-border flex flex-wrap items-center justify-between gap-3 border-b p-4 last:border-b-0"
                >
                    <div>
                        <span class="text-foreground font-medium">{{ relocation.guest_name }}</span>
                        <span class="text-muted-foreground ml-2 text-xs">
                            {{ relocation.confirmation_number }} → {{ relocation.destination }} · {{ relocation.reason }}
                        </span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="text-sm">{{ money(relocation.compensation_minor, relocation.currency_code) }}</span>
                        <Badge variant="outline" class="capitalize">{{ relocation.status }}</Badge>
                    </div>
                </div>
            </div>
        </section>
    </div>
</template>
