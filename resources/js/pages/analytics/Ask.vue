<script setup lang="ts">
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { newIdempotencyKey } from '@/lib/idempotency';

interface Branch {
    id: number;
    name: string;
}
interface SavedQuery {
    id: number;
    name: string;
    nl: string;
    shared: boolean;
    owner_id: number;
}
interface Answer {
    key: string;
    status: string;
    columns: string[];
    rows: (string | number | null)[][];
    total: number;
    sql: string;
    title: string;
    ms: number;
}

const props = defineProps<{
    branch: Branch;
    queries: SavedQuery[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Ask', href: '/analytics' },
        ],
    },
});

const question = ref('');
const answer = ref<Answer | null>(null);
const error = ref('');
const asking = ref(false);
const saveName = ref('');
const saveShared = ref(false);
let pollTimer: ReturnType<typeof setInterval> | null = null;

const ask = async (q?: string) => {
    const text = q ?? question.value;
    if (text.trim() === '') return;
    asking.value = true;
    error.value = '';
    if (pollTimer) clearInterval(pollTimer);

    try {
        const response = await fetch(
            `/branches/${props.branch.id}/reports/ask`,
            {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN':
                        document
                            .querySelector('meta[name="csrf-token"]')
                            ?.getAttribute('content') || '',
                    'X-Idempotency-Key': newIdempotencyKey(),
                },
                body: JSON.stringify({ question: text }),
            },
        );
        const data = await response.json();
        if (!response.ok) {
            error.value = data.message || 'The question was refused.';
            return;
        }
        answer.value = data.data;
        if (answer.value && answer.value.status === 'pending') {
            pollTimer = setInterval(poll, 2000);
        }
    } catch {
        error.value = 'The question failed to run.';
    } finally {
        asking.value = false;
    }
};

const poll = async () => {
    if (!answer.value) return;
    const response = await fetch(
        `/branches/${props.branch.id}/reports/ask/${answer.value.key}`,
    );
    if (!response.ok) return;
    const data = await response.json();
    answer.value = data.data;
    if (answer.value && answer.value.status !== 'pending' && pollTimer) {
        clearInterval(pollTimer);
    }
};

const save = () => {
    if (!answer.value || saveName.value.trim() === '') return;
    router.post(`/branches/${props.branch.id}/reports/queries`, {
        name: saveName.value,
        nl: question.value,
        sql: answer.value.sql,
        shared: saveShared.value,
    });
};

const maxCell = () => {
    if (!answer.value) return 1;
    return Math.max(1, ...answer.value.rows.map((r) => Number(r[1] ?? 0)));
};
</script>

<template>
    <Head title="Ask" />
    <div class="p-6">
        <h1 class="text-foreground mb-6 text-2xl font-bold">
            Ask — {{ branch.name }}
        </h1>

        <div class="mb-4 flex items-end gap-3">
            <div class="grid flex-1 gap-2">
                <Label>Question</Label>
                <Input
                    v-model="question"
                    placeholder="revenue last 30 days by source"
                    @keyup.enter="ask()"
                />
            </div>
            <Button :disabled="asking" @click="ask()">{{
                asking ? 'Asking…' : 'Ask'
            }}</Button>
        </div>

        <div class="mb-6 flex flex-wrap gap-2">
            <Button
                v-for="q in queries"
                :key="q.id"
                size="sm"
                variant="outline"
                @click="
                    question = q.nl;
                    ask(q.nl);
                "
                >{{ q.name }}</Button
            >
        </div>

        <p
            v-if="error"
            class="border-destructive/50 bg-destructive/10 text-destructive mb-4 rounded-lg border p-3 text-sm"
        >
            {{ error }}
        </p>

        <div v-if="answer" class="border-border mb-8 rounded-lg border p-4">
            <div class="mb-2 flex items-center justify-between">
                <h2 class="text-foreground font-semibold">
                    {{ answer.title }}
                </h2>
                <Badge variant="outline"
                    >{{ answer.status }} · {{ answer.ms }}ms</Badge
                >
            </div>
            <details class="mb-3 text-xs">
                <summary class="text-muted-foreground cursor-pointer">
                    SQL inspector
                </summary>
                <pre
                    class="bg-muted text-foreground mt-1 overflow-x-auto rounded p-2 font-mono"
                    >{{ answer.sql }}</pre>
            </details>
            <table
                v-if="answer.rows.length > 0"
                class="w-full caption-bottom text-sm"
            >
                <thead class="bg-muted/50">
                    <tr>
                        <th
                            v-for="c in answer.columns"
                            :key="c"
                            class="text-muted-foreground h-10 px-3 text-left font-medium"
                        >
                            {{ c }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(row, i) in answer.rows.slice(0, 50)"
                        :key="i"
                        class="border-border border-t"
                    >
                        <td
                            v-for="(cell, j) in row"
                            :key="j"
                            class="text-foreground px-3 py-2"
                        >
                            <span
                                v-if="j === 1 && typeof cell === 'number'"
                                class="flex items-center gap-2"
                            >
                                <span
                                    class="bg-primary inline-block h-2 rounded"
                                    :style="{
                                        width: `${Math.round((cell / maxCell()) * 96)}px`,
                                    }"
                                />
                                {{ cell.toLocaleString() }}
                            </span>
                            <span v-else>{{ cell ?? '—' }}</span>
                        </td>
                    </tr>
                </tbody>
            </table>
            <p v-else class="text-muted-foreground text-sm">
                {{ answer.status === 'pending' ? 'Running…' : 'No rows.' }}
            </p>
            <div
                v-if="answer.status === 'completed'"
                class="mt-3 flex items-end gap-3"
            >
                <div class="grid gap-2">
                    <Label>Save as</Label
                    ><Input
                        v-model="saveName"
                        placeholder="Month-end revenue"
                    />
                </div>
                <label class="flex items-center gap-1 text-sm"
                    ><input v-model="saveShared" type="checkbox" />
                    Shared</label
                >
                <Button size="sm" variant="outline" @click="save">Save</Button>
                <a
                    :href="`/branches/${branch.id}/reports/ask/${answer.key}/export`"
                    class="text-primary text-sm hover:underline"
                    >Export CSV</a
                >
            </div>
        </div>
    </div>
</template>
