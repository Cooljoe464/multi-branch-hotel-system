<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Inbox } from '@lucide/vue';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Offline conflicts', href: '/offline/conflicts' },
        ],
    },
});

interface Conflict {
    id: number;
    command_uuid: string;
    command_type: string;
    device_id: string;
    room_id: number | null;
    room_type_id: number | null;
    guest_name: string | null;
    check_in_date: string | null;
    check_out_date: string | null;
    status: string;
    reason: string | null;
    created_at: string | null;
    updated_at: string | null;
    resolved_by: number | null;
    resolved_at: string | null;
    resolution_note: string | null;
}

const props = defineProps<{
    branch: { id: number; name: string };
    conflicts: Conflict[];
}>();

const resolvingId = ref<number | null>(null);
const resolutionNote = ref('');

const resolve = (conflict: Conflict) => {
    resolvingId.value = conflict.id;

    router.post(`/api/v1/conflicts/${conflict.id}/resolve`, {
        room_id: conflict.room_id,
        resolution_note: resolutionNote.value || undefined,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            resolvingId.value = null;
            resolutionNote.value = '';
            router.reload({ only: ['conflicts'] });
        },
        onError: () => {
            resolvingId.value = null;
        },
    });
};

const discard = (conflict: Conflict) => {
    router.post(`/api/v1/conflicts/${conflict.id}/discard`, {}, {
        preserveScroll: true,
        onSuccess: () => {
            resolvingId.value = null;
            router.reload({ only: ['conflicts'] });
        },
    });
};
</script>

<template>
    <Head title="Offline conflicts" />

    <div class="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-4">
        <div>
            <h1 class="text-foreground text-2xl font-bold">Offline conflicts</h1>
            <p class="text-muted-foreground text-sm">
                Supervisor queue for room assignments rejected while a device was offline at {{ branch.name }}.
            </p>
        </div>

        <div v-if="conflicts.length === 0" class="border-border rounded-lg border p-6 text-center">
            <Inbox class="text-muted-foreground mx-auto mb-2 h-8 w-8" />
            <p class="text-muted-foreground text-sm">No pending offline conflicts.</p>
        </div>

        <div v-else class="border-border space-y-3 rounded-lg border">
            <div
                v-for="conflict in conflicts"
                :key="conflict.id"
                class="flex flex-col gap-3 border-b p-4 last:border-b-0"
            >
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <Badge variant="destructive">{{ conflict.status }}</Badge>
                        <span class="font-medium">{{ conflict.guest_name }}</span>
                        <span class="text-muted-foreground text-sm">
                            Room {{ conflict.room_id }} · {{ conflict.check_in_date }} → {{ conflict.check_out_date }}
                        </span>
                    </div>
                    <span class="text-muted-foreground text-xs">
                        {{ conflict.device_id }} · {{ conflict.command_type }}
                    </span>
                </div>

                <p v-if="conflict.reason" class="text-muted-foreground text-sm">{{ conflict.reason }}</p>

                <div class="flex flex-wrap items-end gap-2">
                    <div class="flex-1 space-y-1">
                        <Label for="resolution_note">Resolution note</Label>
                        <textarea
                            id="resolution_note"
                            v-model="resolutionNote"
                            placeholder="Supervisor note (optional)"
                            class="border-input bg-background min-h-20 w-full rounded-md border px-3 py-2 text-sm"
                        />
                    </div>
                    <Button
                        :disabled="resolvingId !== null"
                        @click="resolve(conflict)"
                    >
                        Reassign room
                    </Button>
                    <Button
                        variant="outline"
                        :disabled="resolvingId !== null"
                        @click="discard(conflict)"
                    >
                        Discard
                    </Button>
                </div>
            </div>
        </div>
    </div>
</template>
