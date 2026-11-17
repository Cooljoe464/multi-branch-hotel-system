<script setup lang="ts">
import { Form, Head, router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import TextLink from '@/components/TextLink.vue';
import { logout } from '@/routes';
import { send } from '@/routes/verification';
import { purgeOfflineData } from '@/lib/offlineSync';
import { toast } from 'vue-sonner';

defineOptions({
    layout: {
        title: 'Email verification',
        description:
            'Please verify your email address by clicking on the link we just emailed to you.',
    },
});

defineProps<{
    status?: string;
}>();

async function handleLogout(event: MouseEvent): Promise<void> {
    event.preventDefault();
    try {
        await purgeOfflineData('logout');
    } catch {
        toast.error('Could not clear offline data. You were not logged out.');
        return;
    }
    router.post(logout());
}
</script>

<template>
    <Head title="Email verification" />

    <div
        v-if="status === 'verification-link-sent'"
        class="mb-4 text-center text-sm font-medium text-green-600 dark:text-green-400"
    >
        A new verification link has been sent to the email address you provided
        during registration.
    </div>

    <Form
        v-bind="send.form()"
        class="space-y-6 text-center"
        v-slot="{ processing }"
    >
        <Button variant="secondary" class="w-full" :disabled="processing">
            <Spinner v-if="processing" class="mr-2" />
            Resend verification email
        </Button>

        <TextLink
            :href="logout()"
            as="button"
            class="mx-auto block text-sm"
            @click="handleLogout"
        >
            Log out
        </TextLink>
    </Form>
</template>
