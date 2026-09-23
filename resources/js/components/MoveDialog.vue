<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const props = defineProps<{
    open: boolean;
    reservationId: number;
}>();

const emit = defineEmits<{
    close: [];
}>();

const form = useForm({
    room_id: undefined as number | undefined,
    reason: '',
});

function submit() {
    form.post(`/reservations/${props.reservationId}/move`, {
        onSuccess: () => {
            form.reset();
            emit('close');
            router.reload();
        },
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="emit('close')">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Move guest to another room</DialogTitle>
                <DialogDescription
                    >Future nights repoint to the new room; past nights and all
                    folio charges stay put. The door key is reissued
                    automatically.</DialogDescription
                >
            </DialogHeader>
            <form @submit.prevent="submit" class="grid gap-3">
                <div class="grid gap-2">
                    <Label>Target room id</Label>
                    <Input
                        v-model.number="form.room_id"
                        type="number"
                        min="1"
                        required
                    />
                </div>
                <div class="grid gap-2">
                    <Label>Reason</Label>
                    <Input
                        v-model="form.reason"
                        placeholder="Leaking AC in 101"
                    />
                </div>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="emit('close')"
                        >Cancel</Button
                    >
                    <Button type="submit">Move guest</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
