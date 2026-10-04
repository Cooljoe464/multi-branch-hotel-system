<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { Eye, EyeOff, LockKeyhole, RefreshCw } from '@lucide/vue';
import { nextTick, onMounted, ref, useTemplateRef } from 'vue';
import { Button } from '@/components/ui/button';
import AlertError from '@/components/AlertError.vue';
import { useTwoFactorAuth } from '@/composables/useTwoFactorAuth';
import { regenerateRecoveryCodes } from '@/routes/two-factor';

const { recoveryCodesList, fetchRecoveryCodes, errors } = useTwoFactorAuth();
const isRecoveryCodesVisible = ref<boolean>(false);
const recoveryCodeSectionRef = useTemplateRef('recoveryCodeSectionRef');

const toggleRecoveryCodesVisibility = async () => {
    if (!isRecoveryCodesVisible.value && !recoveryCodesList.value.length) {
        await fetchRecoveryCodes();
    }

    isRecoveryCodesVisible.value = !isRecoveryCodesVisible.value;

    if (isRecoveryCodesVisible.value) {
        await nextTick();
        recoveryCodeSectionRef.value?.scrollIntoView({ behavior: 'smooth' });
    }
};

onMounted(async () => {
    if (!recoveryCodesList.value.length) {
        await fetchRecoveryCodes();
    }
});
</script>

<template>
    <div class="border-border bg-card w-full rounded-lg border p-6 shadow-sm">
        <div class="mb-4">
            <h3 class="text-foreground flex gap-2 text-lg font-semibold">
                <LockKeyhole class="h-5 w-5" /> 2FA recovery codes
            </h3>
            <p class="text-muted-foreground text-sm">
                Recovery codes let you regain access if you lose your 2FA
                device. Store them in a secure password manager.
            </p>
        </div>
        <div>
            <div
                class="flex flex-col gap-3 select-none sm:flex-row sm:items-center sm:justify-between"
            >
                <Button
                    variant="outline"
                    @click="toggleRecoveryCodesVisibility"
                    class="w-fit"
                >
                    <component
                        :is="isRecoveryCodesVisible ? EyeOff : Eye"
                        class="mr-2 h-4 w-4"
                    />
                    {{ isRecoveryCodesVisible ? 'Hide' : 'View' }} recovery
                    codes
                </Button>

                <Form
                    v-if="isRecoveryCodesVisible && recoveryCodesList.length"
                    v-bind="regenerateRecoveryCodes.form()"
                    method="post"
                    :options="{ preserveScroll: true }"
                    @success="fetchRecoveryCodes"
                    #default="{ processing }"
                >
                    <Button
                        variant="outline"
                        type="submit"
                        :disabled="processing"
                    >
                        <RefreshCw class="mr-2 h-4 w-4" /> Regenerate codes
                    </Button>
                </Form>
            </div>
            <div
                :class="[
                    'relative overflow-hidden transition-all duration-300',
                    isRecoveryCodesVisible
                        ? 'h-auto opacity-100'
                        : 'h-0 opacity-0',
                ]"
            >
                <div v-if="errors?.length" class="mt-6">
                    <AlertError :errors="errors" />
                </div>
                <div v-else class="mt-3 space-y-3">
                    <div
                        ref="recoveryCodeSectionRef"
                        class="bg-muted grid gap-1 rounded-lg p-4 font-mono text-sm"
                    >
                        <div v-if="!recoveryCodesList.length" class="space-y-2">
                            <div
                                v-for="n in 8"
                                :key="n"
                                class="bg-muted-foreground/20 h-4 animate-pulse rounded"
                            ></div>
                        </div>
                        <div
                            v-else
                            v-for="(code, index) in recoveryCodesList"
                            :key="index"
                        >
                            {{ code }}
                        </div>
                    </div>
                    <p class="text-muted-foreground text-xs select-none">
                        Each recovery code can be used once to access your
                        account and will be removed after use. If you need more,
                        click
                        <span class="font-bold">Regenerate codes</span> above.
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>
