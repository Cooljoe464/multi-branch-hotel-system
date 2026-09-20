<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { computed, onMounted, onUnmounted, useAttrs } from 'vue';
import { useVModel } from '@vueuse/core';
import { cn } from '@/lib/utils';
import { VueDatePicker } from '@vuepic/vue-datepicker';
import { useAppearance } from '@/composables/useAppearance';

const props = defineProps<{
  defaultValue?: string;
  modelValue?: string;
  class?: HTMLAttributes['class'];
  placeholder?: string;
  minDate?: string;
  maxDate?: string;
  required?: boolean;
  disabled?: boolean;
  ariaLabel?: string;
}>();

const emits = defineEmits<{
  (e: 'update:modelValue', payload: string): void;
  (e: 'change', payload: string): void;
}>();

const attrs = useAttrs();
const { resolvedAppearance } = useAppearance();

const modelValue = useVModel(props, 'modelValue', emits, {
  passive: true,
  defaultValue: props.defaultValue,
});

const internalDate = computed({
  get() {
    return modelValue.value ? new Date(modelValue.value + 'T00:00:00') : null;
  },
  set(val: Date | null) {
    if (val) {
      const y = val.getFullYear();
      const m = String(val.getMonth() + 1).padStart(2, '0');
      const d = String(val.getDate()).padStart(2, '0');
      const dateStr = `${y}-${m}-${d}`;
      modelValue.value = dateStr;
      emits('change', dateStr);
    } else {
      modelValue.value = '';
      emits('change', '');
    }
  },
});

const parsedMinDate = computed(() => (props.minDate ? new Date(props.minDate + 'T00:00:00') : undefined));
const parsedMaxDate = computed(() => (props.maxDate ? new Date(props.maxDate + 'T00:00:00') : undefined));

const isDark = computed(() => resolvedAppearance.value === 'dark');

function setDate(dateStr: string) {
    if (dateStr) {
        modelValue.value = dateStr;
        internalDate.value = new Date(dateStr + 'T00:00:00');
    } else {
        modelValue.value = '';
        internalDate.value = null;
    }
}

function handleSetDateEvent(e: Event) {
    const ce = e as CustomEvent;
    if (ce.detail?.id === String(attrs.id) && ce.detail?.date) {
        setDate(ce.detail.date);
    }
}

onMounted(() => {
    document.addEventListener('setdate', handleSetDateEvent);
});

onUnmounted(() => {
    document.removeEventListener('setdate', handleSetDateEvent);
});

defineExpose({ setDate });
</script>

<template>
  <VueDatePicker
    v-model="internalDate"
    :teleport="true"
    :enable-time-picker="false"
    :format="'yyyy-MM-dd'"
    :preview-format="'yyyy-MM-dd'"
    :min-date="parsedMinDate"
    :max-date="parsedMaxDate"
    :placeholder="placeholder ?? 'Select date'"
    :required="required"
    :disabled="disabled"
    :aria-label="ariaLabel"
    :dark="isDark"
    auto-apply
    v-bind="attrs"
    :class="cn('dp-custom', props.class)"
  />
</template>

<style>
.dp-custom {
  --dp-border-radius: 6px;
  --dp-input-padding: 8px 12px;
  --dp-font-size: 0.875rem;
  --dp-z-index: 10;
}

.dp-custom.dp__theme_light,
.dp-custom.dp--theme-light {
  --dp-background-color: transparent;
  --dp-border-color: var(--color-input);
  --dp-text-color: var(--color-foreground);
  --dp-hover-color: var(--color-accent);
  --dp-hover-text-color: var(--color-accent-foreground);
  --dp-icon-color: var(--color-muted-foreground);
  --dp-menu-border-color: var(--color-border);
  --dp-border-color-hover: var(--color-ring);
  --dp-border-color-focus: var(--color-ring);
  --dp-scroll-bar-background: var(--color-muted);
  --dp-scroll-bar-color: var(--color-muted-foreground);
  --dp-highlight-color: var(--color-primary);
}

.dp-custom.dp__theme_dark {
  --dp-background-color: transparent;
  --dp-text-color: var(--color-foreground);
  --dp-hover-color: var(--color-accent);
  --dp-hover-text-color: var(--color-accent-foreground);
  --dp-icon-color: var(--color-muted-foreground);
  --dp-border-color: var(--color-input);
  --dp-menu-border-color: var(--color-border);
  --dp-border-color-hover: var(--color-ring);
  --dp-border-color-focus: var(--color-ring);
  --dp-primary-color: var(--color-primary);
  --dp-primary-text-color: var(--color-primary-foreground);
  --dp-scroll-bar-background: var(--color-muted);
  --dp-scroll-bar-color: var(--color-muted-foreground);
  --dp-tooltip-color: var(--color-popover);
  --dp-highlight-color: var(--color-primary);
}

.dp-custom .dp__input {
  border: 1px solid var(--color-input);
  background: transparent;
  color: var(--color-foreground);
  font-size: var(--dp-font-size);
  border-radius: var(--dp-border-radius);
  min-height: 36px;
}

.dp-custom .dp__input::placeholder {
  color: var(--color-muted-foreground);
  opacity: 1;
}

.dp-custom .dp__input:focus {
  border-color: var(--color-ring);
  box-shadow: 0 0 0 3px oklch(from var(--color-ring) l c h / 0.2);
}

.dp-custom .dp__input.dp__input_1 {
  padding: var(--dp-input-padding);
}
</style>
