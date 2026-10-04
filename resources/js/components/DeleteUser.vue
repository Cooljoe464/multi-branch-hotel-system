<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { ref, useTemplateRef } from 'vue';
import { Loader2 } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';

const passwordInput = useTemplateRef('passwordInput');
const showDeleteModal = ref(false);
</script>

<template>
    <div class="space-y-6">
        <Heading
            variant="small"
            title="Delete account"
            description="Delete your account and all of its resources"
        />
        <div
            class="border-destructive/20 bg-destructive/5 space-y-4 rounded-lg border p-4"
        >
            <div class="text-destructive relative space-y-0.5">
                <p class="font-medium">Warning</p>
                <p class="text-sm">
                    Please proceed with caution, this cannot be undone.
                </p>
            </div>
            <Button
                variant="destructive"
                data-test="delete-user-button"
                @click="showDeleteModal = true"
            >
                Delete account
            </Button>
            <Dialog
                :open="showDeleteModal"
                @update:open="showDeleteModal = $event"
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle
                            >Are you sure you want to delete your
                            account?</DialogTitle
                        >
                    </DialogHeader>
                    <Form
                        v-bind="ProfileController.destroy.form()"
                        reset-on-success
                        @error="() => passwordInput?.focus()"
                        :options="{
                            preserveScroll: true,
                        }"
                        class="space-y-6"
                        v-slot="{ errors, processing, reset, clearErrors }"
                    >
                        <DialogDescription>
                            Once your account is deleted, all of its resources
                            and data will also be permanently deleted. Please
                            enter your password to confirm you would like to
                            permanently delete your account.
                        </DialogDescription>

                        <div class="grid gap-2">
                            <PasswordInput
                                id="password"
                                name="password"
                                label="Password"
                                ref="passwordInput"
                                placeholder="Password"
                            />
                            <InputError :message="errors.password" />
                        </div>

                        <DialogFooter>
                            <Button
                                variant="outline"
                                @click="
                                    () => {
                                        clearErrors();
                                        reset();
                                        showDeleteModal = false;
                                    }
                                "
                            >
                                Cancel
                            </Button>

                            <Button
                                type="submit"
                                variant="destructive"
                                :disabled="processing"
                                data-test="confirm-delete-user-button"
                            >
                                <Loader2
                                    v-if="processing"
                                    class="mr-2 h-4 w-4 animate-spin"
                                />
                                Delete account
                            </Button>
                        </DialogFooter>
                    </Form>
                </DialogContent>
            </Dialog>
        </div>
    </div>
</template>
