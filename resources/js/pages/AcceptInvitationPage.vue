<script setup lang="ts">
/**
 * Invitation landing page with a centred card. It never creates accounts:
 * sign-in and registration are host (Fortify) links, and acceptance is an
 * explicit POST.
 * Only the "ready" state can accept, and only when the server sends a link.
 */
import { computed } from 'vue';
import { shouldIntercept } from '../internal/navigation';
import { useSubmit } from '../internal/useSubmit';
import { useConcierge } from '../plugin';
import type { AcceptInvitationPageProps } from '../types';
import Alert from '../ui/Alert.vue';
import Badge from '../ui/Badge.vue';
import Button from '../ui/Button.vue';
import Surface from '../ui/Surface.vue';
import type { Variant } from '../ui/variants';
import type { ConciergeLabelKey } from '../labels';

const props = defineProps<AcceptInvitationPageProps>();

const { adapter, t } = useConcierge();
const accept = useSubmit();
const logout = useSubmit();

type State = AcceptInvitationPageProps['state'];

const tone: Record<State, Variant> = {
    ready: 'primary',
    sign_in_required: 'info',
    register_required: 'info',
    wrong_account: 'warning',
    unverified: 'warning',
    expired: 'danger',
    revoked: 'danger',
    invalid: 'danger',
    accepted: 'success',
    already_member: 'success',
    suspended: 'danger',
    authority_lapsed: 'danger',
    workspace_unavailable: 'warning',
    grants_changed: 'warning',
};

const knownState = computed<State>(() =>
    props.state in tone ? props.state : 'invalid',
);

const params = computed(() => ({
    workspace: props.workspace?.name ?? '',
    email: props.email ?? '',
}));

const title = computed(() =>
    props.workspace
        ? t('accept.title', params.value)
        : t('accept.titleUnknown'),
);

const message = computed(() =>
    t(`accept.${knownState.value}` as ConciergeLabelKey, params.value),
);

const links = computed(() => props.metadata.links);

const acceptUrl = computed(() =>
    knownState.value === 'ready' ? links.value.accept : null,
);

const showRoles = computed(
    () =>
        props.roles.length > 0 &&
        ['ready', 'sign_in_required', 'register_required'].includes(
            knownState.value,
        ),
);

type Action =
    | { key: 'accept' | 'logout'; kind: 'submit'; label: string }
    | {
          key: 'login' | 'register' | 'continue';
          kind: 'visit';
          url: string;
          label: string;
          primary: boolean;
      };

/**
 * One ordered action list so the primary action always comes first in both
 * visual and tab order. Sign out is always last, and secondary unless it
 * is the only way forward (wrong_account).
 */
const actions = computed<Action[]>(() => {
    const list: Action[] = [];
    const registerFirst = knownState.value === 'register_required';
    let primaryTaken = false;

    if (acceptUrl.value) {
        list.push({
            key: 'accept',
            kind: 'submit',
            label: accept.failure.value
                ? t('actions.retry')
                : t('accept.accept'),
        });
        primaryTaken = true;
    }

    const account: Action[] = [];
    const push = (
        key: 'login' | 'register' | 'continue',
        url: string | null,
        label: string,
    ) => {
        if (url) {
            account.push({
                key,
                kind: 'visit',
                url,
                label,
                primary: !primaryTaken && account.length === 0,
            });
        }
    };

    if (registerFirst) {
        push('register', links.value.register, t('accept.register'));
        push('login', links.value.login, t('accept.signIn'));
    } else {
        push('login', links.value.login, t('accept.signIn'));
        push('register', links.value.register, t('accept.register'));
    }
    push('continue', links.value.continue, t('accept.continue'));
    list.push(...account);

    if (links.value.logout) {
        list.push({
            key: 'logout',
            kind: 'submit',
            label: t('accept.signOut'),
        });
    }

    return list;
});

function visit(url: string, event: MouseEvent) {
    if (shouldIntercept(event)) {
        event.preventDefault();
        void adapter.visit(url);
    }
}

function acceptInvitation() {
    if (acceptUrl.value) {
        void accept.submit({ method: 'post', url: acceptUrl.value });
    }
}

function signOut() {
    if (links.value.logout) {
        void logout.submit({ method: 'post', url: links.value.logout });
    }
}
</script>

<template>
    <div
        class="concierge flex min-h-full items-start justify-center px-4 py-10 font-(family-name:--concierge-font) sm:items-center"
    >
        <div class="w-full max-w-md space-y-4">
            <slot name="header" />
            <Surface as="article" aria-labelledby="concierge-accept-title">
                <div class="space-y-5 p-6">
                    <div class="space-y-2">
                        <h1
                            id="concierge-accept-title"
                            class="text-xl font-semibold tracking-tight text-(--concierge-text)"
                        >
                            {{ title }}
                        </h1>
                        <p
                            v-if="email && knownState !== 'invalid'"
                            class="text-sm text-(--concierge-text-muted)"
                        >
                            {{ t('accept.invitedAs', params) }}
                        </p>
                    </div>

                    <Alert
                        :variant="tone[knownState]"
                        live="polite"
                        :data-state="knownState"
                    >
                        {{ message }}
                    </Alert>

                    <div v-if="showRoles" class="space-y-2">
                        <p class="text-sm text-(--concierge-text-muted)">
                            {{ t('accept.roles') }}
                        </p>
                        <div class="flex flex-wrap gap-1.5">
                            <Badge
                                v-for="role in roles"
                                :key="role.value"
                                :label="role.label"
                                :variant="role.variant"
                            />
                        </div>
                    </div>

                    <Alert
                        v-if="accept.failure.value"
                        variant="danger"
                        live="assertive"
                    >
                        {{ accept.failure.value }}
                    </Alert>
                    <Alert
                        v-if="logout.failure.value"
                        variant="danger"
                        live="assertive"
                    >
                        {{ logout.failure.value }}
                    </Alert>

                    <div
                        class="flex flex-col gap-2"
                        data-testid="accept-actions"
                    >
                        <template v-for="action in actions" :key="action.key">
                            <Button
                                v-if="action.kind === 'visit'"
                                :variant="action.primary ? 'primary' : 'muted'"
                                :tier="action.primary ? 'solid' : 'outline'"
                                :href="action.url"
                                @click="visit(action.url, $event)"
                                >{{ action.label }}</Button
                            >
                            <Button
                                v-else-if="action.key === 'accept'"
                                :busy="accept.pending.value"
                                @click="acceptInvitation"
                                >{{ action.label }}</Button
                            >
                            <Button
                                v-else
                                :variant="
                                    actions.length === 1 ? 'primary' : 'muted'
                                "
                                :tier="
                                    actions.length === 1 ? 'solid' : 'outline'
                                "
                                :busy="logout.pending.value"
                                @click="signOut"
                                >{{ action.label }}</Button
                            >
                        </template>
                    </div>
                </div>
            </Surface>
            <slot name="aside" />
        </div>
    </div>
</template>
