<script setup lang="ts">
defineProps<{ title: string; description?: string | null }>();
</script>

<template>
    <header class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0 space-y-1">
            <slot name="breadcrumbs" />
            <div class="flex items-center gap-3">
                <slot name="leading" />
                <div class="min-w-0">
                    <h1
                        class="text-2xl font-semibold tracking-tight text-(--concierge-text)"
                    >
                        {{ title }}
                    </h1>
                    <p
                        v-if="description"
                        class="text-sm text-(--concierge-text-muted)"
                    >
                        {{ description }}
                    </p>
                </div>
            </div>
        </div>
        <div v-if="$slots.actions" class="flex shrink-0 flex-wrap gap-2">
            <slot name="actions" />
        </div>
    </header>
</template>
