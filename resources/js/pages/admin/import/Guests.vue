<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Upload, Download, FileSpreadsheet, CheckCircle2 } from '@lucide/vue';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Import', href: '/admin/import' },
            { title: 'Guests', href: '/admin/import/guests' },
        ],
    },
});

defineProps<{
    branch: { id: number; name: string };
}>();

const file = ref<File | null>(null);
const isDragging = ref(false);
const isSubmitting = ref(false);

const handleDrop = (e: DragEvent) => {
    isDragging.value = false;
    const droppedFile = e.dataTransfer?.files[0];
    if (droppedFile) {
        file.value = droppedFile;
    }
};

const handleFileSelect = (e: Event) => {
    const input = e.target as HTMLInputElement;
    if (input.files?.[0]) {
        file.value = input.files[0];
    }
};

const submit = () => {
    if (!file.value) return;
    isSubmitting.value = true;
    const formData = new FormData();
    formData.append('file', file.value);
    router.post('/admin/import/guests', formData, {
        onFinish: () => {
            isSubmitting.value = false;
        },
    });
};
</script>

<template>
    <Head title="Import Guests" />
    <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
        <div class="flex items-center justify-between pb-2 border-b border-border">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-foreground">Import Guests</h1>
                <p class="text-sm text-muted-foreground mt-0.5">
                    Bulk import guest profiles and stay history
                </p>
            </div>
            <a
                href="/admin/import/template/guests"
                class="inline-flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground transition-colors"
            >
                <Download class="h-4 w-4" />
                Download Template
            </a>
        </div>

        <Card>
            <CardHeader>
                <CardTitle>Upload CSV or Excel File</CardTitle>
                <CardDescription>
                    File must include columns: first_name, last_name. See template for full format.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <form @submit.prevent="submit" class="space-y-6">
                    <div
                        class="flex flex-col items-center justify-center rounded-lg border-2 border-dashed p-8 transition-colors"
                        :class="isDragging ? 'border-primary bg-primary/5' : 'border-border'"
                        @dragover.prevent="isDragging = true"
                        @dragleave="isDragging = false"
                        @drop.prevent="handleDrop"
                    >
                        <div v-if="file" class="flex items-center gap-3">
                            <FileSpreadsheet class="h-8 w-8 text-green-600 dark:text-green-400" />
                            <div>
                                <p class="text-sm font-medium text-foreground">{{ file.name }}</p>
                                <p class="text-xs text-muted-foreground">{{ (file.size / 1024).toFixed(1) }} KB</p>
                            </div>
                            <Button type="button" variant="ghost" size="sm" @click="file = null">Remove</Button>
                        </div>
                        <div v-else class="text-center">
                            <Upload class="mx-auto h-10 w-10 text-muted-foreground" />
                            <p class="mt-2 text-sm text-muted-foreground">
                                Drag and drop your file here, or
                                <label class="cursor-pointer text-primary hover:underline">
                                    browse
                                    <input type="file" class="hidden" accept=".csv,.xlsx,.xls" @change="handleFileSelect" />
                                </label>
                            </p>
                            <p class="mt-1 text-xs text-muted-foreground">CSV, XLSX, or XLS up to 10MB</p>
                        </div>
                    </div>
                    <div class="flex justify-end gap-3">
                        <Link href="/dashboard" class="inline-flex items-center justify-center rounded-md text-sm font-medium h-9 px-4 border border-border bg-background hover:bg-accent">
                            Cancel
                        </Link>
                        <Button type="submit" :disabled="!file || isSubmitting">
                            <CheckCircle2 v-if="!isSubmitting" class="mr-2 h-4 w-4" />
                            {{ isSubmitting ? 'Importing...' : 'Import Guests' }}
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>
    </div>
</template>
