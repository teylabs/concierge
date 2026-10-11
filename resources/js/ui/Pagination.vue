<script setup lang="ts">
/**
 * Previous/next pager over server-provided URLs. Links are real anchors;
 * the page intercepts plain clicks through the adapter.
 */
import type { Pagination } from '../types';
import Button from './Button.vue';

defineProps<{
    pagination: Pagination;
    label: string;
    summary: string;
    previousLabel: string;
    nextLabel: string;
}>();

const emit = defineEmits<{ navigate: [url: string, event: MouseEvent] }>();
</script>

<template>
    <nav
        :aria-label="label"
        class="flex flex-wrap items-center justify-between gap-3 border-t border-(--concierge-border) px-4 py-3 sm:px-6"
    >
        <p class="text-sm text-(--concierge-text-muted)">{{ summary }}</p>
        <div class="flex gap-2">
            <Button
                size="sm"
                variant="muted"
                tier="outline"
                :href="pagination.previousUrl"
                :disabled="!pagination.previousUrl"
                rel="prev"
                @click="
                    pagination.previousUrl &&
                    emit('navigate', pagination.previousUrl, $event)
                "
                >{{ previousLabel }}</Button
            >
            <Button
                size="sm"
                variant="muted"
                tier="outline"
                :href="pagination.nextUrl"
                :disabled="!pagination.nextUrl"
                rel="next"
                @click="
                    pagination.nextUrl &&
                    emit('navigate', pagination.nextUrl, $event)
                "
                >{{ nextLabel }}</Button
            >
        </div>
    </nav>
</template>
