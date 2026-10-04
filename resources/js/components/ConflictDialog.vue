<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

defineProps<{
    open: boolean;
    message: string;
}>();

const emit = defineEmits<{
    reload: [];
    close: [];
}>();
</script>

<template>
    <Dialog :open="open" @update:open="emit('close')">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Someone else changed this record</DialogTitle>
                <DialogDescription
                    >{{ message }} Reload to see the latest values, then
                    re-apply your change.</DialogDescription
                >
            </DialogHeader>
            <DialogFooter>
                <Button variant="outline" @click="emit('close')"
                    >Keep editing</Button
                >
                <Button @click="emit('reload')">Reload latest</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
