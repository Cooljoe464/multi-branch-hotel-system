<script setup lang="ts">
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { ArrowLeft } from '@lucide/vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

interface Reservation { id: number; confirmation_number: string; guest_name: string; }
interface RegistrationCard { id: number; guest_name: string; id_type: string; id_number: string; signature_image_url: string | null; signed_at: string | null; }

const props = defineProps<{ reservation: Reservation; registrationCard: RegistrationCard; }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Reservations', href: '/reservations' }, { title: 'Registration Card', href: '#' }] } });

const form = ref({
    guest_name: props.registrationCard?.guest_name || props.reservation.guest_name,
    id_type: props.registrationCard?.id_type || 'passport',
    id_number: props.registrationCard?.id_number || '',
    signature_image_url: props.registrationCard?.signature_image_url || '',
});

const signatureCanvas = ref<HTMLCanvasElement | null>(null);
let isDrawing = false;

const startDraw = (e: MouseEvent) => {
    if (!signatureCanvas.value) return;
    isDrawing = true;
    const ctx = signatureCanvas.value.getContext('2d');
    if (!ctx) return;
    ctx.beginPath();
    ctx.moveTo(e.offsetX, e.offsetY);
};
const draw = (e: MouseEvent) => {
    if (!isDrawing || !signatureCanvas.value) return;
    const ctx = signatureCanvas.value.getContext('2d');
    if (!ctx) return;
    ctx.lineTo(e.offsetX, e.offsetY);
    ctx.stroke();
};
const endDraw = () => { isDrawing = false; };
const clearSignature = () => {
    if (!signatureCanvas.value) return;
    const ctx = signatureCanvas.value.getContext('2d');
    if (ctx) ctx.clearRect(0, 0, signatureCanvas.value.width, signatureCanvas.value.height);
    form.value.signature_image_url = '';
};

const submit = () => {
    if (signatureCanvas.value) {
        form.value.signature_image_url = signatureCanvas.value.toDataURL();
    }
    router.post(`/reservations/${props.reservation.id}/registration-card`, form.value);
};

const goBack = () => {
    window.history.back();
};
</script>

<template>
<div class="p-6 max-w-2xl mx-auto">
    <div class="mb-6 flex items-center gap-3">
        <Button variant="ghost" size="sm" @click="goBack()" class="gap-1 text-muted-foreground hover:text-foreground">
            <ArrowLeft class="size-4" /> Back
        </Button>
        <h1 class="text-2xl font-bold text-foreground">Registration Card - {{ reservation.confirmation_number }}</h1>
    </div>
    <form @submit.prevent="submit" class="space-y-4 bg-card rounded-lg border border-border p-4">
        <div class="grid gap-2"><Label>Guest Name</Label><Input v-model="form.guest_name" required /></div>
        <div class="grid grid-cols-2 gap-4">
            <div class="grid gap-2"><Label>ID Type</Label><Select v-model="form.id_type"><SelectTrigger><SelectValue /></SelectTrigger><SelectContent><SelectItem value="passport">Passport</SelectItem><SelectItem value="drivers_license">Driver's License</SelectItem><SelectItem value="national_id">National ID</SelectItem></SelectContent></Select></div>
            <div class="grid gap-2"><Label>ID Number</Label><Input v-model="form.id_number" required /></div>
        </div>
        <div class="grid gap-2">
            <Label>Signature</Label>
            <canvas ref="signatureCanvas" width="400" height="150" class="border border-border rounded cursor-crosshair bg-card" @mousedown="startDraw" @mousemove="draw" @mouseup="endDraw" @mouseleave="endDraw"></canvas>
            <Button variant="outline" size="sm" type="button" @click="clearSignature">Clear Signature</Button>
        </div>
        <Button type="submit" class="w-full">Save Registration Card</Button>
    </form>
</div>
</template>
