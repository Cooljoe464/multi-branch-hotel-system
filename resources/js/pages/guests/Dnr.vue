<script setup lang="ts">
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogFooter,
} from '@/components/ui/dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import Pagination from '@/components/ui/pagination/Pagination.vue';

interface Branch {
    id: number;
    name: string;
    code: string;
}
interface DnrEntry {
    id: number;
    branch_id: number | null;
    guest_id: number | null;
    email: string | null;
    reason: string;
    listed_by: number;
    created_at: string;
    guest: {
        id: number;
        first_name: string;
        last_name: string;
        email: string;
    } | null;
    branch: { id: number; name: string } | null;
}

const props = defineProps<{
    branch: Branch;
    entries: {
        data: DnrEntry[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Do Not Rent', href: '/guests' },
        ],
    },
});

const showModal = ref(false);
const form = ref({ guest_id: '', email: '', reason: '', scope: 'branch' });

const submit = () => {
    router.post(
        `/branches/${props.branch.id}/dnr`,
        {
            guest_id:
                form.value.guest_id !== ''
                    ? parseInt(form.value.guest_id)
                    : null,
            email: form.value.email !== '' ? form.value.email : null,
            reason: form.value.reason,
            scope: form.value.scope,
        },
        {
            onSuccess: () => {
                showModal.value = false;
                form.value = {
                    guest_id: '',
                    email: '',
                    reason: '',
                    scope: 'branch',
                };
            },
        },
    );
};

const destroy = (id: number) =>
    router.delete(`/branches/${props.branch.id}/dnr/${id}`);
const goToPage = (page: number) =>
    router.get(
        `/branches/${props.branch.id}/dnr`,
        { page },
        { preserveState: true, replace: true },
    );
</script>

<template>
    <Head title="Do Not Rent" />
    <div class="p-6">
        <div class="mb-6 flex items-center justify-between">
            <h1 class="text-foreground text-2xl font-bold">
                Do Not Rent — {{ branch.name }}
            </h1>
            <Button @click="showModal = true">List Entry</Button>
        </div>

        <div class="border-border rounded-lg border">
            <table class="w-full caption-bottom text-sm">
                <thead class="bg-muted/50">
                    <tr>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Subject
                        </th>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Scope
                        </th>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Reason
                        </th>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Listed
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
                        v-for="e in entries.data"
                        :key="e.id"
                        class="border-border hover:bg-muted/50 border-t"
                    >
                        <td class="text-foreground p-4">
                            <span v-if="e.guest"
                                >{{ e.guest.first_name }}
                                {{ e.guest.last_name }} ({{
                                    e.guest.email
                                }})</span
                            >
                            <span v-else class="font-mono">{{
                                e.email ?? '—'
                            }}</span>
                        </td>
                        <td class="p-4">
                            <Badge variant="outline">{{
                                e.branch ? e.branch.name : 'Global'
                            }}</Badge>
                        </td>
                        <td class="text-muted-foreground p-4">
                            {{ e.reason }}
                        </td>
                        <td class="text-muted-foreground p-4">
                            {{ e.created_at }}
                        </td>
                        <td class="p-4 text-right">
                            <Button
                                size="sm"
                                variant="outline"
                                @click="destroy(e.id)"
                                >Remove</Button
                            >
                        </td>
                    </tr>
                    <tr v-if="entries.data.length === 0">
                        <td
                            colspan="5"
                            class="text-muted-foreground p-4 text-center"
                        >
                            No entries. Bookings are not blocked.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="mt-4">
            <Pagination
                :data="entries"
                label="entries"
                @page-change="goToPage"
            />
        </div>

        <Dialog :open="showModal" @update:open="showModal = $event">
            <DialogContent class="max-w-lg">
                <DialogHeader
                    ><DialogTitle
                        >List Do-Not-Rent Entry</DialogTitle
                    ></DialogHeader
                >
                <form @submit.prevent="submit" class="space-y-4">
                    <div class="grid gap-2">
                        <Label>Guest ID (optional if email given)</Label
                        ><Input v-model="form.guest_id" inputmode="numeric" />
                    </div>
                    <div class="grid gap-2">
                        <Label>Email (optional if guest given)</Label
                        ><Input v-model="form.email" type="email" />
                    </div>
                    <div class="grid gap-2">
                        <Label>Reason</Label
                        ><Input v-model="form.reason" required />
                    </div>
                    <div class="grid gap-2">
                        <Label>Scope</Label>
                        <Select v-model="form.scope"
                            ><SelectTrigger><SelectValue /></SelectTrigger>
                            <SelectContent
                                ><SelectItem value="branch"
                                    >This property</SelectItem
                                ><SelectItem value="global"
                                    >All properties</SelectItem
                                ></SelectContent
                            ></Select
                        >
                    </div>
                    <DialogFooter
                        ><Button type="submit">List Entry</Button></DialogFooter
                    >
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
