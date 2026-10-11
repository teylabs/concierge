<script setup lang="ts">
/**
 * Label, hint and error wiring for one control. The slot receives the ids a
 * control needs for aria-describedby / aria-invalid.
 */
import { computed, useId } from 'vue';

const props = defineProps<{
    label: string;
    error?: string | null;
    hint?: string | null;
    as?: 'div' | 'fieldset';
    labelHidden?: boolean;
}>();

const id = useId();
const hintId = `${id}-hint`;
const errorId = `${id}-error`;

const describedBy = computed(
    () =>
        [props.hint ? hintId : null, props.error ? errorId : null]
            .filter(Boolean)
            .join(' ') || undefined,
);
</script>

<template>
    <fieldset v-if="as === 'fieldset'" class="min-w-0 space-y-2">
        <legend
            :class="
                labelHidden
                    ? 'sr-only'
                    : 'text-sm font-medium text-(--concierge-text)'
            "
        >
            {{ label }}
        </legend>
        <p
            v-if="hint"
            :id="hintId"
            class="text-sm text-(--concierge-text-muted)"
        >
            {{ hint }}
        </p>
        <slot
            :id="id"
            :described-by="describedBy"
            :invalid="!!error || undefined"
        />
        <p
            v-if="error"
            :id="errorId"
            class="text-sm text-(--concierge-danger-solid)"
        >
            {{ error }}
        </p>
    </fieldset>
    <div v-else class="space-y-1.5">
        <label
            :for="id"
            class="block text-sm font-medium text-(--concierge-text)"
        >
            {{ label }}
        </label>
        <slot
            :id="id"
            :described-by="describedBy"
            :invalid="!!error || undefined"
        />
        <p
            v-if="hint"
            :id="hintId"
            class="text-sm text-(--concierge-text-muted)"
        >
            {{ hint }}
        </p>
        <p
            v-if="error"
            :id="errorId"
            class="text-sm text-(--concierge-danger-solid)"
        >
            {{ error }}
        </p>
    </div>
</template>
