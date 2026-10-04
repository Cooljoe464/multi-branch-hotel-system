<script setup lang="ts">
import { KeyRound, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { Passkey } from '@/types/auth';

const props = defineProps<{
    passkey: Passkey;
}>();

const emit = defineEmits<{
    remove: [id: number, onError: () => void];
}>();

const isDeleting = ref(false);
const showModal = ref(false);

const handleDelete = () => {
    isDeleting.value = true;
    emit('remove', props.passkey.id, () => {
        isDeleting.value = false;
        showModal.value = false;
    });
};
</script>

<template>
    <div
        class="border-border flex items-center justify-between border-b p-4 last:border-b-0"
    >
        <div class="flex items-center gap-4">
            <div
                class="bg-muted flex h-10 w-10 shrink-0 items-center justify-center rounded-xl"
            >
                <KeyRound class="text-muted-foreground h-5 w-5" />
            </div>
            <div class="space-y-1">
                <div class="flex items-center gap-2.5">
                    <p class="text-foreground font-medium tracking-tight">
                        {{ passkey.name }}
                    </p>
                    <span
                        v-if="passkey.authenticator"
                        class="bg-muted text-muted-foreground ring-border inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-medium tracking-wide uppercase ring-1 ring-inset"
                    >
                        {{ passkey.authenticator }}
                    </span>
                </div>
                <p class="text-muted-foreground text-sm">
                    Added {{ passkey.created_at_diff }}
                    <template v-if="passkey.last_used_at_diff">
                        <span class="text-muted-foreground/50 mx-1">/</span>
                        Last used {{ passkey.last_used_at_diff }}
                    </template>
                </p>
            </div>
        </div>

        <Button variant="outline" size="sm" @click="showModal = true">
            <Trash2 class="text-destructive h-4 w-4" />
            <span class="sr-only">Remove</span>
        </Button>

        <Dialog :open="showModal" @update:open="showModal = $event">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Remove passkey</DialogTitle>
                    <DialogDescription>
                        Are you sure you want to remove the "{{ passkey.name }}"
                        passkey? You will no longer be able to use it to sign
                        in.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="outline" @click="showModal = false">
                        Cancel
                    </Button>
                    <Button
                        variant="destructive"
                        :disabled="isDeleting"
                        @click="handleDelete"
                    >
                        {{ isDeleting ? 'Removing...' : 'Remove passkey' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
