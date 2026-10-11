<script setup lang="ts">
/**
 * Button with variant × tier × size options. Renders an anchor
 * when href is given so hosts keep native link behaviour (open in new tab);
 * plain clicks are intercepted by the caller through the adapter.
 */
import { computed } from 'vue';
import Spinner from './Spinner.vue';
import {
    ghost,
    outline,
    pale,
    solid,
    type Tier,
    type Variant,
} from './variants';

const props = withDefaults(
    defineProps<{
        variant?: Variant;
        tier?: Tier;
        size?: 'sm' | 'md';
        type?: 'button' | 'submit';
        href?: string | null;
        busy?: boolean;
        disabled?: boolean;
    }>(),
    {
        variant: 'primary',
        tier: 'solid',
        size: 'md',
        type: 'button',
        href: null,
        busy: false,
        disabled: false,
    },
);

const tierClasses = computed(() => {
    const map = { solid, pale, outline, ghost }[props.tier];

    return map[props.variant];
});

const sizeClasses = computed(() =>
    props.size === 'sm' ? 'h-8 px-2.5 text-xs' : 'h-9 px-3.5 text-sm',
);

const inactive = computed(() => props.disabled || props.busy);
</script>

<template>
    <a
        v-if="href && !inactive"
        :href="href"
        :class="[
            'inline-flex shrink-0 items-center justify-center gap-1.5 rounded-md font-medium whitespace-nowrap transition-colors ring-inset focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--concierge-focus)',
            tier === 'outline' ? 'ring-1' : '',
            tierClasses,
            sizeClasses,
        ]"
    >
        <slot />
    </a>
    <button
        v-else
        :type="type"
        :disabled="inactive"
        :aria-busy="busy || undefined"
        :class="[
            'inline-flex shrink-0 items-center justify-center gap-1.5 rounded-md font-medium whitespace-nowrap transition-colors ring-inset focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--concierge-focus) disabled:cursor-not-allowed disabled:opacity-60',
            tier === 'outline' ? 'ring-1' : '',
            tierClasses,
            sizeClasses,
        ]"
    >
        <Spinner v-if="busy" />
        <slot />
    </button>
</template>
