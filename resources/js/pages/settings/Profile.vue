<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Loader2 } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/DeleteUser.vue';
import Heading from '@/components/Heading.vue';
import { edit } from '@/routes/profile';
import { send } from '@/routes/verification';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Profile settings',
                href: edit(),
            },
        ],
    },
});

const page = usePage();
const user = computed(() => page.props.auth.user);
const nameInput = ref(user.value.name);
const emailInput = ref(user.value.email);
</script>

<template>
    <Head title="Profile settings" />

    <h1 class="sr-only">Profile settings</h1>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="Profile"
            description="Update your name and email address"
        />

        <Form
            v-bind="ProfileController.update.form()"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input
                    id="name"
                    v-model="nameInput"
                    class="mt-1 block w-full"
                    name="name"
                    required
                    autocomplete="name"
                    placeholder="Full name"
                    :class="errors.name ? 'border-destructive' : ''"
                />
                <p v-if="errors.name" class="text-sm text-destructive">{{ errors.name }}</p>
            </div>

            <div class="grid gap-2">
                <Label for="email">Email address</Label>
                <Input
                    id="email"
                    v-model="emailInput"
                    type="email"
                    class="mt-1 block w-full"
                    name="email"
                    required
                    autocomplete="username"
                    placeholder="Email address"
                    :class="errors.email ? 'border-destructive' : ''"
                />
                <p v-if="errors.email" class="text-sm text-destructive">{{ errors.email }}</p>
            </div>

            <div v-if="page.props.mustVerifyEmail && !user.email_verified_at">
                <p
                    class="text-muted-foreground -mt-4 text-sm"
                >
                    Your email address is unverified.
                    <Link
                        :href="send()"
                        as="button"
                        class="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current!"
                    >
                        Click here to re-send the verification email.
                    </Link>
                </p>

                <div
                    v-if="page.props.status === 'verification-link-sent'"
                    class="mt-2 text-sm font-medium text-green-600 dark:text-green-400"
                >
                    A new verification link has been sent to your email address.
                </div>
            </div>

            <div class="flex items-center gap-4">
                <Button
                    type="submit"
                    :disabled="processing"
                    data-test="update-profile-button"
                >
                    <Loader2 v-if="processing" class="mr-2 size-4 animate-spin" />
                    Save
                </Button>
            </div>
        </Form>
    </div>

    <DeleteUser />
</template>
