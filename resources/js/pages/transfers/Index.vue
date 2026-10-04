<script setup lang="ts">
import { ref } from 'vue';
import { Head, router, Link } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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

interface Transfer {
    id: number;
    status: string;
    from_branch: { name: string };
    to_branch: { name: string };
    items: any[];
    created_at: string;
}
interface Branch {
    id: number;
    name: string;
}

const props = defineProps<{
    transfers: {
        data: Transfer[];
        current_page: number;
        last_page: number;
        total: number;
    };
    branches: Branch[];
    filters: { status?: string };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Transfers', href: '/transfers' },
        ],
    },
});

const showModal = ref(false);
const form = ref({
    to_branch_id: '',
    items: [{ name: '', quantity: 1 }],
    notes: '',
});
const addItem = () => form.value.items.push({ name: '', quantity: 1 });
const removeItem = (i: number) => form.value.items.splice(i, 1);

const statusBadge: Record<string, string> = {
    pending:
        'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300',
    approved: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300',
    in_transit:
        'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-300',
    received:
        'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300',
    rejected: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300',
};

const submit = () => {
    router.post('/transfers', form.value, {
        onSuccess: () => {
            showModal.value = false;
            form.value = {
                to_branch_id: '',
                items: [{ name: '', quantity: 1 }],
                notes: '',
            };
        },
    });
};
const goToPage = (page: number) =>
    router.get('/transfers', { page }, { preserveState: true, replace: true });
</script>

<template>
    <Head title="Inter-Property Transfers" />
    <div class="p-6">
        <div class="mb-6 flex items-center justify-between">
            <h1 class="text-foreground text-2xl font-bold">
                Inter-Property Transfers
            </h1>
            <Button @click="showModal = true">New Transfer</Button>
        </div>
        <div class="border-border rounded-lg border">
            <table class="w-full caption-bottom text-sm">
                <thead class="bg-muted/50">
                    <tr>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            From
                        </th>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            To
                        </th>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Items
                        </th>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Status
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
                        v-for="t in transfers.data"
                        :key="t.id"
                        class="border-border hover:bg-muted/50 border-t"
                    >
                        <td class="text-foreground p-4">
                            {{ t.from_branch.name }}
                        </td>
                        <td class="text-foreground p-4">
                            {{ t.to_branch.name }}
                        </td>
                        <td class="text-muted-foreground p-4">
                            {{ t.items.length }} items
                        </td>
                        <td class="p-4">
                            <Badge
                                :class="statusBadge[t.status]"
                                variant="outline"
                                class="capitalize"
                                >{{ t.status.replace('_', ' ') }}</Badge
                            >
                        </td>
                        <td class="p-4 text-right">
                            <Link
                                :href="`/transfers/${t.id}`"
                                class="text-sm text-blue-600 hover:underline dark:text-blue-400"
                                >View</Link
                            >
                        </td>
                    </tr>
                    <tr v-if="transfers.data.length === 0">
                        <td
                            colspan="5"
                            class="text-muted-foreground p-4 text-center"
                        >
                            No transfers found.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="mt-4">
            <Pagination
                :data="transfers"
                label="transfers"
                @page-change="goToPage"
            />
        </div>

        <Dialog :open="showModal" @update:open="showModal = $event">
            <DialogContent class="max-w-lg">
                <DialogHeader
                    ><DialogTitle
                        >New Transfer Request</DialogTitle
                    ></DialogHeader
                >
                <form @submit.prevent="submit" class="space-y-4">
                    <div class="grid gap-2">
                        <Label>To Branch</Label
                        ><Select v-model="form.to_branch_id"
                            ><SelectTrigger
                                ><SelectValue
                                    placeholder="Select branch" /></SelectTrigger
                            ><SelectContent
                                ><SelectItem
                                    v-for="b in branches"
                                    :key="b.id"
                                    :value="String(b.id)"
                                    >{{ b.name }}</SelectItem
                                ></SelectContent
                            ></Select
                        >
                    </div>
                    <div>
                        <Label>Items</Label>
                        <div
                            v-for="(item, i) in form.items"
                            :key="i"
                            class="mt-2 flex gap-2"
                        >
                            <Input
                                v-model="item.name"
                                placeholder="Item name"
                                class="flex-1"
                                required
                            />
                            <Input
                                v-model.number="item.quantity"
                                type="number"
                                min="1"
                                class="w-24"
                                required
                            />
                            <Button
                                variant="destructive"
                                size="sm"
                                type="button"
                                @click="removeItem(i)"
                                >×</Button
                            >
                        </div>
                        <Button
                            variant="outline"
                            size="sm"
                            type="button"
                            class="mt-2"
                            @click="addItem"
                            >+ Add Item</Button
                        >
                    </div>
                    <div class="grid gap-2">
                        <Label>Notes</Label><Input v-model="form.notes" />
                    </div>
                    <DialogFooter
                        ><div class="flex justify-end gap-2">
                            <Button
                                variant="outline"
                                type="button"
                                @click="showModal = false"
                                >Cancel</Button
                            ><Button type="submit">Create</Button>
                        </div></DialogFooter
                    >
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
