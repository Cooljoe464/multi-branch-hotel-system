<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import LocaleSwitcher from '@/components/LocaleSwitcher.vue';
import { initLocale, t } from '@/lib/locale';

const props = defineProps<{
    branch: { id: number; name: string; currency_code?: string };
    room: { id: number; number: string; status: string } | null;
    roomType: { id: number; name: string; code: string } | null;
    reservation: {
        confirmation_number: string;
        status: string;
        check_in_date: string | null;
        check_out_date: string | null;
        guest_name: string;
        guest_email: string;
        adults: number;
        children: number;
        total_amount: number;
    };
    guest: {
        id: number;
        first_name: string;
        last_name: string;
        email: string;
        phone: string | null;
        id_type: string | null;
        id_number: string | null;
    } | null;
}>();

initLocale('en');

const form = useForm({
    eta: '',
    vehicle: '',
    signature: '',
});

const submit = () => {
    form.post(`/guest/checkin/${props.reservation.confirmation_number}/submit`, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('eta');
            form.reset('vehicle');
            form.reset('signature');
        },
    });
};

const goBack = () => {
    window.history.back();
};
</script>

<template>
    <Head :title="t('portal.title')" />

    <div class="bg-background flex min-h-screen items-center justify-center px-4">
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
                    <div class="bg-primary/10 mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full">
                        <span class="text-3xl">🏨</span>
                    </div>
                    <h1 class="text-foreground text-2xl font-bold">
                        {{ t('portal.checkin_title') }}
                    </h1>
                    <p class="text-muted-foreground mt-1 text-sm">
                        {{ props.reservation.check_in_date }} → {{ props.reservation.check_out_date }} · Room
                        {{ props.room?.number ?? '—' }}
                    </p>
                </div>

                <form @submit.prevent="submit" class="space-y-4">
                    <div>
                        <Label for="eta">{{ t('portal.checkin_eta') }}</Label>
                        <Input
                            id="eta"
                            v-model="form.eta"
                            type="date"
                            class="bg-background"
                        />
                    </div>

                    <div>
                        <Label for="vehicle">{{ t('portal.checkin_vehicle') }}</Label>
                        <Input
                            id="vehicle"
                            v-model="form.vehicle"
                            placeholder="ABC-1234"
                            class="bg-background"
                        />
                    </div>

                    <div>
                        <Label for="signature">{{ t('portal.checkin_signature') }}</Label>
                        <textarea
                            id="signature"
                            v-model="form.signature"
                            class="border-input bg-background min-h-20 w-full rounded-md border px-3 py-2 text-sm"
                            placeholder="Optional guest signature"
                        />
                    </div>

                    <Button type="submit" class="w-full" :disabled="form.processing">
                        {{ form.processing ? 'Submitting…' : t('portal.checkin_submit') }}
                    </Button>
                </form>
            </div>
        </div>
    </div>
</template>
