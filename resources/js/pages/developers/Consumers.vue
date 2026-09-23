<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

interface Consumer {
    id: number;
    name: string;
    branch_id: number | null;
    branch_name: string | null;
    branch_ids: number[];
    scopes: string[];
    webhook_url: string | null;
    has_webhook_secret: boolean;
    grace_active: boolean;
    is_active: boolean;
    tokens_count: number;
}

interface Delivery {
    id: number;
    consumer_name: string;
    event: string;
    status: string;
    attempts: number;
    last_error: string | null;
    created_at: string | null;
}

interface Branch { id: number; name: string; }

const props = defineProps<{
    consumers: Consumer[];
    deliveries: Delivery[];
    scopes: string[];
    branches: Branch[];
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }, { title: 'Developers', href: '/developers' }] } });

const page = usePage();
const flashToken = computed(() => (page.props.flash as Record<string, unknown> | undefined)?.token as string | undefined);

const showModal = ref(false);
const form = ref({ name: '', branch_id: '', branch_ids: [] as string[], scopes: [] as string[], webhook_url: '' });

const toggleScope = (scope: string) => {
    form.value.scopes = form.value.scopes.includes(scope)
        ? form.value.scopes.filter((s) => s !== scope)
        : [...form.value.scopes, scope];
};

const toggleBranch = (id: string) => {
    form.value.branch_ids = form.value.branch_ids.includes(id)
        ? form.value.branch_ids.filter((b) => b !== id)
        : [...form.value.branch_ids, id];
};

const submit = () => {
    router.post('/developers/consumers', {
        name: form.value.name,
        branch_id: form.value.branch_id !== '' ? parseInt(form.value.branch_id) : null,
        branch_ids: form.value.branch_ids.map((b) => parseInt(b)),
        scopes: form.value.scopes,
        webhook_url: form.value.webhook_url !== '' ? form.value.webhook_url : null,
    }, { onSuccess: () => { showModal.value = false; form.value = { name: '', branch_id: '', branch_ids: [], scopes: [], webhook_url: '' }; } });
};

const issueToken = (id: number) => router.post(`/developers/consumers/${id}/tokens`);
const rotate = (id: number) => router.post(`/developers/consumers/${id}/rotate`);
const toggle = (id: number) => router.post(`/developers/consumers/${id}/toggle`);
const replay = (id: number) => router.post(`/developers/deliveries/${id}/replay`);

const statusClass = (status: string) =>
    status === 'delivered' ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200'
    : status === 'failed' ? 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200'
    : 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200';
</script>

<template>
    <Head title="Developers" />
    <div class="p-6">
        <div class="mb-6 flex items-center justify-between">
            <h1 class="text-2xl font-bold text-foreground">API Consumers</h1>
            <Button @click="showModal = true">New Consumer</Button>
        </div>

        <div v-if="flashToken" class="mb-6 rounded-lg border border-amber-300 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-950/30">
            <p class="mb-1 text-sm font-medium text-foreground">Copy this token now — it is shown once:</p>
            <p class="break-all font-mono text-sm text-foreground">{{ flashToken }}</p>
        </div>

        <div class="mb-8 rounded-lg border border-border">
            <table class="w-full caption-bottom text-sm">
                <thead class="bg-muted/50"><tr>
                    <th class="h-12 px-4 text-left font-medium text-muted-foreground">Name</th>
                    <th class="h-12 px-4 text-left font-medium text-muted-foreground">Scope</th>
                    <th class="h-12 px-4 text-left font-medium text-muted-foreground">Scopes</th>
                    <th class="h-12 px-4 text-left font-medium text-muted-foreground">Webhook</th>
                    <th class="h-12 px-4 text-left font-medium text-muted-foreground">Tokens</th>
                    <th class="h-12 px-4 text-left font-medium text-muted-foreground">Status</th>
                    <th class="h-12 px-4 text-right font-medium text-muted-foreground">Actions</th>
                </tr></thead>
                <tbody>
                    <tr v-for="c in consumers" :key="c.id" class="border-t border-border hover:bg-muted/50">
                        <td class="p-4 font-medium text-foreground">{{ c.name }}</td>
                        <td class="p-4 text-muted-foreground">{{ c.branch_name ?? (c.branch_ids.length > 0 ? `${c.branch_ids.length} properties` : '—') }}</td>
                        <td class="p-4"><div class="flex max-w-xs flex-wrap gap-1"><Badge v-for="s in c.scopes" :key="s" variant="outline" class="text-xs">{{ s }}</Badge></div></td>
                        <td class="p-4 text-muted-foreground">
                            <span class="block max-w-48 truncate text-xs">{{ c.webhook_url ?? '—' }}</span>
                            <span v-if="c.has_webhook_secret" class="text-xs">secret ✓<span v-if="c.grace_active" class="text-amber-600"> + grace</span></span>
                        </td>
                        <td class="p-4 text-muted-foreground">{{ c.tokens_count }}</td>
                        <td class="p-4"><Badge variant="outline">{{ c.is_active ? 'active' : 'disabled' }}</Badge></td>
                        <td class="p-4"><div class="flex justify-end gap-2">
                            <Button size="sm" variant="outline" @click="issueToken(c.id)">Token</Button>
                            <Button size="sm" variant="outline" @click="rotate(c.id)">Rotate</Button>
                            <Button size="sm" variant="outline" @click="toggle(c.id)">{{ c.is_active ? 'Disable' : 'Enable' }}</Button>
                        </div></td>
                    </tr>
                    <tr v-if="consumers.length === 0"><td colspan="7" class="p-4 text-center text-muted-foreground">No consumers yet.</td></tr>
                </tbody>
            </table>
        </div>

        <h2 class="mb-4 text-xl font-bold text-foreground">Webhook Deliveries</h2>
        <div class="rounded-lg border border-border">
            <table class="w-full caption-bottom text-sm">
                <thead class="bg-muted/50"><tr>
                    <th class="h-12 px-4 text-left font-medium text-muted-foreground">ID</th>
                    <th class="h-12 px-4 text-left font-medium text-muted-foreground">Consumer</th>
                    <th class="h-12 px-4 text-left font-medium text-muted-foreground">Event</th>
                    <th class="h-12 px-4 text-left font-medium text-muted-foreground">Status</th>
                    <th class="h-12 px-4 text-left font-medium text-muted-foreground">Attempts</th>
                    <th class="h-12 px-4 text-left font-medium text-muted-foreground">Error</th>
                    <th class="h-12 px-4 text-right font-medium text-muted-foreground">Actions</th>
                </tr></thead>
                <tbody>
                    <tr v-for="d in deliveries" :key="d.id" class="border-t border-border hover:bg-muted/50">
                        <td class="p-4 font-mono text-foreground">#{{ d.id }}</td>
                        <td class="p-4 text-muted-foreground">{{ d.consumer_name }}</td>
                        <td class="p-4 text-muted-foreground">{{ d.event }}</td>
                        <td class="p-4"><Badge variant="outline" :class="statusClass(d.status)">{{ d.status }}</Badge></td>
                        <td class="p-4 text-muted-foreground">{{ d.attempts }}</td>
                        <td class="p-4 text-xs text-muted-foreground max-w-64 truncate">{{ d.last_error ?? '—' }}</td>
                        <td class="p-4 text-right"><Button size="sm" variant="outline" @click="replay(d.id)">Replay</Button></td>
                    </tr>
                    <tr v-if="deliveries.length === 0"><td colspan="7" class="p-4 text-center text-muted-foreground">No deliveries yet.</td></tr>
                </tbody>
            </table>
        </div>

        <Dialog :open="showModal" @update:open="showModal = $event">
            <DialogContent class="max-w-lg">
                <DialogHeader><DialogTitle>New API Consumer</DialogTitle></DialogHeader>
                <form @submit.prevent="submit" class="space-y-4">
                    <div class="grid gap-2"><Label>Name</Label><Input v-model="form.name" required placeholder="OTA Partner" /></div>
                    <div class="grid gap-2"><Label>Single property (leave empty for multi-property)</Label>
                        <Select v-model="form.branch_id"><SelectTrigger><SelectValue placeholder="Global (multi-property)" /></SelectTrigger>
                        <SelectContent><SelectItem v-for="b in branches" :key="b.id" :value="String(b.id)">{{ b.name }}</SelectItem></SelectContent></Select>
                    </div>
                    <div v-if="form.branch_id === ''" class="grid gap-2"><Label>Properties (multi-property)</Label>
                        <div class="flex flex-wrap gap-2"><label v-for="b in branches" :key="b.id" class="flex items-center gap-1 text-sm"><input type="checkbox" :value="String(b.id)" @change="toggleBranch(String(b.id))" /> {{ b.name }}</label></div>
                    </div>
                    <div class="grid gap-2"><Label>Scopes</Label>
                        <div class="flex flex-wrap gap-2"><label v-for="s in scopes" :key="s" class="flex items-center gap-1 text-sm"><input type="checkbox" :value="s" @change="toggleScope(s)" /> {{ s }}</label></div>
                    </div>
                    <div class="grid gap-2"><Label>Webhook URL (optional)</Label><Input v-model="form.webhook_url" type="url" placeholder="https://partner.example/hooks/pms" /></div>
                    <DialogFooter><Button type="submit">Create & Issue Token</Button></DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
