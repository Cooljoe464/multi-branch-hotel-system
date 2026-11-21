<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ref } from 'vue';

interface ChecklistItem {
    id: number;
    task: string;
    done: boolean;
    checked_at: string | null;
    note: string | null;
}

interface OperationalChecklist {
    id: number;
    department: string;
    shift_type: string;
    title: string;
    items: ChecklistItem[];
    status: string;
    completed_by_user?: { name: string } | null;
    verified_by_user?: { name: string } | null;
    created_at: string;
}

const props = defineProps<{
    branch: { id: number; name: string; code: string };
    checklists: {
        data: OperationalChecklist[];
        current_page: number;
        last_page: number;
        total: number;
    };
    filters: { department?: string; shift_type?: string; status?: string };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Operations', href: '#' },
            { title: 'Checklists', href: '#' },
        ],
    },
});

const showCreateModal = ref(false);
const newChecklistForm = useForm({
    department: 'front_desk',
    shift_type: 'morning',
    title: '',
    tasks: ['Check cash float & keys', 'Review VIP arrivals', 'Verify system backups'],
});

const addTask = () => {
    newChecklistForm.tasks.push('');
};

const removeTask = (index: number) => {
    newChecklistForm.tasks.splice(index, 1);
};

const submitCreate = () => {
    newChecklistForm.post(`/branches/${props.branch.id}/operations/checklists`, {
        onSuccess: () => {
            showCreateModal.value = false;
            newChecklistForm.reset();
        },
    });
};

const checkItem = (checklistId: number, itemId: number) => {
    router.post(
        `/branches/${props.branch.id}/operations/checklists/${checklistId}/items/${itemId}/check`,
        {},
        { preserveScroll: true },
    );
};

const completeChecklist = (checklistId: number) => {
    router.post(
        `/branches/${props.branch.id}/operations/checklists/${checklistId}/complete`,
        {},
        { preserveScroll: true },
    );
};

const verifyChecklist = (checklistId: number) => {
    router.post(
        `/branches/${props.branch.id}/operations/checklists/${checklistId}/verify`,
        {},
        { preserveScroll: true },
    );
};
</script>

<template>
    <div class="p-6">
        <Head title="Operational Checklists" />

        <div class="mb-6 flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-foreground">Operational Checklists & Handover</h1>
                <p class="text-sm text-muted-foreground">{{ branch.name }} ({{ branch.code }})</p>
            </div>
            <Button @click="showCreateModal = true">Create Checklist</Button>
        </div>

        <div class="space-y-6">
            <div
                v-for="cl in checklists.data"
                :key="cl.id"
                class="rounded-lg border border-border bg-card p-5 shadow-sm"
            >
                <div class="flex items-start justify-between border-b border-border pb-3">
                    <div>
                        <div class="flex items-center gap-2">
                            <h3 class="text-lg font-semibold text-foreground">{{ cl.title }}</h3>
                            <Badge variant="outline" class="capitalize">
                                {{ cl.department.replace('_', ' ') }} &bull; {{ cl.shift_type }}
                            </Badge>
                        </div>
                        <p class="text-xs text-muted-foreground">
                            Created: {{ new Date(cl.created_at).toLocaleString() }}
                        </p>
                    </div>
                    <Badge :variant="cl.status === 'reviewed' ? 'default' : cl.status === 'completed' ? 'secondary' : 'outline'" class="capitalize">
                        {{ cl.status }}
                    </Badge>
                </div>

                <!-- Checklist Items -->
                <div class="mt-4 space-y-2">
                    <div
                        v-for="item in cl.items"
                        :key="item.id"
                        class="flex items-center justify-between rounded-md border border-border/50 p-2.5 hover:bg-muted/30"
                    >
                        <div class="flex items-center gap-3">
                            <input
                                type="checkbox"
                                :checked="item.done"
                                :disabled="cl.status !== 'pending' || item.done"
                                class="h-4 w-4 rounded border-input"
                                @change="checkItem(cl.id, item.id)"
                            />
                            <span :class="{'line-through text-muted-foreground': item.done, 'font-medium text-foreground': !item.done}">
                                {{ item.task }}
                            </span>
                        </div>
                        <span v-if="item.checked_at" class="text-xs text-muted-foreground">
                            Done at {{ new Date(item.checked_at).toLocaleTimeString() }}
                        </span>
                    </div>
                </div>

                <!-- Footer / Signoff -->
                <div class="mt-4 flex items-center justify-between border-t border-border pt-3 text-xs text-muted-foreground">
                    <div>
                        <span v-if="cl.completed_by_user">Completed by {{ cl.completed_by_user.name }} &bull; </span>
                        <span v-if="cl.verified_by_user">Verified by {{ cl.verified_by_user.name }}</span>
                    </div>
                    <div class="flex gap-2">
                        <Button
                            v-if="cl.status === 'pending'"
                            size="sm"
                            @click="completeChecklist(cl.id)"
                        >
                            Complete Checklist
                        </Button>
                        <Button
                            v-if="cl.status === 'completed'"
                            size="sm"
                            variant="outline"
                            @click="verifyChecklist(cl.id)"
                        >
                            Supervisor Verification
                        </Button>
                    </div>
                </div>
            </div>

            <div v-if="checklists.data.length === 0" class="rounded-lg border border-dashed border-border p-12 text-center text-muted-foreground">
                No active operational checklists for this filter.
            </div>
        </div>

        <!-- Create Modal -->
        <div v-if="showCreateModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div class="w-full max-w-lg rounded-lg border border-border bg-background p-6 shadow-xl">
                <h3 class="text-lg font-semibold">Create Operational Checklist</h3>
                <form @submit.prevent="submitCreate" class="mt-4 space-y-4">
                    <div>
                        <label class="text-sm font-medium">Title</label>
                        <input v-model="newChecklistForm.title" type="text" required class="mt-1 w-full rounded-md border border-input bg-background px-3 py-2 text-sm" placeholder="e.g. Front Desk Shift Handover" />
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="text-sm font-medium">Department</label>
                            <select v-model="newChecklistForm.department" class="mt-1 w-full rounded-md border border-input bg-background px-3 py-2 text-sm">
                                <option value="front_desk">Front Desk</option>
                                <option value="housekeeping">Housekeeping</option>
                                <option value="food_beverage">Food & Beverage</option>
                                <option value="security">Security</option>
                                <option value="maintenance">Maintenance</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-sm font-medium">Shift Type</label>
                            <select v-model="newChecklistForm.shift_type" class="mt-1 w-full rounded-md border border-input bg-background px-3 py-2 text-sm">
                                <option value="morning">Morning Shift</option>
                                <option value="evening">Evening Shift</option>
                                <option value="night">Night Shift</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <div class="flex items-center justify-between">
                            <label class="text-sm font-medium">Tasks</label>
                            <button type="button" class="text-xs text-primary underline" @click="addTask">+ Add Task</button>
                        </div>
                        <div class="mt-2 space-y-2">
                            <div v-for="(task, idx) in newChecklistForm.tasks" :key="idx" class="flex gap-2">
                                <input v-model="newChecklistForm.tasks[idx]" type="text" required class="w-full rounded-md border border-input bg-background px-3 py-1.5 text-sm" />
                                <button v-if="newChecklistForm.tasks.length > 1" type="button" class="text-xs text-destructive" @click="removeTask(idx)">&times;</button>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end gap-2 pt-2">
                        <Button type="button" variant="outline" @click="showCreateModal = false">Cancel</Button>
                        <Button type="submit" :disabled="newChecklistForm.processing">Create Checklist</Button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
