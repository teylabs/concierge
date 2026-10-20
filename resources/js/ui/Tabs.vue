<script setup lang="ts">
/**
 * Page tabs: links, so they work without script and keep the URL (the host's
 * `?tab`); the current one is marked with aria-current and each shows the
 * server's count as a badge.
 */
import { shouldIntercept } from '../internal/navigation';
import type { MembersTab } from '../types';

defineProps<{ tabs: MembersTab[]; label: string }>();

const emit = defineEmits<{ navigate: [url: string, event: MouseEvent] }>();
</script>

<template>
    <nav :aria-label="label" class="border-b border-(--concierge-border)">
        <ul class="-mb-px flex gap-6">
            <li v-for="tab in tabs" :key="tab.key">
                <a
                    :href="tab.url"
                    :aria-current="tab.current ? 'page' : undefined"
                    :class="[
                        'inline-flex items-center gap-2 border-b-2 px-1 py-2.5 text-sm font-medium focus-visible:outline-2 focus-visible:outline-(--concierge-focus)',
                        tab.current
                            ? 'border-(--concierge-primary-solid) text-(--concierge-text)'
                            : 'border-transparent text-(--concierge-text-muted) hover:border-(--concierge-border-strong) hover:text-(--concierge-text)',
                    ]"
                    @click="
                        shouldIntercept($event) &&
                        (emit('navigate', tab.url, $event),
                        $event.preventDefault())
                    "
                >
                    {{ tab.label }}
                    <span
                        class="rounded-full bg-(--concierge-surface-muted) px-2 py-0.5 text-xs text-(--concierge-text-muted) ring-1 ring-(--concierge-border) ring-inset"
                        >{{ tab.count }}</span
                    >
                </a>
            </li>
        </ul>
    </nav>
</template>
