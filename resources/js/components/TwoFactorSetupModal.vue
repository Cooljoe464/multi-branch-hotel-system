<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { useClipboard } from '@vueuse/core';
import { Check, Copy, Loader2, ScanLine } from '@lucide/vue';
import { computed, nextTick, ref, useTemplateRef, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import AlertError from '@/components/AlertError.vue';
import InputError from '@/components/InputError.vue';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import { useAppearance } from '@/composables/useAppearance';
import { useTwoFactorAuth } from '@/composables/useTwoFactorAuth';
import { confirm } from '@/routes/two-factor';
import type { TwoFactorConfigContent } from '@/types';

type Props = {
    requiresConfirmation: boolean;
    twoFactorEnabled: boolean;
};

const { resolvedAppearance } = useAppearance();

const props = defineProps<Props>();
const isOpen = defineModel<boolean>('isOpen');

const { copy, copied } = useClipboard();
const { qrCodeSvg, manualSetupKey, clearSetupData, fetchSetupData, errors } =
    useTwoFactorAuth();

const showVerificationStep = ref(false);
const code = ref<string>('');

const pinInputContainerRef = useTemplateRef('pinInputContainerRef');

const modalConfig = computed<TwoFactorConfigContent>(() => {
    if (props.twoFactorEnabled) {
        return {
            title: 'Two-factor authentication enabled',
            description:
                'Two-factor authentication is now enabled. Scan the QR code or enter the setup key in your authenticator app.',
            buttonText: 'Close',
        };
    }

    if (showVerificationStep.value) {
        return {
            title: 'Verify authentication code',
            description: 'Enter the 6-digit code from your authenticator app',
            buttonText: 'Continue',
        };
    }

    return {
        title: 'Enable two-factor authentication',
        description:
            'To finish enabling two-factor authentication, scan the QR code or enter the setup key in your authenticator app',
        buttonText: 'Continue',
    };
});

const handleModalNextStep = () => {
    if (props.requiresConfirmation) {
        showVerificationStep.value = true;

        nextTick(() => {
            pinInputContainerRef.value?.querySelector('input')?.focus();
        });

        return;
    }

    clearSetupData();
    isOpen.value = false;
};

const resetModalState = () => {
    if (props.twoFactorEnabled) {
        clearSetupData();
    }

    showVerificationStep.value = false;
    code.value = '';
};

watch(
    () => isOpen.value,
    async (isOpenVal) => {
        if (!isOpenVal) {
            resetModalState();

            return;
        }

        if (!qrCodeSvg.value) {
            await fetchSetupData();
        }
    },
);
</script>

<template>
    <Dialog :open="isOpen" @update:open="isOpen = $event">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <div class="flex items-center gap-3">
                    <div class="bg-muted rounded-full p-2.5">
                        <ScanLine class="text-foreground h-6 w-6" />
                    </div>
                    <div>
                        <DialogTitle>{{ modalConfig.title }}</DialogTitle>
                        <p class="text-muted-foreground text-sm">
                            {{ modalConfig.description }}
                        </p>
                    </div>
                </div>
            </DialogHeader>

            <div
                class="flex flex-col items-center justify-center space-y-5 py-2"
            >
                <template v-if="!showVerificationStep">
                    <AlertError v-if="errors?.length" :errors="errors" />
                    <template v-else>
                        <div
                            class="relative mx-auto flex max-w-md items-center overflow-hidden"
                        >
                            <div
                                class="border-border relative mx-auto aspect-square w-64 overflow-hidden rounded-lg border"
                            >
                                <div
                                    v-if="!qrCodeSvg"
                                    class="bg-background absolute inset-0 z-10 flex aspect-square h-auto w-full animate-pulse items-center justify-center"
                                >
                                    <Loader2
                                        class="text-muted-foreground h-6 w-6 animate-spin"
                                    />
                                </div>
                                <div
                                    v-else
                                    class="border-border relative z-10 overflow-hidden border p-5"
                                >
                                    <div
                                        v-html="qrCodeSvg"
                                        class="flex aspect-square size-full items-center justify-center"
                                        :style="{
                                            filter:
                                                resolvedAppearance === 'dark'
                                                    ? 'invert(1) brightness(1.5)'
                                                    : undefined,
                                        }"
                                    />
                                </div>
                            </div>
                        </div>

                        <div class="flex w-full items-center space-x-5">
                            <Button
                                class="w-full justify-center"
                                @click="handleModalNextStep"
                            >
                                {{ modalConfig.buttonText }}
                            </Button>
                        </div>

                        <div
                            class="relative flex w-full items-center justify-center"
                        >
                            <div
                                class="bg-border absolute inset-0 top-1/2 h-px w-full"
                            />
                            <span
                                class="bg-background text-muted-foreground relative px-2 text-xs"
                                >or, enter the code manually</span
                            >
                        </div>

                        <div
                            class="flex w-full items-center justify-center space-x-2"
                        >
                            <div
                                class="border-border flex w-full items-stretch overflow-hidden rounded-xl border"
                            >
                                <div
                                    v-if="!manualSetupKey"
                                    class="bg-muted flex h-full w-full items-center justify-center p-3"
                                >
                                    <Loader2
                                        class="text-muted-foreground h-4 w-4 animate-spin"
                                    />
                                </div>
                                <template v-else>
                                    <input
                                        type="text"
                                        readonly
                                        :value="manualSetupKey"
                                        class="bg-background text-foreground h-full w-full p-3 text-sm"
                                    />
                                    <button
                                        @click="copy(manualSetupKey || '')"
                                        class="border-border hover:bg-muted relative block h-auto border-l px-3"
                                    >
                                        <Check
                                            v-if="copied"
                                            class="w-4 text-green-500"
                                        />
                                        <Copy
                                            v-else
                                            class="text-muted-foreground w-4"
                                        />
                                    </button>
                                </template>
                            </div>
                        </div>
                    </template>
                </template>

                <template v-else>
                    <Form
                        v-bind="confirm.form()"
                        error-bag="confirmTwoFactorAuthentication"
                        reset-on-error
                        @finish="code = ''"
                        @success="isOpen = false"
                        v-slot="{ errors, processing }"
                    >
                        <input type="hidden" name="code" :value="code" />
                        <div
                            ref="pinInputContainerRef"
                            class="relative w-full space-y-3"
                        >
                            <div
                                class="flex w-full flex-col items-center justify-center space-y-3 py-2"
                            >
                                <InputOTP
                                    id="otp"
                                    v-model="code"
                                    :maxlength="6"
                                    :disabled="processing"
                                    autofocus
                                >
                                    <InputOTPGroup>
                                        <InputOTPSlot
                                            v-for="index in 6"
                                            :key="index"
                                            :index="index - 1"
                                        />
                                    </InputOTPGroup>
                                </InputOTP>
                                <InputError :message="errors?.code" />
                            </div>

                            <div class="flex w-full items-center space-x-5">
                                <Button
                                    type="button"
                                    variant="outline"
                                    class="w-auto flex-1 justify-center"
                                    @click="showVerificationStep = false"
                                    :disabled="processing"
                                >
                                    Back
                                </Button>
                                <Button
                                    type="submit"
                                    class="w-auto flex-1 justify-center"
                                    :disabled="processing || code.length < 6"
                                >
                                    Confirm
                                </Button>
                            </div>
                        </div>
                    </Form>
                </template>
            </div>
        </DialogContent>
    </Dialog>
</template>
