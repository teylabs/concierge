<script setup lang="ts">
/**
 * Modal confirmation built on the native <dialog> element: showModal() gives
 * focus containment, Escape to close and an inert background without a
 * headless UI dependency.
 *
 * While busy the dialog cannot be dismissed, so a pending request is never
 * orphaned by a second click or an Escape press.
 */
import { nextTick, ref, useId, watch } from 'vue';
import Alert from './Alert.vue';
import Button from './Button.vue';
import type { Variant } from './variants';

const props = withDefaults(
    defineProps<{
        open: boolean;
        title: string;
        confirmLabel: string;
        cancelLabel: string;
        variant?: Variant;
        busy?: boolean;
        error?: string | null;
        confirmDisabled?: boolean;
    }>(),
    { variant: 'danger', busy: false, error: null, confirmDisabled: false },
);

const emit = defineEmits<{ confirm: []; close: [] }>();

const dialog = ref<HTMLDialogElement | null>(null);
const id = useId();

watch(
    () => props.open,
    async (open) => {
        await nextTick();
        const element = dialog.value;

        if (!element) {
            return;
        }

        if (open && !element.open) {
            element.showModal();
        } else if (!open && element.open) {
            element.close();
        }
    },
    { immediate: true },
);

function requestClose(event?: Event) {
    event?.preventDefault();

    if (!props.busy) {
        emit('close');
    }
}

function onBackdropClick(event: MouseEvent) {
    if (event.target === dialog.value) {
        requestClose();
    }
}
</script>

<template>
    <dialog
        ref="dialog"
        class="m-auto w-[calc(100%-2rem)] max-w-md rounded-md bg-(--concierge-surface) p-0 text-(--concierge-text) shadow-xl ring-1 ring-(--concierge-border) backdrop:bg-(--concierge-backdrop)"
        :aria-labelledby="`${id}-title`"
        :aria-describedby="`${id}-body`"
        @cancel="requestClose"
        @click="onBackdropClick"
    >
        <form class="space-y-4 p-5" @submit.prevent="emit('confirm')">
            <h2 :id="`${id}-title`" class="text-base font-semibold">
                {{ title }}
            </h2>
            <div
                :id="`${id}-body`"
                class="space-y-3 text-sm text-(--concierge-text-muted)"
            >
                <slot />
            </div>
            <Alert v-if="error" variant="danger" live="assertive">{{
                error
            }}</Alert>
            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <Button
                    variant="muted"
                    tier="outline"
                    :disabled="busy"
                    @click="requestClose()"
                    >{{ cancelLabel }}</Button
                >
                <Button
                    type="submit"
                    :variant="variant"
                    :busy="busy"
                    :disabled="confirmDisabled"
                    >{{ confirmLabel }}</Button
                >
            </div>
        </form>
    </dialog>
</template>
