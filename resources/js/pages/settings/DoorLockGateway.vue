<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { KeyRound } from '@lucide/vue';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Door lock gateway', href: '/settings/door-lock' },
        ],
    },
});

const props = defineProps<{
    branch: { id: number; name: string };
    gateway: {
        provider: string;
        api_base_url: string;
        is_active: boolean;
        manual_mode: boolean;
        lock_id: string;
        client_id: string;
        has_api_key: boolean;
        has_api_secret: boolean;
    } | null;
    providers: { key: string; entitled: boolean }[];
}>();

const form = ref({
    provider: props.gateway?.provider ?? props.providers[0]?.key ?? '',
    api_base_url: props.gateway?.api_base_url ?? '',
    is_active: props.gateway?.is_active ?? true,
    manual_mode: props.gateway?.manual_mode ?? false,
    lock_id: props.gateway?.lock_id ?? '',
    client_id: props.gateway?.client_id ?? '',
    api_key: '',
    api_secret: '',
});
const errors = ref<Record<string, string>>({});
const saving = ref(false);

const save = () => {
    saving.value = true;
    router.put('/settings/door-lock', form.value, {
        preserveScroll: true,
        onSuccess: () => {
            form.value.api_key = '';
            form.value.api_secret = '';
            errors.value = {};
        },
        onError: (e) => {
            errors.value = e;
        },
        onFinish: () => {
            saving.value = false;
        },
    });
};

const remove = () => {
    router.delete('/settings/door-lock', { preserveScroll: true });
};
</script>

<template>
    <Head title="Door lock gateway" />

    <div class="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-foreground text-2xl font-bold">Door lock gateway</h1>
                <p class="text-muted-foreground text-sm">
                    Key-card or mobile-key system for {{ branch.name }}. Credentials are stored encrypted and never shown again.
                </p>
            </div>

            <Badge
                variant="outline"
                :class="
                    gateway?.is_active && gateway.has_api_key
                        ? 'border-green-300 text-green-700 dark:text-green-300'
                        : 'border-red-300 text-red-700 dark:text-red-300'
                "
            >
                <KeyRound class="mr-1 size-3" />
                {{ gateway?.is_active && gateway.has_api_key ? 'Connected' : 'Not configured' }}
            </Badge>
        </div>

        <form
            class="border-border grid gap-4 rounded-lg border p-4 md:grid-cols-2"
            @submit.prevent="save"
        >
            <div>
                <Label for="lock-provider">Provider</Label>
                <select
                    id="lock-provider"
                    v-model="form.provider"
                    class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                >
                    <option v-for="provider in providers" :key="provider.key" :value="provider.key">
                        {{ provider.key }}{{ provider.entitled ? '' : ' (not in plan)' }}
                    </option>
                </select>
                <p v-if="errors.provider" class="text-destructive mt-1 text-xs">{{ errors.provider }}</p>
            </div>

            <div>
                <Label for="lock-url">API base URL</Label>
                <Input id="lock-url" v-model="form.api_base_url" placeholder="https://api.example.com" />
                <p v-if="errors.api_base_url" class="text-destructive mt-1 text-xs">{{ errors.api_base_url }}</p>
            </div>

            <div>
                <Label for="lock-key">
                    API key
                    <span v-if="gateway?.has_api_key" class="text-muted-foreground">(stored)</span>
                </Label>
                <Input
                    id="lock-key"
                    v-model="form.api_key"
                    type="password"
                    autocomplete="off"
                    placeholder="Leave blank to keep the stored key"
                />
            </div>

            <div>
                <Label for="lock-secret">
                    API secret
                    <span v-if="gateway?.has_api_secret" class="text-muted-foreground">(stored)</span>
                </Label>
                <Input
                    id="lock-secret"
                    v-model="form.api_secret"
                    type="password"
                    autocomplete="off"
                    placeholder="Leave blank to keep the stored secret"
                />
            </div>

            <div>
                <Label for="lock-client">Client ID (OAuth providers)</Label>
                <Input id="lock-client" v-model="form.client_id" />
            </div>

            <div>
                <Label for="lock-id">Lock ID (TTLock)</Label>
                <Input id="lock-id" v-model="form.lock_id" />
            </div>

            <label class="flex items-center gap-2 text-sm font-medium">
                <input v-model="form.is_active" type="checkbox" />
                Gateway active
            </label>

            <label class="flex items-center gap-2 text-sm font-medium">
                <input v-model="form.manual_mode" type="checkbox" />
                Manual-record mode (staff encode keys by hand; no vendor call)
            </label>

            <div class="flex justify-between gap-2 md:col-span-2">
                <Button v-if="gateway" type="button" variant="destructive" @click="remove">
                    Remove gateway
                </Button>
                <span v-else />
                <Button type="submit" :disabled="saving">
                    {{ saving ? 'Saving…' : 'Save gateway' }}
                </Button>
            </div>
        </form>
    </div>
</template>
