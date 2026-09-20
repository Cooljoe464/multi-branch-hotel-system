<script setup lang="ts">
import { Form, Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Loader2, Upload, X, Image } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import Heading from '@/components/Heading.vue';
import { edit } from '@/routes/branding';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Branding settings',
                href: edit(),
            },
        ],
    },
});

const page = usePage();
const branding = computed(() => page.props.branding as { app_name: string; logo_url: string | null });

const appNameInput = ref(branding.value.app_name);
const logoPreview = ref<string | null>(branding.value.logo_url);
const logoFile = ref<File | null>(null);
const processing = ref(false);

function handleFileSelect(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];

    if (! file) return;

    logoFile.value = file;
    logoPreview.value = URL.createObjectURL(file);
}

function removeLogo() {
    processing.value = true;

    router.delete('/settings/branding/logo', {
        preserveScroll: true,
        onSuccess: () => {
            logoFile.value = null;
            logoPreview.value = null;
            processing.value = false;
        },
        onError: () => {
            processing.value = false;
        },
    });
}

function submit() {
    processing.value = true;

    const formData = new FormData();
    formData.append('app_name', appNameInput.value);
    formData.append('_method', 'PUT');

    if (logoFile.value) {
        formData.append('logo', logoFile.value);
    }

    router.post('/settings/branding', formData, {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            processing.value = false;
            logoFile.value = null;
        },
        onError: () => {
            processing.value = false;
        },
    });
}
</script>

<template>
    <Head title="Branding settings" />

    <h1 class="sr-only">Branding settings</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Branding"
            description="Customize your company logo and application name"
        />

        <form @submit.prevent="submit" class="space-y-8">
            <div class="grid gap-2">
                <Label for="app_name">Application Name</Label>
                <Input
                    id="app_name"
                    v-model="appNameInput"
                    class="mt-1 block w-full max-w-md"
                    required
                    placeholder="Enter application name"
                />
                <p class="text-muted-foreground text-sm">
                    This name appears in the sidebar, header, and login pages.
                </p>
            </div>

            <div class="grid gap-2">
                <Label>Company Logo</Label>
                <div class="flex items-start gap-4">
                    <div
                        class="border-muted-foreground/25 flex h-24 w-24 items-center justify-center rounded-lg border-2 border-dashed"
                    >
                        <img
                            v-if="logoPreview"
                            :src="logoPreview"
                            alt="Logo preview"
                            class="h-full w-full rounded-lg object-contain p-1"
                        />
                        <Image v-else class="text-muted-foreground size-8" />
                    </div>

                    <div class="flex flex-col gap-2">
                        <div class="flex gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                @click="($refs.fileInput as HTMLInputElement).click()"
                            >
                                <Upload class="mr-2 size-4" />
                                Upload Logo
                            </Button>
                            <Button
                                v-if="logoPreview"
                                type="button"
                                variant="outline"
                                size="sm"
                                @click="removeLogo"
                                :disabled="processing"
                            >
                                <X class="mr-2 size-4" />
                                Remove
                            </Button>
                        </div>
                        <p class="text-muted-foreground text-sm">
                            SVG, PNG, JPG or WebP. Max 2MB.
                        </p>
                    </div>
                </div>
                <input
                    ref="fileInput"
                    type="file"
                    accept="image/svg+xml,image/png,image/jpeg,image/webp"
                    class="hidden"
                    @change="handleFileSelect"
                />
            </div>

            <div class="flex items-center gap-4">
                <Button type="submit" :disabled="processing">
                    <Loader2 v-if="processing" class="mr-2 size-4 animate-spin" />
                    Save
                </Button>
            </div>
        </form>
    </div>
</template>
