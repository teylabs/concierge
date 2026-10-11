<script setup lang="ts">
/**
 * Remove one user. Only an action with a server-provided link renders; the
 * server still authorises every request. Shared by the users index rows and
 * the user page.
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

const { t } = useConcierge();
const { pending, failure, submit, retry, reset } = useSubmit();

const open = ref(false);

const params = computed(() => ({ name: props.user.user.name }));

function show() {
    reset();
    open.value = true;
}

function close() {
    if (!pending.value) {
        open.value = false;
    }
}

async function confirm() {
    const url = props.user.links.remove;

    if (!url) {
        return;
    }

    const result = failure.value
        ? await retry()
        : await submit({ method: 'delete', url });

    if (result?.ok) {
        open.value = false;
    }
}
</script>

<template>
    <div v-if="user.links.remove" class="flex flex-wrap gap-2">
        <Button :size="size" variant="danger" tier="outline" @click="show">{{
            t('userActions.remove')
        }}</Button>

        <ConfirmDialog
            :open="open"
            :title="t('userActions.remove.title', params)"
            :confirm-label="
                failure ? t('actions.retry') : t('userActions.remove')
            "
            :cancel-label="t('actions.cancel')"
            variant="danger"
            :busy="pending"
            :error="failure"
            @confirm="confirm"
            @close="close"
        >
            <p>{{ t('userActions.remove.body', params) }}</p>
        </ConfirmDialog>
    </div>
</template>
