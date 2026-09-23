<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

interface Item {
    id: number;
    description: string;
    status: string;
    room: { number: string } | null;
    claim: { collected_by: string } | null;
}

const props = defineProps<{
    branch: { id: number; name: string };
    items: { data: Item[] };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Lost & Found', href: '#' },
        ],
    },
});

const form = useForm({
    room_id: undefined as number | undefined,
    description: '',
});
const claimForm = useForm({ collected_by: '' });

const base = `/branches/${props.branch.id}/housekeeping/lost-found`;
</script>

<template>
    <Head title="Lost & Found" />
    <div class="space-y-6 p-6">
        <div>
            <h1 class="text-2xl font-semibold">
                Lost &amp; Found — {{ branch.name }}
            </h1>
            <p class="text-muted-foreground text-sm">
                Logged items, claims and disposals.
            </p>
        </div>

        <form
            @submit.prevent="form.post(base, { onSuccess: () => form.reset() })"
            class="flex flex-wrap items-end gap-3 rounded-lg border p-4"
        >
            <div class="grid gap-2">
                <Label>Description</Label
                ><Input v-model="form.description" required />
            </div>
            <div class="grid gap-2">
                <Label>Room id (optional)</Label
                ><Input v-model.number="form.room_id" type="number" min="1" />
            </div>
            <Button type="submit">Log item</Button>
        </form>

        <div class="rounded-lg border">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-muted-foreground border-b text-left">
                        <th class="p-3">Item</th>
                        <th class="p-3">Room</th>
                        <th class="p-3">Status</th>
                        <th class="p-3">Claim</th>
                        <th class="p-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="item in items.data"
                        :key="item.id"
                        class="border-b last:border-0"
                    >
                        <td class="p-3 font-medium">{{ item.description }}</td>
                        <td class="p-3">{{ item.room?.number ?? '—' }}</td>
                        <td class="p-3 font-mono text-xs">{{ item.status }}</td>
                        <td class="p-3 text-xs">
                            {{ item.claim?.collected_by ?? '—' }}
                        </td>
                        <td class="p-3">
                            <div
                                v-if="item.status === 'logged'"
                                class="flex justify-end gap-1"
                            >
                                <Input
                                    v-model="claimForm.collected_by"
                                    placeholder="Collected by"
                                    class="w-36"
                                />
                                <Button
                                    variant="outline"
                                    size="sm"
                                    @click="
                                        claimForm.post(
                                            `${base}/${item.id}/claim`,
                                        )
                                    "
                                    >Claim</Button
                                >
                                <Button
                                    variant="outline"
                                    size="sm"
                                    @click="
                                        router.post(
                                            `${base}/${item.id}/dispose`,
                                        )
                                    "
                                    >Dispose</Button
                                >
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
