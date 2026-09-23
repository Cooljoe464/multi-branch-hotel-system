<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const props = defineProps<{
    reservation: {
        id: number;
        confirmation_number: string;
        guest_name: string;
    };
    survey: {
        nps: number | null;
        answers: Record<string, string> | null;
        sentiment: number | null;
    };
}>();

const form = useForm({
    nps: props.survey.nps ?? (undefined as number | undefined),
    answers: {
        liked: props.survey.answers?.liked ?? '',
        improve: props.survey.answers?.improve ?? '',
    },
});
</script>

<template>
    <Head title="Post-stay survey" />
    <div class="mx-auto max-w-xl space-y-6 p-6">
        <div>
            <h1 class="text-2xl font-semibold">
                How was your stay, {{ reservation.guest_name }}?
            </h1>
            <p class="text-muted-foreground text-sm">
                Booking {{ reservation.confirmation_number }} · resubmits update
                your response.
            </p>
        </div>

        <form
            @submit.prevent="form.post(`/surveys/${reservation.id}`)"
            class="space-y-4 rounded-lg border p-4"
        >
            <div class="grid gap-2">
                <Label>Score 0–10</Label>
                <Input
                    v-model.number="form.nps"
                    type="number"
                    min="0"
                    max="10"
                />
            </div>
            <div class="grid gap-2">
                <Label>What did you enjoy?</Label>
                <Input v-model="form.answers.liked" />
            </div>
            <div class="grid gap-2">
                <Label>What could improve?</Label>
                <Input v-model="form.answers.improve" />
            </div>
            <Button type="submit">Send feedback</Button>
        </form>
    </div>
</template>
