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

interface Branch {
    id: number;
    name: string;
}
interface Finding {
    id: number;
    rule_code: string;
    subject_type: string | null;
    subject_id: number | null;
    score: number;
    status: string;
    evidence: Record<string, unknown>;
    created_at: string | null;
}
interface Rule {
    id: number;
    branch_id: number | null;
    code: string;
    params: Record<string, number>;
    active: boolean;
}

const props = defineProps<{
    branch: Branch;
    findings: Finding[];
    rules: Rule[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Anomalies', href: '/audit/flags' },
        ],
    },
});

const tuning = ref<Rule | null>(null);
const tuneForm = ref<Record<string, number>>({});

const openTune = (rule: Rule) => {
    tuning.value = rule;
    tuneForm.value = { ...rule.params };
};

const confirm = (id: number) =>
    router.post(`/branches/${props.branch.id}/anomalies/${id}/confirm`);
const clear = (id: number) =>
    router.post(`/branches/${props.branch.id}/anomalies/${id}/clear`);
const saveTune = () => {
    if (!tuning.value) return;
    router.put(
        `/branches/${props.branch.id}/anomaly-rules/${tuning.value.id}`,
        { params: tuneForm.value },
    );
    tuning.value = null;
};

const statusClass = (status: string) =>
    status === 'confirmed'
        ? 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200'
        : status === 'cleared'
          ? 'bg-muted text-muted-foreground'
          : 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200';
</script>

<template>
    <Head title="Anomalies" />
    <div class="p-6">
        <h1 class="text-foreground mb-6 text-2xl font-bold">
            Anomaly Inbox — {{ branch.name }}
        </h1>

        <div class="border-border mb-8 rounded-lg border">
            <table class="w-full caption-bottom text-sm">
                <thead class="bg-muted/50">
                    <tr>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Rule
                        </th>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Subject
                        </th>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Score
                        </th>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Evidence
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
                        v-for="f in findings"
                        :key="f.id"
                        class="border-border hover:bg-muted/50 border-t"
                    >
                        <td class="text-foreground p-4 font-mono">
                            {{ f.rule_code }}
                        </td>
                        <td class="text-muted-foreground p-4">
                            {{ f.subject_type ?? '—'
                            }}<span v-if="f.subject_id">
                                #{{ f.subject_id }}</span
                            >
                        </td>
                        <td class="text-foreground p-4 font-bold">
                            {{ Math.round(f.score) }}
                        </td>
                        <td
                            class="text-muted-foreground max-w-xs truncate p-4 text-xs"
                        >
                            {{
                                Object.entries(f.evidence)
                                    .map(([k, v]) => `${k}: ${v}`)
                                    .join(' · ')
                            }}
                        </td>
                        <td class="p-4">
                            <Badge
                                variant="outline"
                                :class="statusClass(f.status)"
                                >{{ f.status }}</Badge
                            >
                        </td>
                        <td class="p-4">
                            <div
                                v-if="f.status === 'open'"
                                class="flex justify-end gap-2"
                            >
                                <Button
                                    size="sm"
                                    variant="outline"
                                    @click="confirm(f.id)"
                                    >Confirm</Button
                                >
                                <Button
                                    size="sm"
                                    variant="outline"
                                    @click="clear(f.id)"
                                    >Clear</Button
                                >
                            </div>
                        </td>
                    </tr>
                    <tr v-if="findings.length === 0">
                        <td
                            colspan="6"
                            class="text-muted-foreground p-4 text-center"
                        >
                            No findings. The night is quiet.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <h2 class="text-foreground mb-3 text-lg font-semibold">Rule Tuner</h2>
        <div class="border-border rounded-lg border">
            <table class="w-full caption-bottom text-sm">
                <thead class="bg-muted/50">
                    <tr>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Rule
                        </th>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Scope
                        </th>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Params
                        </th>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Active
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
                        v-for="r in rules"
                        :key="r.id"
                        class="border-border border-t"
                    >
                        <td class="text-foreground p-4 font-mono">
                            {{ r.code }}
                        </td>
                        <td class="text-muted-foreground p-4">
                            {{ r.branch_id ? branch.name : 'global' }}
                        </td>
                        <td class="text-muted-foreground p-4 font-mono text-xs">
                            {{
                                Object.entries(r.params)
                                    .map(([k, v]) => `${k}=${v}`)
                                    .join(' ')
                            }}
                        </td>
                        <td class="p-4">
                            <Badge variant="outline">{{
                                r.active ? 'on' : 'off'
                            }}</Badge>
                        </td>
                        <td class="p-4 text-right">
                            <Button
                                size="sm"
                                variant="outline"
                                @click="openTune(r)"
                                >Tune</Button
                            >
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Dialog
            :open="tuning !== null"
            @update:open="tuning = $event ? tuning : null"
        >
            <DialogContent class="max-w-md">
                <DialogHeader
                    ><DialogTitle
                        >Tune {{ tuning?.code }}</DialogTitle
                    ></DialogHeader
                >
                <div class="space-y-3">
                    <p class="text-muted-foreground text-xs">
                        Tuning a global rule creates a property override; the
                        global default is untouched.
                    </p>
                    <div
                        v-for="(value, key) in tuneForm"
                        :key="key"
                        class="grid gap-1"
                    >
                        <Label class="font-mono text-xs">{{ key }}</Label>
                        <Input
                            v-model.number="tuneForm[key]"
                            type="number"
                            step="0.05"
                        />
                    </div>
                </div>
                <DialogFooter
                    ><Button @click="saveTune"
                        >Save Override</Button
                    ></DialogFooter
                >
            </DialogContent>
        </Dialog>
    </div>
</template>
