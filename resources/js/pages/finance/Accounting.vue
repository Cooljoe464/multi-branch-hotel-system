<script setup lang="ts">
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

interface Branch { id: number; name: string; }
interface Link { id: number; provider: string; sandbox: boolean; is_active: boolean; connected: boolean; mapped: number; }
interface ExportRow { id: number; business_date: string; provider: string; status: string; external_id: string | null; totals: Record<string, unknown> | null; last_error: string | null; }

const props = defineProps<{
    branch: Branch;
    links: Link[];
    exports: ExportRow[];
    chartCodes: string[];
    providers: string[];
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }, { title: 'Accounting Export', href: '/branches' }] } });

const exportForm = ref({ provider: 'fake', business_date: '' });
const mapForm = ref<Record<number, Record<string, string>>>({});
const newProvider = ref('xero');

const linkFor = (provider: string) => props.links.find((l) => l.provider === provider) ?? null;

const mapValue = (linkId: number, code: string) => mapForm.value[linkId]?.[code] ?? '';

const setMapValue = (linkId: number, code: string, value: string) => {
    if (!mapForm.value[linkId]) mapForm.value[linkId] = {};
    mapForm.value[linkId][code] = value;
};

const createLink = () => router.post(`/branches/${props.branch.id}/accounting/links`, { provider: newProvider.value, sandbox: true });
const saveMap = (linkId: number) => router.put(`/branches/${props.branch.id}/accounting/links/${linkId}`, { account_map: mapForm.value[linkId] ?? {} });
const runExport = () => router.post(`/branches/${props.branch.id}/accounting/exports`, { ...exportForm.value });
const retry = (id: number) => router.post(`/branches/${props.branch.id}/accounting/exports/${id}/retry`);

const statusClass = (status: string) =>
    status === 'delivered' ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200'
    : status === 'failed' ? 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200'
    : 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200';
</script>

<template>
    <Head title="Accounting Export" />
    <div class="p-6">
        <div class="mb-6 flex items-center justify-between">
            <h1 class="text-2xl font-bold text-foreground">Accounting Export — {{ branch.name }}</h1>
        </div>

        <h2 class="mb-3 text-lg font-semibold text-foreground">Providers</h2>
        <div class="mb-4 flex items-end gap-3">
            <div class="grid gap-2"><Label>Link provider</Label>
                <Select v-model="newProvider"><SelectTrigger class="w-48"><SelectValue /></SelectTrigger>
                <SelectContent><SelectItem v-for="p in providers.concat(['fake'])" :key="p" :value="p">{{ p }}</SelectItem></SelectContent></Select>
            </div>
            <Button variant="outline" @click="createLink">Link</Button>
        </div>

        <div class="mb-8 grid gap-4">
            <div v-for="link in links" :key="link.id" class="rounded-lg border border-border p-4">
                <div class="mb-3 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="font-semibold text-foreground">{{ link.provider }}</span>
                        <Badge variant="outline">{{ link.sandbox ? 'sandbox' : 'live' }}</Badge>
                        <Badge variant="outline">{{ link.connected ? 'connected' : 'no tokens' }}</Badge>
                        <Badge variant="outline">{{ link.mapped }} mapped</Badge>
                    </div>
                    <Button size="sm" @click="saveMap(link.id)">Save Mapping</Button>
                </div>
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    <div v-for="code in chartCodes" :key="code" class="grid gap-1">
                        <Label class="font-mono text-xs">{{ code }}</Label>
                        <Input :model-value="mapValue(link.id, code)" @update:model-value="(v) => setMapValue(link.id, code, String(v))" placeholder="External ID" />
                    </div>
                </div>
            </div>
            <p v-if="links.length === 0" class="text-sm text-muted-foreground">No providers linked yet.</p>
        </div>

        <h2 class="mb-3 text-lg font-semibold text-foreground">Run Export</h2>
        <div class="mb-8 flex items-end gap-3">
            <div class="grid gap-2"><Label>Provider</Label>
                <Select v-model="exportForm.provider"><SelectTrigger class="w-48"><SelectValue /></SelectTrigger>
                <SelectContent><SelectItem v-for="p in providers.concat(['fake'])" :key="p" :value="p">{{ p }}</SelectItem></SelectContent></Select>
            </div>
            <div class="grid gap-2"><Label>Business date</Label><Input v-model="exportForm.business_date" type="date" /></div>
            <Button @click="runExport">Export</Button>
        </div>

        <h2 class="mb-3 text-lg font-semibold text-foreground">Exports</h2>
        <div class="rounded-lg border border-border">
            <table class="w-full caption-bottom text-sm">
                <thead class="bg-muted/50"><tr>
                    <th class="h-12 px-4 text-left font-medium text-muted-foreground">Date</th>
                    <th class="h-12 px-4 text-left font-medium text-muted-foreground">Provider</th>
                    <th class="h-12 px-4 text-left font-medium text-muted-foreground">Status</th>
                    <th class="h-12 px-4 text-left font-medium text-muted-foreground">External ID</th>
                    <th class="h-12 px-4 text-left font-medium text-muted-foreground">Error</th>
                    <th class="h-12 px-4 text-right font-medium text-muted-foreground">Actions</th>
                </tr></thead>
                <tbody>
                    <tr v-for="e in exports" :key="e.id" class="border-t border-border hover:bg-muted/50">
                        <td class="p-4 text-foreground">{{ e.business_date }}</td>
                        <td class="p-4 text-muted-foreground">{{ e.provider }}</td>
                        <td class="p-4"><Badge variant="outline" :class="statusClass(e.status)">{{ e.status }}</Badge></td>
                        <td class="p-4 font-mono text-xs text-muted-foreground">{{ e.external_id ?? '—' }}</td>
                        <td class="p-4 text-xs text-muted-foreground max-w-64 truncate">{{ e.last_error ?? '—' }}</td>
                        <td class="p-4 text-right"><Button v-if="e.status !== 'delivered'" size="sm" variant="outline" @click="retry(e.id)">Retry</Button></td>
                    </tr>
                    <tr v-if="exports.length === 0"><td colspan="6" class="p-4 text-center text-muted-foreground">No exports yet.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
