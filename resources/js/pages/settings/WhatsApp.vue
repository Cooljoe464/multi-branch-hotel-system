<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Check, Copy, MessageSquare } from '@lucide/vue';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'WhatsApp Cloud API', href: '/settings/whatsapp' },
        ],
    },
});

const props = defineProps<{
    branch: { id: number; name: string };
    whatsapp: {
        enabled: boolean;
        phone_number_id: string;
        waba_id: string;
        verify_token: string;
        has_access_token: boolean;
        has_app_secret: boolean;
    };
    webhook_url: string;
}>();

const enabled = ref(props.whatsapp.enabled);
const phoneNumberId = ref(props.whatsapp.phone_number_id);
const wabaId = ref(props.whatsapp.waba_id);
const verifyToken = ref(props.whatsapp.verify_token);
const accessToken = ref('');
const appSecret = ref('');
const clearAccessToken = ref(false);
const clearAppSecret = ref(false);
const saving = ref(false);
const copied = ref(false);

const save = () => {
    saving.value = true;

    router.put(
        '/settings/whatsapp',
        {
            enabled: enabled.value,
            phone_number_id: phoneNumberId.value,
            waba_id: wabaId.value,
            verify_token: verifyToken.value,
            access_token: accessToken.value,
            app_secret: appSecret.value,
            clear_access_token: clearAccessToken.value,
            clear_app_secret: clearAppSecret.value,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                accessToken.value = '';
                appSecret.value = '';
                clearAccessToken.value = false;
                clearAppSecret.value = false;
            },
            onFinish: () => {
                saving.value = false;
            },
        },
    );
};

const copyWebhook = async () => {
    await navigator.clipboard.writeText(props.webhook_url);
    copied.value = true;
    setTimeout(() => (copied.value = false), 1500);
};
</script>

<template>
    <Head title="WhatsApp Cloud API" />

    <div class="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-foreground text-2xl font-bold">
                    WhatsApp Cloud API
                </h1>
                <p class="text-muted-foreground text-sm">
                    Direct Meta connection for {{ branch.name }}.
                </p>
            </div>

            <Badge
                variant="outline"
                :class="
                    whatsapp.has_access_token && whatsapp.phone_number_id
                        ? 'border-green-300 text-green-700 dark:text-green-300'
                        : 'border-red-300 text-red-700 dark:text-red-300'
                "
            >
                <MessageSquare class="mr-1 size-3" />
                {{
                    whatsapp.has_access_token && whatsapp.phone_number_id
                        ? 'Configured'
                        : 'Not configured'
                }}
            </Badge>
        </div>

        <div class="border-border rounded-lg border p-4">
            <h2 class="text-foreground mb-2 text-lg font-semibold">
                Webhook
            </h2>
            <p class="text-muted-foreground mb-2 text-sm">
                In Meta → WhatsApp → Configuration, set this callback URL and the verify token below, then subscribe to the <code>messages</code> field.
            </p>
            <div class="flex flex-wrap items-center gap-2">
                <code class="bg-muted text-foreground rounded px-3 py-2 text-sm">
                    {{ webhook_url }}
                </code>
                <Button size="sm" variant="outline" @click="copyWebhook">
                    <component :is="copied ? Check : Copy" class="mr-1 size-4" />
                    {{ copied ? 'Copied' : 'Copy' }}
                </Button>
            </div>
        </div>

        <div class="border-border grid gap-4 rounded-lg border p-4 md:grid-cols-2">
            <div class="md:col-span-2">
                <label class="flex items-center gap-2 text-sm font-medium">
                    <input v-model="enabled" type="checkbox" />
                    AI concierge enabled (kill switch)
                </label>
                <p class="text-muted-foreground mt-1 text-xs">
                    When off, inbound messages still appear in the inbox but the AI never replies.
                </p>
            </div>

            <div>
                <Label for="phone-number-id">Phone number ID</Label>
                <Input
                    id="phone-number-id"
                    v-model="phoneNumberId"
                    placeholder="123456789012345"
                />
            </div>

            <div>
                <Label for="waba-id">WhatsApp Business Account ID</Label>
                <Input id="waba-id" v-model="wabaId" placeholder="098765432109876" />
            </div>

            <div>
                <Label for="verify-token">Webhook verify token</Label>
                <Input
                    id="verify-token"
                    v-model="verifyToken"
                    placeholder="Long random string"
                />
            </div>

            <div>
                <Label for="access-token">
                    Permanent access token
                    <span v-if="whatsapp.has_access_token" class="text-muted-foreground">
                        (stored)
                    </span>
                </Label>
                <Input
                    id="access-token"
                    v-model="accessToken"
                    type="password"
                    placeholder="Leave blank to keep the stored token"
                />
                <label
                    v-if="whatsapp.has_access_token"
                    class="text-muted-foreground mt-1 flex items-center gap-2 text-xs"
                >
                    <input v-model="clearAccessToken" type="checkbox" />
                    Clear stored token
                </label>
            </div>

            <div>
                <Label for="app-secret">
                    App secret (webhook signature)
                    <span v-if="whatsapp.has_app_secret" class="text-muted-foreground">
                        (stored)
                    </span>
                </Label>
                <Input
                    id="app-secret"
                    v-model="appSecret"
                    type="password"
                    placeholder="Leave blank to keep the stored secret"
                />
                <label
                    v-if="whatsapp.has_app_secret"
                    class="text-muted-foreground mt-1 flex items-center gap-2 text-xs"
                >
                    <input v-model="clearAppSecret" type="checkbox" />
                    Clear stored secret
                </label>
            </div>
        </div>

        <div class="flex justify-end">
            <Button :disabled="saving" @click="save">
                {{ saving ? 'Saving…' : 'Save settings' }}
            </Button>
        </div>
    </div>
</template>
