<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { ArrowLeft } from '@lucide/vue';

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
    <Head title="Guest Portal" />

    <div class="flex min-h-screen items-center justify-center bg-background px-4">
        <div class="w-full max-w-md">
            <div class="mb-4">
                <Button variant="ghost" size="sm" @click="goBack()" class="gap-1 text-muted-foreground hover:text-foreground">
                    <ArrowLeft class="size-4" /> Back
                </Button>
            </div>
            <div class="rounded-lg border border-border bg-card p-8 shadow-sm">
                <div class="mb-8 text-center">
                    <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-primary/10">
                        <span class="text-3xl">🏨</span>
                    </div>
                    <h1 class="text-2xl font-bold text-foreground">Guest Portal</h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Enter your confirmation number and email to access your folio
                    </p>
                </div>

                <form @submit.prevent="submit" class="space-y-4">
                    <div>
                        <label for="confirmation" class="mb-1 block text-sm font-medium text-foreground">
                            Confirmation Number
                        </label>
                        <input
                            id="confirmation"
                            v-model="form.confirmation"
                            type="text"
                            placeholder="e.g. HTL-20240917-A1B2"
                            required
                            class="w-full rounded-lg border border-border bg-background px-4 py-3 text-foreground placeholder:text-muted-foreground focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        />
                        <p v-if="form.errors.confirmation" class="mt-1 text-sm text-destructive">
                            {{ form.errors.confirmation }}
                        </p>
                    </div>

                    <div>
                        <label for="email" class="mb-1 block text-sm font-medium text-foreground">
                            Email Address
                        </label>
                        <input
                            id="email"
                            v-model="form.email"
                            type="email"
                            placeholder="you@example.com"
                            required
                            class="w-full rounded-lg border border-border bg-background px-4 py-3 text-foreground placeholder:text-muted-foreground focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        />
                        <p v-if="form.errors.email" class="mt-1 text-sm text-destructive">
                            {{ form.errors.email }}
                        </p>
                    </div>

                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="w-full rounded-lg bg-primary py-3 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
                    >
                        {{ form.processing ? 'Looking up...' : 'View My Folio' }}
                    </button>
                </form>

                <div class="mt-6 border-t border-border pt-4 text-center">
                    <p class="text-xs text-muted-foreground">
                        Check your booking confirmation email for these details.
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>
