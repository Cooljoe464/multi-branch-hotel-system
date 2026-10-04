<script setup lang="ts">
import { ref, computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
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

interface BankProfile {
    id: number;
    bank_name: string;
    account_number: string;
    account_name: string;
    swift_code?: string | null;
    sort_code?: string | null;
    currency_code: string;
    is_default: boolean;
}

const props = defineProps<{ bankProfiles: BankProfile[] }>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Bank Profiles', href: '/bank-profiles' },
        ],
    },
});

const page = usePage();
const branchCurrencyCode = computed(
    () => (page.props.branch?.current as any)?.currency_code || 'NGN',
);
const showModal = ref(false);
const editing = ref<BankProfile | null>(null);
const form = ref({
    bank_name: '',
    account_number: '',
    account_name: '',
    swift_code: '',
    sort_code: '',
    currency_code: branchCurrencyCode.value,
    is_default: false,
});

const submit = () => {
    if (editing.value) {
        router.put(`/bank-profiles/${editing.value.id}`, form.value, {
            onSuccess: () => {
                showModal.value = false;
                editing.value = null;
            },
        });
    } else {
        router.post('/bank-profiles', form.value, {
            onSuccess: () => {
                showModal.value = false;
                form.value = {
                    bank_name: '',
                    account_number: '',
                    account_name: '',
                    swift_code: '',
                    sort_code: '',
                    currency_code: branchCurrencyCode.value,
                    is_default: false,
                };
            },
        });
    }
};
const openEdit = (bp: BankProfile) => {
    editing.value = bp;
    form.value = {
        ...bp,
        swift_code: bp.swift_code ?? '',
        sort_code: bp.sort_code ?? '',
    };
    showModal.value = true;
};
const deleteItem = (bp: BankProfile) => {
    if (confirm(`Delete ${bp.bank_name}?`))
        router.delete(`/bank-profiles/${bp.id}`);
};
</script>

<template>
    <div class="p-6">
        <div class="mb-6 flex items-center justify-between">
            <h1 class="text-foreground text-2xl font-bold">Bank Profiles</h1>
            <Button
                @click="
                    editing = null;
                    showModal = true;
                "
                >Add Bank Profile</Button
            >
        </div>
        <div class="border-border rounded-lg border">
            <table class="w-full caption-bottom text-sm">
                <thead class="bg-muted/50">
                    <tr>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Bank
                        </th>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Account
                        </th>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Currency
                        </th>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Default
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
                        v-for="bp in bankProfiles"
                        :key="bp.id"
                        class="border-border hover:bg-muted/50 border-t"
                    >
                        <td class="text-foreground p-4 font-medium">
                            {{ bp.bank_name }}
                        </td>
                        <td class="text-muted-foreground p-4">
                            {{ bp.account_number }}
                        </td>
                        <td class="text-foreground p-4">
                            {{ bp.currency_code }}
                        </td>
                        <td class="p-4">
                            <Badge
                                :class="
                                    bp.is_default
                                        ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300'
                                        : 'bg-muted text-muted-foreground'
                                "
                                variant="outline"
                                >{{ bp.is_default ? 'Default' : 'No' }}</Badge
                            >
                        </td>
                        <td class="p-4 text-right">
                            <div class="flex justify-end gap-1">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    @click="openEdit(bp)"
                                    >Edit</Button
                                ><Button
                                    variant="destructive"
                                    size="sm"
                                    @click="deleteItem(bp)"
                                    >×</Button
                                >
                            </div>
                        </td>
                    </tr>
                    <tr v-if="bankProfiles.length === 0">
                        <td
                            colspan="5"
                            class="text-muted-foreground p-4 text-center"
                        >
                            No bank profiles found.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <Dialog :open="showModal" @update:open="showModal = $event">
            <DialogContent class="max-w-lg">
                <DialogHeader
                    ><DialogTitle
                        >{{ editing ? 'Edit' : 'Add' }} Bank
                        Profile</DialogTitle
                    ></DialogHeader
                >
                <form @submit.prevent="submit" class="space-y-4">
                    <div class="grid gap-2">
                        <Label>Bank Name</Label
                        ><Input v-model="form.bank_name" required />
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="grid gap-2">
                            <Label>Account Number</Label
                            ><Input v-model="form.account_number" required />
                        </div>
                        <div class="grid gap-2">
                            <Label>Account Name</Label
                            ><Input v-model="form.account_name" required />
                        </div>
                    </div>
                    <div class="grid grid-cols-3 gap-4">
                        <div class="grid gap-2">
                            <Label>SWIFT</Label
                            ><Input v-model="form.swift_code" />
                        </div>
                        <div class="grid gap-2">
                            <Label>Sort Code</Label
                            ><Input v-model="form.sort_code" />
                        </div>
                        <div class="grid gap-2">
                            <Label>Currency</Label
                            ><Input v-model="form.currency_code" required />
                        </div>
                    </div>
                    <label class="flex items-center gap-2"
                        ><input
                            v-model="form.is_default"
                            type="checkbox"
                            class="rounded"
                        /><span class="text-foreground text-sm"
                            >Default</span
                        ></label
                    >
                    <DialogFooter
                        ><div class="flex justify-end gap-2">
                            <Button
                                variant="outline"
                                type="button"
                                @click="showModal = false"
                                >Cancel</Button
                            ><Button type="submit">{{
                                editing ? 'Save' : 'Create'
                            }}</Button>
                        </div></DialogFooter
                    >
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
