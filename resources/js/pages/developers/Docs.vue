<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head } from '@inertiajs/vue3';

interface OpenApiSpec {
    openapi?: string;
    info?: { title?: string; version?: string };
    paths?: Record<string, Record<string, unknown>>;
}

interface SwaggerUiPreset {
    apis?: unknown;
    standalone?: unknown;
}

interface SwaggerUiBundle {
    init: (options: Record<string, unknown>) => unknown;
    destroyInstance?: () => void;
    presets?: SwaggerUiPreset;
}

interface SwaggerUiWindow {
    SwaggerUIBundle: SwaggerUiBundle;
}

const props = defineProps<{
    spec: OpenApiSpec;
}>();

const container = ref<HTMLElement | null>(null);
let swaggerInstance: SwaggerUiBundle | null = null;

const loadSwaggerUi = (): Promise<SwaggerUiBundle> =>
    new Promise((resolve, reject) => {
        const scope = window as unknown as SwaggerUiWindow;
        const bundle = scope.SwaggerUIBundle;

        if (bundle) {
            resolve(bundle);

            return;
        }

        const existing = document.querySelector('script[data-swaldoc-ui="true"]');

        if (existing) {
            existing.addEventListener('load', () => resolve(bundle));
            existing.addEventListener('error', () => reject(new Error('Swagger UI bundle failed to load.')));

            return;
        }

        const script = document.createElement('script');
        script.src = 'https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui-bundle.js';
        script.dataset.swaldocUi = 'true';
        script.onload = () => resolve(bundle);
        script.onerror = () => reject(new Error('Swagger UI bundle failed to load.'));
        document.head.appendChild(script);
    });

const startSwagger = async (bundle: SwaggerUiBundle): Promise<void> => {
    const element = document.createElement('div');
    element.id = 'swagger-ui';

    container.value?.appendChild(element);

    await bundle.init({
        dom_id: '#swagger-ui',
        spec: props.spec as Record<string, unknown>,
        deepLinking: true,
        persistAuthorization: true,
        layout: 'StandaloneLayout',
        presets: [bundle.presets?.apis, bundle.presets?.standalone],
    });

    swaggerInstance = bundle;
};

const stopSwagger = (): void => {
    swaggerInstance?.destroyInstance?.();
    swaggerInstance = null;

    if (container.value) {
        container.value.innerHTML = '';
    }
};

onMounted(async () => {
    try {
        const bundle = await loadSwaggerUi();

        stopSwagger();
        await startSwagger(bundle);
    } catch (error) {
        console.error(error);
    }
});

onBeforeUnmount(() => {
    stopSwagger();
});

watch(
    () => props.spec,
    async () => {
        try {
            const bundle = await loadSwaggerUi();

            stopSwagger();
            await startSwagger(bundle);
        } catch (error) {
            console.error(error);
        }
    },
    { deep: true },
);
</script>

<template>
    <Head title="API Documentation" />

    <div class="h-full w-full">
        <div ref="container" class="h-full w-full" />
    </div>
</template>
