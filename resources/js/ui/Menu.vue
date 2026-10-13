<script setup lang="ts" generic="T extends string">
/**
 * Native popover keeps the menu above clipped panels and handles outside
 * clicks. Its invoker is an implicit CSS anchor; utilities own placement.
 */
import { ref, useId } from 'vue';
import Button from './Button.vue';

withDefaults(
    defineProps<{
        label: string;
        items: { value: T; label: string; danger?: boolean }[];
        size?: 'sm' | 'md';
    }>(),
    { size: 'sm' },
);

const emit = defineEmits<{ select: [value: T] }>();
const root = ref<HTMLDivElement | null>(null);
const menu = ref<HTMLDivElement | null>(null);
const open = ref(false);
const id = useId();
let first = 0;
let tabbing = false;

function menuItems() {
    return Array.from(
        menu.value?.querySelectorAll<HTMLButtonElement>('[role="menuitem"]') ??
            [],
    );
}

function beforeToggle(event: ToggleEvent) {
    open.value = event.newState === 'open';

    if (!open.value && menu.value?.contains(document.activeElement)) {
        root.value?.querySelector('button')?.focus({ preventScroll: true });
    }
}

function onToggle() {
    if (open.value) {
        const buttons = menuItems();
        buttons.at(first)?.focus();
        first = 0;
    } else if (!tabbing && document.activeElement === document.body) {
        // Light dismissal on non-focusable content must not lose focus.
        root.value?.querySelector('button')?.focus({ preventScroll: true });
    }
    tabbing = false;
}

function onTriggerKeydown(event: KeyboardEvent) {
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        first = event.key === 'ArrowUp' ? -1 : 0;
        (event.currentTarget as HTMLButtonElement).click();
    }
}

function onMenuKeydown(event: KeyboardEvent) {
    if (event.key === 'Escape' || event.key === 'Tab') {
        if (event.key === 'Escape') {
            event.preventDefault();
        }
        tabbing = event.key === 'Tab';
        menu.value?.hidePopover();
        return;
    }

    const buttons = menuItems();
    const index = buttons.indexOf(document.activeElement as HTMLButtonElement);
    let next: number;

    switch (event.key) {
        case 'ArrowDown':
            next = (index + 1) % buttons.length;
            break;
        case 'ArrowUp':
            next = (index - 1 + buttons.length) % buttons.length;
            break;
        case 'Home':
            next = 0;
            break;
        case 'End':
            next = buttons.length - 1;
            break;
        default:
            return;
    }

    event.preventDefault();
    buttons[next]?.focus();
}

function select(value: T) {
    // Restore focus before opening a dialog so its return target survives.
    menu.value?.hidePopover();
    emit('select', value);
}
</script>

<template>
    <div ref="root" class="inline-flex shrink-0">
        <Button
            :id="`${id}-trigger`"
            :size="size"
            variant="muted"
            tier="outline"
            :popovertarget="id"
            aria-haspopup="menu"
            :aria-expanded="open"
            :aria-controls="id"
            @keydown="onTriggerKeydown"
        >
            {{ label }}
            <svg
                class="size-3.5"
                viewBox="0 0 16 16"
                fill="none"
                stroke="currentColor"
                stroke-width="1.5"
                aria-hidden="true"
            >
                <path d="m4 6 4 4 4-4" />
            </svg>
        </Button>
        <div
            :id="id"
            ref="menu"
            popover="auto"
            role="menu"
            :aria-labelledby="`${id}-trigger`"
            class="fixed inset-auto m-0 mt-1 w-max max-w-[calc(100vw-2rem)] rounded-md border-0 bg-(--concierge-surface) p-1 text-sm text-(--concierge-text) shadow-lg ring-1 ring-(--concierge-border) [position-area:bottom_span-left] [position-try-fallbacks:flip-block,flip-inline]"
            @beforetoggle="beforeToggle"
            @toggle="onToggle"
            @keydown="onMenuKeydown"
        >
            <button
                v-for="item in items"
                :key="item.value"
                type="button"
                role="menuitem"
                tabindex="-1"
                :class="[
                    'flex min-h-9 w-full items-center rounded-sm px-3 py-2 text-left whitespace-nowrap focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-(--concierge-focus)',
                    item.danger
                        ? 'text-(--concierge-danger-solid) hover:bg-(--concierge-danger-pale) focus:bg-(--concierge-danger-pale)'
                        : 'hover:bg-(--concierge-surface-muted) focus:bg-(--concierge-surface-muted)',
                ]"
                @click="select(item.value)"
            >
                {{ item.label }}
            </button>
        </div>
    </div>
</template>
