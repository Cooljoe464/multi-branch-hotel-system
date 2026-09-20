<script setup lang="ts">
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

interface Outlet { id: number; name: string; code: string; type: string; is_active: boolean; kitchen_stations: any[]; }

const props = defineProps<{ outlets: { data: Outlet[]; total: number; }; filters: { type?: string; }; }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }, { title: 'Outlets', href: '/outlets' }] } });

const showModal = ref(false);
const editing = ref<Outlet | null>(null);
const form = ref({ name: '', code: '', type: 'restaurant' });

const typeBadge: Record<string, string> = { restaurant: 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-300', bar: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300', spa: 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-300', gift_shop: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300', laundry: 'bg-teal-100 text-teal-800 dark:bg-teal-900 dark:text-teal-300' };

const submit = () => {
    if (editing.value) { router.put(`/outlets/${editing.value.id}`, form.value, { onSuccess: () => { showModal.value = false; editing.value = null; } }); }
    else { router.post('/outlets', form.value, { onSuccess: () => { showModal.value = false; form.value = { name: '', code: '', type: 'restaurant' }; } }); }
};
const openEdit = (o: Outlet) => { editing.value = o; form.value = { name: o.name, code: o.code, type: o.type }; showModal.value = true; };
const deleteItem = (o: Outlet) => { if (confirm(`Delete ${o.name}?`)) router.delete(`/outlets/${o.id}`); };
</script>

<template>
<div class="p-6">
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-bold text-foreground">Outlets</h1>
        <Button @click="editing = null; showModal = true">Add Outlet</Button>
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
        <div v-for="outlet in outlets.data" :key="outlet.id" class="bg-card rounded-lg shadow border border-border p-4 hover:shadow-md transition-shadow">
            <div class="flex items-center justify-between mb-2"><span class="font-bold text-foreground">{{ outlet.name }}</span><Badge :class="typeBadge[outlet.type]" variant="outline" class="capitalize">{{ outlet.type.replace('_', ' ') }}</Badge></div>
            <div class="text-sm text-muted-foreground mb-2">{{ outlet.code }}</div>
            <div class="text-xs text-muted-foreground mb-3">{{ outlet.kitchen_stations?.length || 0 }} kitchen stations</div>
            <div class="flex gap-1"><Button variant="outline" size="sm" class="flex-1" @click="openEdit(outlet)">Edit</Button><Button variant="destructive" size="sm" @click="deleteItem(outlet)">×</Button></div>
        </div>
    </div>
    <Dialog :open="showModal" @update:open="showModal = $event">
        <DialogContent class="max-w-lg">
            <DialogHeader><DialogTitle>{{ editing ? 'Edit' : 'Add' }} Outlet</DialogTitle></DialogHeader>
            <form @submit.prevent="submit" class="space-y-4">
                <div class="grid gap-2"><Label>Name</Label><Input v-model="form.name" required /></div>
                <div class="grid gap-2"><Label>Code</Label><Input v-model="form.code" required /></div>
                <div class="grid gap-2"><Label>Type</Label><Select v-model="form.type"><SelectTrigger><SelectValue /></SelectTrigger><SelectContent><SelectItem value="restaurant">Restaurant</SelectItem><SelectItem value="bar">Bar</SelectItem><SelectItem value="spa">Spa</SelectItem><SelectItem value="gift_shop">Gift Shop</SelectItem><SelectItem value="laundry">Laundry</SelectItem></SelectContent></Select></div>
                <DialogFooter><div class="flex justify-end gap-2"><Button variant="outline" type="button" @click="showModal = false">Cancel</Button><Button type="submit">{{ editing ? 'Save' : 'Create' }}</Button></div></DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</div>
</template>
