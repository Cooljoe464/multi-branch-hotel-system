<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Server } from '@lucide/vue';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Edge agents', href: '/settings/edge-agents' },
        ],
    },
});

defineProps<{
    branch: { id: number; name: string };
    agents: {
        id: number;
        name: string;
        agent_id: string;
        status: string;
        protocol_version: string;
        last_seen_at: string | null;
        is_stale: boolean;
    }[];
    current_version: string;
}>();

const page = usePage();
const flashToken = computed(
    () => (page.props.flash as Record<string, unknown> | undefined)?.token as string | undefined,
);

const name = ref('');
const errors = ref<Record<string, string>>({});
const saving = ref(false);

const issue = () => {
    saving.value = true;
    router.post('/settings/edge-agents', { name: name.value }, {
        preserveScroll: true,
        onSuccess: () => {
            name.value = '';
            errors.value = {};
        },
        onError: (e) => {
            errors.value = e;
        },
        onFinish: () => {
            saving.value = false;
        },
    });
};

const revoke = (id: number) => {
    router.post(`/settings/edge-agents/${id}/revoke`, {}, { preserveScroll: true });
};
</script>

<template>
    <Head title="Edge agents" />

    <div class="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-4">
        <div>
            <h1 class="text-foreground text-2xl font-bold">Edge agents</h1>
            <p class="text-muted-foreground text-sm">
                On-premises nodes that keep {{ branch.name }} running when the internet is down. Current protocol {{ current_version }}.
            </p>
        </div>

        <div
            v-if="flashToken"
            class="rounded-lg border border-amber-300 bg-amber-50 p-4 dark:bg-amber-950"
        >
            <p class="text-sm font-medium">Copy this token now. It will not be shown again.</p>
            <code class="bg-muted text-foreground mt-2 block break-all rounded px-3 py-2 text-sm">
                {{ flashToken }}
            </code>
        </div>

        <div class="border-border rounded-lg border">
            <div v-if="agents.length === 0" class="text-muted-foreground p-6 text-center text-sm">
                No edge agents yet.
            </div>

            <div
                v-for="agent in agents"
                :key="agent.id"
                class="border-border flex flex-wrap items-center justify-between gap-3 border-b p-4 last:border-b-0"
            >
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <Server class="size-4" />
                        <span class="text-foreground font-medium">{{ agent.name }}</span>
                        <Badge variant="outline">v{{ agent.protocol_version }}</Badge>
                        <Badge
                            variant="outline"
                            :class="
                                agent.status !== 'active'
                                    ? 'border-red-300 text-red-700 dark:text-red-300'
                                    : agent.is_stale
                                      ? 'border-amber-300 text-amber-700 dark:text-amber-300'
                                      : 'border-green-300 text-green-700 dark:text-green-300'
                            "
                        >
                            {{ agent.status !== 'active' ? agent.status : agent.is_stale ? 'offline' : 'online' }}
                        </Badge>
                    </div>
                    <p class="text-muted-foreground mt-1 text-xs">
                        {{ agent.agent_id }} ·
                        {{ agent.last_seen_at ? `Last seen ${new Date(agent.last_seen_at).toLocaleString()}` : 'Never connected' }}
                    </p>
                </div>

                <Button
                    v-if="agent.status === 'active'"
                    size="sm"
                    variant="destructive"
                    @click="revoke(agent.id)"
                >
                    Revoke
                </Button>
            </div>
        </div>

        <form class="border-border flex flex-wrap items-end gap-3 rounded-lg border p-4" @submit.prevent="issue">
            <div class="min-w-64 flex-1">
                <Label for="edge-name">Agent name</Label>
                <Input id="edge-name" v-model="name" placeholder="Front office server" />
                <p v-if="errors.name" class="text-destructive mt-1 text-xs">{{ errors.name }}</p>
            </div>
            <Button type="submit" :disabled="saving">
                {{ saving ? 'Issuing…' : 'Issue token' }}
            </Button>
        </form>
    </div>
</template>
