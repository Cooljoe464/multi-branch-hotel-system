<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { ArrowLeft } from '@lucide/vue';
import LocaleSwitcher from '@/components/LocaleSwitcher.vue';
import { t, initLocale } from '@/lib/locale';

const props = defineProps<{
    defaultLocale?: string;
}>();

initLocale(props.defaultLocale);

const form = useForm({
    confirmation: '',
    email: '',
});

const submit = () => {
    form.post('/guest/lookup');
};

const goBack = () => {
    window.history.back();
};
</script>

<template>
    <Head :title="t('portal.title')" />

    <div
        class="bg-background flex min-h-screen items-center justify-center px-4"
    >
        <div class="w-full max-w-md">
            <div class="mb-4 flex items-center justify-between">
                <Button
                    variant="ghost"
                    size="sm"
                    @click="goBack()"
                    class="text-muted-foreground hover:text-foreground gap-1"
                >
                    <ArrowLeft class="size-4" /> {{ t('common.back') }}
                </Button>
                <LocaleSwitcher />
            </div>
            <div class="border-border bg-card rounded-lg border p-8 shadow-sm">
                <div class="mb-8 text-center">
                    <div
                        class="bg-primary/10 mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full"
                    >
                        <span class="text-3xl">🏨</span>
                    </div>
                    <h1 class="text-foreground text-2xl font-bold">
                        {{ t('portal.title') }}
                    </h1>
                    <p class="text-muted-foreground mt-1 text-sm">
                        {{ t('portal.lookup_body') }}
                    </p>
                </div>

                <form @submit.prevent="submit" class="space-y-4">
                    <div>
                        <label
                            for="confirmation"
                            class="text-foreground mb-1 block text-sm font-medium"
                        >
                            {{ t('portal.confirmation_number') }}
                        </label>
                        <input
                            id="confirmation"
                            v-model="form.confirmation"
                            type="text"
                            :placeholder="t('portal.confirmation_placeholder')"
                            required
                            class="border-border bg-background text-foreground placeholder:text-muted-foreground focus:border-primary focus:ring-primary w-full rounded-lg border px-4 py-3 focus:ring-1 focus:outline-none"
                        />
                        <p
                            v-if="form.errors.confirmation"
                            class="text-destructive mt-1 text-sm"
                        >
                            {{ form.errors.confirmation }}
                        </p>
                    </div>

                    <div>
                        <label
                            for="email"
                            class="text-foreground mb-1 block text-sm font-medium"
                        >
                            {{ t('portal.email_label') }}
                        </label>
                        <input
                            id="email"
                            v-model="form.email"
                            type="email"
                            placeholder="you@example.com"
                            required
                            class="border-border bg-background text-foreground placeholder:text-muted-foreground focus:border-primary focus:ring-primary w-full rounded-lg border px-4 py-3 focus:ring-1 focus:outline-none"
                        />
                        <p
                            v-if="form.errors.email"
                            class="text-destructive mt-1 text-sm"
                        >
                            {{ form.errors.email }}
                        </p>
                    </div>

                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="bg-primary text-primary-foreground hover:bg-primary/90 w-full rounded-lg py-3 text-sm font-medium disabled:opacity-50"
                    >
                        {{
                            form.processing
                                ? t('portal.looking_up')
                                : t('portal.view_my_folio_btn')
                        }}
                    </button>
                </form>

                <div class="border-border mt-6 border-t pt-4 text-center">
                    <p class="text-muted-foreground text-xs">
                        {{ t('portal.lookup_hint') }}
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>
