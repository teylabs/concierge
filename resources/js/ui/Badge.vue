<script setup lang="ts">
/** Adapted from cookbook UI/Badge/StatusBadge.vue: pale tier plus ring. */
import { computed } from 'vue';
import { badgeClasses } from './variants';

const props = defineProps<{ label: string; variant?: string | null }>();

const classes = computed(() => badgeClasses(props.variant));
</script>

<template>
    <span
        :class="[
            'inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium whitespace-nowrap ring-1 ring-inset',
            classes,
        ]"
        >{{ label }}</span
    >
</template>
