<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';

interface Branch { id: number; name: string; code: string; }
interface Candidate {
    id: number;
    first_name: string;
    last_name: string;
    email: string | null;
    total_stays: number;
    dedup_hash: string | null;
}

const props = defineProps<{
    branch: Branch;
    candidates: Candidate[];
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }, { title: 'Merge Duplicates', href: '/guests' }] } });

const groups = computed(() => {
    const map = new Map<string, Candidate[]>();
    for (const c of props.candidates) {
        const key = c.dedup_hash ?? `single-${c.id}`;
        if (!map.has(key)) map.set(key, []);
        map.get(key)!.push(c);
    }
    return [...map.entries()].filter(([, rows]) => rows.length > 1);
});

const showModal = ref(false);
const form = ref({ surviving_guest_id: '', retired_guest_id: '' });

const openMerge = (survivor: number, retired: number) => {
    form.value = { surviving_guest_id: String(survivor), retired_guest_id: String(retired) };
    showModal.value = true;
};

const submit = () => {
    router.post('/guests/merges', {
        surviving_guest_id: parseInt(form.value.surviving_guest_id),
        retired_guest_id: parseInt(form.value.retired_guest_id),
    }, { onSuccess: () => { showModal.value = false; } });
};
</script>

<template>
    <Head title="Merge Duplicates" />
    <div class="p-6">
        <div class="mb-6 flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-foreground">Duplicate Candidates</h1>
                <p class="text-sm text-muted-foreground">Grouped by match hash. Merging retires one profile into the survivor.</p>
            </div>
            <Button variant="outline" @click="showModal = true">Manual Merge</Button>
        </div>

        <div v-if="groups.length === 0" class="rounded-lg border border-border p-8 text-center text-sm text-muted-foreground">
            No duplicate groups right now.
        </div>

        <div v-for="[hash, rows] in groups" :key="hash" class="mb-6 rounded-lg border border-border">
            <table class="w-full caption-bottom text-sm">
                <thead class="bg-muted/50"><tr>
                    <th class="h-12 px-4 text-left font-medium text-muted-foreground">Guest</th>
                    <th class="h-12 px-4 text-left font-medium text-muted-foreground">Email</th>
                    <th class="h-12 px-4 text-left font-medium text-muted-foreground">Stays</th>
                    <th class="h-12 px-4 text-right font-medium text-muted-foreground">Merge Into</th>
                </tr></thead>
                <tbody>
                    <tr v-for="c in rows" :key="c.id" class="border-t border-border hover:bg-muted/50">
                        <td class="p-4 text-foreground">{{ c.first_name }} {{ c.last_name }} <span class="text-xs text-muted-foreground">#{{ c.id }}</span></td>
                        <td class="p-4 text-muted-foreground">{{ c.email ?? '—' }}</td>
                        <td class="p-4 text-muted-foreground">{{ c.total_stays }}</td>
                        <td class="p-4"><div class="flex justify-end gap-2">
                            <Button v-for="other in rows.filter((r) => r.id !== c.id)" :key="other.id" size="sm" variant="outline" @click="openMerge(other.id, c.id)">
                                Into #{{ other.id }}
                            </Button>
                        </div></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Dialog :open="showModal" @update:open="showModal = $event">
            <DialogContent class="max-w-lg">
                <DialogHeader><DialogTitle>Merge Profiles</DialogTitle></DialogHeader>
                <form @submit.prevent="submit" class="space-y-4">
                    <div class="grid gap-2"><Label>Surviving guest ID</Label><Input v-model="form.surviving_guest_id" inputmode="numeric" required /></div>
                    <div class="grid gap-2"><Label>Retired guest ID</Label><Input v-model="form.retired_guest_id" inputmode="numeric" required /></div>
                    <DialogFooter><Button type="submit">Merge</Button></DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
