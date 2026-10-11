<script setup lang="ts">
defineProps<{ title: string }>();
</script>

<template>
    <div class="flex flex-col items-center gap-2 px-6 py-12 text-center">
        <p class="text-sm font-semibold text-(--concierge-text)">{{ title }}</p>
        <div class="max-w-sm text-sm text-(--concierge-text-muted)">
            <slot />
        </div>
        <div v-if="$slots.actions" class="mt-2 flex gap-2">
            <slot name="actions" />
        </div>
    </div>
</template>
