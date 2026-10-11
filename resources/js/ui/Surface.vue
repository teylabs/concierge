<script setup lang="ts">
/**
 * Elevated panel: the only element
 * that declares background, ring, shadow and radius for a panel.
 */
withDefaults(
    defineProps<{
        as?: 'div' | 'section' | 'article' | 'form';
        clip?: boolean;
    }>(),
    { as: 'div', clip: true },
);
</script>

<template>
    <component
        :is="as"
        :class="[
            'rounded-md bg-(--concierge-surface) shadow-xs ring-1 ring-(--concierge-border)',
            clip ? 'overflow-hidden' : '',
        ]"
    >
        <slot />
    </component>
</template>
