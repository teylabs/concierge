<script setup lang="ts">
/**
 * Suspend / reinstate / remove / transfer ownership for one member. Only
 * actions with a server-provided link render; the server still authorises
 * every request. Shared by the members index rows and the member page.
 */
import { computed, ref } from 'vue';
import { useConcierge } from '../plugin';
import type { ConciergeMethod } from '../adapter';
import type { MemberRow } from '../types';
import Button from '../ui/Button.vue';
import ConfirmDialog from '../ui/ConfirmDialog.vue';
import { controlClasses } from '../ui/controlClasses';
import type { Variant } from '../ui/variants';
import { useSubmit } from './useSubmit';

type ActionKind = 'suspend' | 'reinstate' | 'remove' | 'transfer';

const props = withDefaults(
    defineProps<{
        member: MemberRow;
        workspaceName: string;
        size?: 'sm' | 'md';
    }>(),
    { size: 'sm' },
);

const { t } = useConcierge();
const { pending, failure, submit, retry, reset } = useSubmit();

const active = ref<ActionKind | null>(null);
const typedName = ref('');

type ActionDefinition = {
    kind: ActionKind;
    url: string | null;
    method: ConciergeMethod;
    label: string;
    variant: Variant;
};

const actions = computed<ActionDefinition[]>(() => {
    const links = props.member.links;

    const all: ActionDefinition[] = [
        {
            kind: 'reinstate',
            url: links.reinstate,
            method: 'post',
            label: t('actions.reinstate'),
            variant: 'success',
        },
        {
            kind: 'transfer',
            url: links.transferOwnership,
            method: 'post',
            label: t('actions.transfer'),
            variant: 'warning',
        },
        {
            kind: 'suspend',
            url: links.suspend,
            method: 'post',
            label: t('actions.suspend'),
            variant: 'warning',
        },
        {
            kind: 'remove',
            url: links.remove,
            method: 'delete',
            label: t('actions.remove'),
            variant: 'danger',
        },
    ];

    return all.filter((action) => action.url !== null);
});

const current = computed(
    () => actions.value.find((action) => action.kind === active.value) ?? null,
);

const params = computed(() => ({
    name: props.member.user.name,
    workspace: props.workspaceName,
}));

const copy = computed(() => {
    switch (active.value) {
        case 'suspend':
            return {
                title: t('actions.suspend.title', params.value),
                body: t('actions.suspend.body', params.value),
            };
        case 'reinstate':
            return {
                title: t('actions.reinstate.title', params.value),
                body: t('actions.reinstate.body', params.value),
            };
        case 'remove':
            return {
                title: t('actions.remove.title', params.value),
                body: t('actions.remove.body', params.value),
            };
        case 'transfer':
            return {
                title: t('actions.transfer.title', params.value),
                body: t('actions.transfer.body', params.value),
            };
        default:
            return { title: '', body: '' };
    }
});

const nameConfirmed = computed(
    () =>
        active.value !== 'transfer' ||
        typedName.value.trim() === props.member.user.name.trim(),
);

function open(kind: ActionKind) {
    reset();
    typedName.value = '';
    active.value = kind;
}

function close() {
    if (!pending.value) {
        active.value = null;
    }
}

async function confirm() {
    const action = current.value;

    if (!action?.url || !nameConfirmed.value) {
        return;
    }

    const result = failure.value
        ? await retry()
        : await submit({ method: action.method, url: action.url });

    if (result?.ok) {
        active.value = null;
    }
}
</script>

<template>
    <div v-if="actions.length" class="flex flex-wrap gap-2">
        <Button
            v-for="action in actions"
            :key="action.kind"
            :size="size"
            :variant="action.variant"
            tier="outline"
            @click="open(action.kind)"
            >{{ action.label }}</Button
        >

        <ConfirmDialog
            :open="current !== null"
            :title="copy.title"
            :confirm-label="
                failure ? t('actions.retry') : (current?.label ?? '')
            "
            :cancel-label="t('actions.cancel')"
            :variant="current?.variant ?? 'danger'"
            :busy="pending"
            :error="failure"
            :confirm-disabled="!nameConfirmed"
            @confirm="confirm"
            @close="close"
        >
            <p>{{ copy.body }}</p>
            <label v-if="active === 'transfer'" class="block space-y-1.5">
                <span class="block font-medium text-(--concierge-text)">{{
                    t('actions.transfer.typeName', { name: member.user.name })
                }}</span>
                <input
                    v-model="typedName"
                    type="text"
                    autocomplete="off"
                    :class="controlClasses"
                />
            </label>
        </ConfirmDialog>
    </div>
</template>
