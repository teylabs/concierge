<script setup lang="ts">
/**
 * Deactivate / restore / remove one user. Only an action with a server-provided
 * link renders; the server still authorises every request. Shared by the users
 * index rows and the user page.
 */
import { computed, ref } from 'vue';
import { useConcierge } from '../plugin';
import type { UserRow } from '../types';
import Button from '../ui/Button.vue';
import ConfirmDialog from '../ui/ConfirmDialog.vue';
import { useSubmit } from './useSubmit';

const props = withDefaults(
    defineProps<{
        user: UserRow;
        size?: 'sm' | 'md';
    }>(),
    { size: 'sm' },
);

type Kind = 'deactivate' | 'restore' | 'remove';

const { t } = useConcierge();
const { pending, failure, submit, retry, reset } = useSubmit();

const open = ref<Kind | null>(null);
const reason = ref('');

const params = computed(() => ({ name: props.user.user.name }));

const deactivated = computed(() => props.user.status === 'deactivated');

const links = computed(() => props.user.links);

const showDeactivate = computed(
    () => !deactivated.value && links.value.deactivate !== null,
);

const showRestore = computed(
    () => deactivated.value && links.value.restore !== null,
);

const visible = computed(
    () => showDeactivate.value || showRestore.value || links.value.remove,
);

const dialog = computed(() => {
    switch (open.value) {
        case 'deactivate':
            return {
                title: t('userActions.deactivate.title', params.value),
                body: t('userActions.deactivate.body', params.value),
                label: t('userActions.deactivate'),
                variant: 'warning' as const,
            };
        case 'restore':
            return {
                title: t('userActions.restore.title', params.value),
                body: t('userActions.restore.body', params.value),
                label: t('userActions.restore'),
                variant: 'primary' as const,
            };
        default:
            return {
                title: t('userActions.remove.title', params.value),
                body: t('userActions.remove.body', params.value),
                label: t('userActions.remove'),
                variant: 'danger' as const,
            };
    }
});

function show(kind: Kind) {
    reset();
    reason.value = '';
    open.value = kind;
}

function close() {
    if (!pending.value) {
        open.value = null;
    }
}

async function confirm() {
    const kind = open.value;
    const url = kind ? links.value[kind] : null;

    if (!kind || !url) {
        return;
    }

    const trimmed = reason.value.trim();
    const result = failure.value
        ? await retry()
        : await submit(
              kind === 'remove'
                  ? { method: 'delete', url }
                  : {
                        method: 'post',
                        url,
                        ...(kind === 'deactivate' && trimmed !== ''
                            ? { data: { reason: trimmed } }
                            : {}),
                    },
          );

    if (result?.ok) {
        open.value = null;
    }
}
</script>

<template>
    <div v-if="visible" class="flex flex-wrap gap-2">
        <Button
            v-if="showRestore"
            :size="size"
            variant="primary"
            tier="outline"
            @click="show('restore')"
            >{{ t('userActions.restore') }}</Button
        >
        <Button
            v-if="showDeactivate"
            :size="size"
            variant="warning"
            tier="outline"
            @click="show('deactivate')"
            >{{ t('userActions.deactivate') }}</Button
        >
        <Button
            v-if="links.remove"
            :size="size"
            variant="danger"
            tier="outline"
            @click="show('remove')"
            >{{ t('userActions.remove') }}</Button
        >

        <ConfirmDialog
            :open="open !== null"
            :title="dialog.title"
            :confirm-label="failure ? t('actions.retry') : dialog.label"
            :cancel-label="t('actions.cancel')"
            :variant="dialog.variant"
            :busy="pending"
            :error="failure"
            @confirm="confirm"
            @close="close"
        >
            <p>{{ dialog.body }}</p>
            <label v-if="open === 'deactivate'" class="block space-y-1">
                <span
                    class="block text-sm font-medium text-(--concierge-text)"
                    >{{ t('userActions.deactivate.reason') }}</span
                >
                <textarea
                    v-model="reason"
                    name="reason"
                    rows="3"
                    maxlength="500"
                    :disabled="pending"
                    class="block w-full rounded-md bg-(--concierge-surface) px-3 py-2 text-sm text-(--concierge-text) ring-1 ring-(--concierge-border-strong) ring-inset focus:ring-2 focus:ring-(--concierge-focus) focus:outline-none disabled:opacity-60"
                />
            </label>
        </ConfirmDialog>
    </div>
</template>
