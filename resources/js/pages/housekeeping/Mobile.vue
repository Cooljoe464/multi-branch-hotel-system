<script setup lang="ts">
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

interface Task {
    id: number;
    type: string;
    priority: string;
    status: string;
    description: string;
    notes: string | null;
    estimated_minutes: number | null;
    room: { id: number; number: string; floor: string; } | null;
}

const props = defineProps<{
    tasks: Task[];
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Mobile Tasks', href: '/housekeeping/mobile' }] } });

const getPriorityBadgeClass = (priority: string) => {
    const classes: Record<string, string> = {
        low: 'bg-muted text-muted-foreground',
        normal: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
        high: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200',
        urgent: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
    };
    return classes[priority] || 'bg-muted text-muted-foreground';
};

const getTypeLabel = (type: string) => type.replace('_', ' ').replace(/\b\w/g, (l) => l.toUpperCase());

const startTask = (task: Task) => {
    router.post(`/housekeeping/${task.id}/start`);
};

const completeTask = (task: Task) => {
    const notes = prompt('Completion notes (optional):');
    if (notes !== null) {
        router.post(`/housekeeping/${task.id}/complete`, { notes });
    }
};
</script>

<template>
    <Head title="Mobile Tasks" />
    <div class="min-h-screen bg-background p-4">
        <div class="mb-4">
            <h1 class="text-xl font-bold text-foreground">My Tasks</h1>
            <p class="text-sm text-muted-foreground">{{ tasks.length }} pending task{{ tasks.length !== 1 ? 's' : '' }}</p>
        </div>

        <div v-if="tasks.length === 0" class="text-center text-muted-foreground py-12">
            No tasks assigned.
        </div>

        <div class="space-y-3">
            <div
                v-for="task in tasks"
                :key="task.id"
                class="bg-card rounded-lg shadow border border-border p-4"
            >
                <div class="flex items-center justify-between mb-2">
                    <div class="flex items-center gap-2">
                        <span class="text-lg font-bold text-foreground">
                            {{ task.room?.number || 'N/A' }}
                        </span>
                        <Badge :class="getPriorityBadgeClass(task.priority)" variant="outline" class="text-xs">
                            {{ task.priority }}
                        </Badge>
                    </div>
                    <Badge variant="outline" class="text-xs capitalize">{{ task.status.replace('_', ' ') }}</Badge>
                </div>

                <div class="text-sm text-muted-foreground mb-1">
                    {{ getTypeLabel(task.type) }}
                </div>
                <div v-if="task.description" class="text-sm text-foreground mb-2">
                    {{ task.description }}
                </div>
                <div v-if="task.estimated_minutes" class="text-xs text-muted-foreground mb-3">
                    Est. {{ task.estimated_minutes }} min
                </div>

                <div class="flex gap-2">
                    <Button
                        v-if="task.status === 'pending'"
                        size="sm"
                        class="flex-1"
                        @click="startTask(task)"
                    >
                        Start
                    </Button>
                    <Button
                        v-if="task.status === 'in_progress'"
                        size="sm"
                        variant="default"
                        class="flex-1"
                        @click="completeTask(task)"
                    >
                        Complete
                    </Button>
                </div>
            </div>
        </div>
    </div>
</template>
