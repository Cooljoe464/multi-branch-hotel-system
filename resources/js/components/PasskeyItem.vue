<script setup lang="ts">
import { KeyRound, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
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
    <div class="flex items-center justify-between border-b border-border p-4 last:border-b-0">
        <div class="flex items-center gap-4">
            <div
                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-muted"
            >
                <KeyRound class="h-5 w-5 text-muted-foreground" />
            </div>
            <div class="space-y-1">
                <div class="flex items-center gap-2.5">
                    <p class="font-medium tracking-tight text-foreground">{{ passkey.name }}</p>
                    <span
                        v-if="passkey.authenticator"
                        class="inline-flex items-center rounded-md bg-muted px-2 py-0.5 text-[11px] font-medium tracking-wide uppercase text-muted-foreground ring-1 ring-border ring-inset"
                    >
                        {{ passkey.authenticator }}
                    </span>
                </div>
                <p class="text-sm text-muted-foreground">
                    Added {{ passkey.created_at_diff }}
                    <template v-if="passkey.last_used_at_diff">
                        <span class="mx-1 text-muted-foreground/50">/</span>
                        Last used {{ passkey.last_used_at_diff }}
                    </template>
                </p>
            </div>
        </div>

        <Button variant="outline" size="sm" @click="showModal = true">
            <Trash2 class="h-4 w-4 text-destructive" />
            <span class="sr-only">Remove</span>
        </Button>

        <Dialog :open="showModal" @update:open="showModal = $event">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Remove passkey</DialogTitle>
                    <DialogDescription>
                        Are you sure you want to remove the "{{ passkey.name }}"
                        passkey? You will no longer be able to use it to sign in.
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
