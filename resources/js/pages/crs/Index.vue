<script setup lang="ts">
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import Pagination from '@/components/ui/pagination/Pagination.vue';

interface Branch { id: number; name: string; }
interface Reservation { id: number; confirmation_number: string; guest_name: string; status: string; check_in_date: string; check_out_date: string; branch: { name: string }; room: { number: string } | null; }

const props = defineProps<{ branches: Branch[]; reservations: { data: Reservation[]; current_page: number; last_page: number; total: number; }; }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }, { title: 'CRS', href: '/crs' }] } });

const showModal = ref(false);
const form = ref({ branch_id: '', guest_name: '', guest_email: '', guest_phone: '', room_type_id: '', check_in_date: '', check_out_date: '', adults: '2', children: '0', room_rate: '', special_requests: '' });

const submit = () => { router.post('/crs/reservations', { ...form.value, room_type_id: parseInt(form.value.room_type_id) || 0, adults: parseInt(form.value.adults) || 2, children: parseInt(form.value.children) || 0, room_rate: parseInt(form.value.room_rate) || 0 }, { onSuccess: () => { showModal.value = false; } }); };
const goToPage = (page: number) => router.get('/crs', { page }, { preserveState: true, replace: true });
</script>

<template>
    <Head title="Central Reservations" />
<div class="p-6">
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-bold text-foreground">Central Reservation System</h1>
        <Button @click="showModal = true">New Reservation</Button>
    </div>
    <div class="rounded-lg border border-border">
        <table class="w-full caption-bottom text-sm">
            <thead class="bg-muted/50"><tr>
                <th class="h-12 px-4 text-left font-medium text-muted-foreground">Confirmation</th>
                <th class="h-12 px-4 text-left font-medium text-muted-foreground">Guest</th>
                <th class="h-12 px-4 text-left font-medium text-muted-foreground">Branch</th>
                <th class="h-12 px-4 text-left font-medium text-muted-foreground">Room</th>
                <th class="h-12 px-4 text-left font-medium text-muted-foreground">Status</th>
            </tr></thead>
            <tbody>
                <tr v-for="r in reservations.data" :key="r.id" class="border-t border-border hover:bg-muted/50">
                    <td class="p-4 font-medium text-foreground">{{ r.confirmation_number }}</td>
                    <td class="p-4 text-muted-foreground">{{ r.guest_name }}</td>
                    <td class="p-4 text-muted-foreground">{{ r.branch.name }}</td>
                    <td class="p-4 text-foreground">{{ r.room?.number ?? 'Unassigned' }}</td>
                    <td class="p-4"><Badge variant="outline" class="capitalize">{{ r.status }}</Badge></td>
                </tr>
                <tr v-if="reservations.data.length === 0"><td colspan="5" class="p-4 text-center text-muted-foreground">No reservations.</td></tr>
            </tbody>
        </table>
    </div>
    <div class="mt-4"><Pagination :data="reservations" label="reservations" @page-change="goToPage" /></div>

    <Dialog :open="showModal" @update:open="showModal = $event">
        <DialogContent class="max-w-lg">
            <DialogHeader><DialogTitle>New CRS Reservation</DialogTitle></DialogHeader>
            <form @submit.prevent="submit" class="space-y-4">
                <div class="grid gap-2"><Label>Branch</Label><Select v-model="form.branch_id"><SelectTrigger><SelectValue placeholder="Select" /></SelectTrigger><SelectContent><SelectItem v-for="b in branches" :key="b.id" :value="String(b.id)">{{ b.name }}</SelectItem></SelectContent></Select></div>
                <div class="grid grid-cols-2 gap-4"><div class="grid gap-2"><Label>Guest Name</Label><Input v-model="form.guest_name" required /></div><div class="grid gap-2"><Label>Email</Label><Input v-model="form.guest_email" type="email" required /></div></div>
                <div class="grid gap-2"><Label>Phone</Label><Input v-model="form.guest_phone" /></div>
                <div class="grid grid-cols-2 gap-4"><div class="grid gap-2"><Label>Check In</Label><Input v-model="form.check_in_date" type="date" required /></div><div class="grid gap-2"><Label>Check Out</Label><Input v-model="form.check_out_date" type="date" required /></div></div>
                <div class="grid grid-cols-3 gap-4"><div class="grid gap-2"><Label>Adults</Label><Input v-model="form.adults" type="number" min="1" required /></div><div class="grid gap-2"><Label>Children</Label><Input v-model="form.children" type="number" min="0" /></div><div class="grid gap-2"><Label>Rate (cents)</Label><Input v-model="form.room_rate" type="number" min="0" required /></div></div>
                <div class="grid gap-2"><Label>Special Requests</Label><Input v-model="form.special_requests" /></div>
                <DialogFooter><div class="flex justify-end gap-2"><Button variant="outline" type="button" @click="showModal = false">Cancel</Button><Button type="submit">Create</Button></div></DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</div>
</template>
