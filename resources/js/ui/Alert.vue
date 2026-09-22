<script setup lang="ts">
import { computed } from 'vue';
import { pale, type Variant } from './variants';

const props = withDefaults(
    defineProps<{ variant?: Variant; live?: 'polite' | 'assertive' | null }>(),
    { variant: 'info', live: null },
);

const classes = computed(() => pale[props.variant]);
</script>

<template>
    <div
        :role="
            live === 'assertive'
                ? 'alert'
                : live === 'polite'
                  ? 'status'
                  : undefined
        "
        :class="[
            'flex flex-wrap items-start gap-x-3 gap-y-2 rounded-md px-3 py-2.5 text-sm ring-1 ring-inset',
            classes,
        ]"
    >
        <div class="min-w-0 flex-1"><slot /></div>
        <div v-if="$slots.actions" class="flex shrink-0 gap-2">
            <slot name="actions" />
        </div>
    </div>
</template>
