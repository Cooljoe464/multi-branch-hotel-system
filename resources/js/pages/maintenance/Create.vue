<script setup lang="ts">
import { Head, router, Link, usePage } from '@inertiajs/vue3';
import { reactive, computed } from 'vue';
import { getCurrencySymbol } from '@/lib/format';

const page = usePage();
const branchSymbol = computed(
    () => (page.props.branch?.current as any)?.currency_symbol || '$',
);
const resolveSymbol = (code?: string) =>
    getCurrencySymbol(code || 'NGN') || branchSymbol.value;
const currencySymbol = computed(() => branchSymbol.value);
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import { Button } from '@/components/ui/button';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Maintenance', href: '/maintenance' },
            { title: 'New Ticket', href: '/maintenance/create' },
        ],
    },
});

const props = defineProps<{
    rooms: Array<{ id: number; number: string; floor: string }>;
}>();

const form = reactive({
    room_id: 'none',
    category: 'plumbing',
    priority: 'normal',
    title: '',
    description: '',
    is_room_locked: false,
    estimated_cost: '',
});

const categoryOptions = [
    { label: 'Plumbing', value: 'plumbing' },
    { label: 'Electrical', value: 'electrical' },
    { label: 'HVAC', value: 'hvac' },
    { label: 'Furniture', value: 'furniture' },
    { label: 'Appliance', value: 'appliance' },
    { label: 'Structural', value: 'structural' },
    { label: 'Other', value: 'other' },
];

const priorityOptions = [
    { label: 'Low', value: 'low' },
    { label: 'Normal', value: 'normal' },
    { label: 'High', value: 'high' },
    { label: 'Urgent', value: 'urgent' },
];

const submit = () => {
    router.post('/maintenance', {
        ...form,
        estimated_cost: form.estimated_cost
            ? Number(form.estimated_cost)
            : null,
    });
};
</script>

<template>
    <Head title="New Maintenance Ticket" />
    <div class="mx-auto max-w-2xl p-6">
        <h1 class="text-foreground mb-6 text-2xl font-bold">
            New Maintenance Ticket
        </h1>

        <form @submit.prevent="submit" class="space-y-6">
            <div class="bg-card border-border rounded-lg border p-6 shadow">
                <div class="space-y-4">
                    <div class="grid gap-2">
                        <Label>Room</Label>
                        <Select v-model="form.room_id">
                            <SelectTrigger class="w-full">
                                <SelectValue
                                    placeholder="No room (general issue)"
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="none"
                                    >No room (general issue)</SelectItem
                                >
                                <SelectItem
                                    v-for="room in rooms"
                                    :key="room.id"
                                    :value="String(room.id)"
                                >
                                    Room {{ room.number }} (Floor
                                    {{ room.floor }})
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div class="grid gap-2">
                        <Label>Title *</Label>
                        <Input
                            v-model="form.title"
                            type="text"
                            required
                            placeholder="Brief description of the issue"
                        />
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div class="grid gap-2">
                            <Label>Category *</Label>
                            <Select v-model="form.category">
                                <SelectTrigger class="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="option in categoryOptions"
                                        :key="option.value"
                                        :value="option.value"
                                    >
                                        {{ option.label }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div class="grid gap-2">
                            <Label>Priority *</Label>
                            <Select v-model="form.priority">
                                <SelectTrigger class="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="option in priorityOptions"
                                        :key="option.value"
                                        :value="option.value"
                                    >
                                        {{ option.label }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </div>

                    <div class="grid gap-2">
                        <Label>Description *</Label>
                        <textarea
                            v-model="form.description"
                            rows="4"
                            required
                            class="border-border bg-background text-foreground placeholder:text-muted-foreground focus:border-primary focus:ring-primary flex w-full rounded-md border px-3 py-2 text-sm shadow-sm focus:outline-none"
                            placeholder="Detailed description of the issue..."
                        ></textarea>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div class="grid gap-2">
                            <Label>Estimated Cost ({{ currencySymbol }})</Label>
                            <Input
                                v-model="form.estimated_cost"
                                type="number"
                                min="0"
                            />
                        </div>
                        <div class="flex items-end">
                            <label class="flex items-center gap-2">
                                <Checkbox
                                    v-model:checked="form.is_room_locked"
                                />
                                <span
                                    class="text-muted-foreground text-sm font-medium"
                                    >Lock room for maintenance</span
                                >
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex justify-end gap-2">
                <Button type="button" variant="outline" as-child>
                    <Link href="/maintenance">Cancel</Link>
                </Button>
                <Button type="submit">Create Ticket</Button>
            </div>
        </form>
    </div>
</template>
