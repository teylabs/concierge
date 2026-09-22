<script setup lang="ts">
/**
 * One pending or expired invitation. "Sent" reflects transport submission
 * (lastSentAt) and never claims delivery.
 */
import { computed, ref } from 'vue';
import { useConcierge } from '../plugin';
import type { InvitationRow } from '../types';
import Alert from '../ui/Alert.vue';
import Badge from '../ui/Badge.vue';
import Button from '../ui/Button.vue';
import ConfirmDialog from '../ui/ConfirmDialog.vue';
import { formatDate, formatRelative, isPast } from './format';
import { useSubmit } from './useSubmit';

const props = defineProps<{ invitation: InvitationRow }>();

const { t, locale } = useConcierge();
const resend = useSubmit();
const revoke = useSubmit();

const confirmingRevoke = ref(false);
const resent = ref(false);

const expired = computed(
    () =>
        props.invitation.status === 'expired' ||
        isPast(props.invitation.expiresAt),
);

const statusLabel = computed(() =>
    expired.value
        ? t('invitations.status.expired')
        : t('invitations.status.pending'),
);

const expiry = computed(() =>
    t(expired.value ? 'invitations.expired' : 'invitations.expires', {
        date: formatRelative(props.invitation.expiresAt, locale),
    }),
);

async function sendAgain() {
    const url = props.invitation.links.resend;

    if (!url) {
        return;
    }

    resent.value = false;
    const result = await resend.submit({ method: 'post', url });
    resent.value = result?.ok === true;
}

async function confirmRevoke() {
    const url = props.invitation.links.revoke;

    if (!url) {
        return;
    }

    const result = await revoke.submit({ method: 'delete', url });

    if (result?.ok) {
        confirmingRevoke.value = false;
    }
}
</script>

<template>
    <li class="space-y-2 px-4 py-3 sm:px-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0 space-y-1">
                <div class="flex flex-wrap items-center gap-2">
                    <span
                        class="truncate text-sm font-medium text-(--concierge-text)"
                        >{{ invitation.email }}</span
                    >
                    <Badge
                        :label="statusLabel"
                        :variant="expired ? 'danger' : 'warning'"
                    />
                </div>
                <div class="flex flex-wrap gap-1.5">
                    <Badge
                        v-for="role in invitation.roles"
                        :key="role.value"
                        :label="role.label"
                        :variant="role.variant"
                    />
                </div>
                <p
                    class="flex flex-wrap gap-x-3 gap-y-1 text-xs text-(--concierge-text-muted)"
                >
                    <span
                        ><time
                            :datetime="invitation.expiresAt"
                            :title="formatDate(invitation.expiresAt, locale)"
                            >{{ expiry }}</time
                        ></span
                    >
                    <span v-if="invitation.lastSentAt">{{
                        t('invitations.sent', {
                            date: formatDate(invitation.lastSentAt, locale),
                        })
                    }}</span>
                    <span v-else>{{ t('invitations.notSent') }}</span>
                    <span v-if="invitation.invitedBy">{{
                        t('invitations.invitedBy', {
                            name: invitation.invitedBy,
                        })
                    }}</span>
                </p>
            </div>
            <div
                v-if="invitation.hasActions"
                class="flex shrink-0 flex-wrap gap-2"
            >
                <Button
                    v-if="invitation.links.resend"
                    size="sm"
                    variant="muted"
                    tier="outline"
                    :busy="resend.pending.value"
                    @click="sendAgain"
                    >{{ t('invitations.resend') }}</Button
                >
                <Button
                    v-if="invitation.links.revoke"
                    size="sm"
                    variant="danger"
                    tier="outline"
                    @click="
                        revoke.reset();
                        confirmingRevoke = true;
                    "
                    >{{ t('invitations.revoke') }}</Button
                >
            </div>
        </div>

        <Alert v-if="resend.failure.value" variant="danger" live="assertive">
            {{ resend.failure.value }}
            <template #actions>
                <Button
                    size="sm"
                    variant="danger"
                    tier="outline"
                    :busy="resend.pending.value"
                    @click="sendAgain"
                    >{{ t('actions.retry') }}</Button
                >
            </template>
        </Alert>
        <Alert v-else-if="resent" variant="success" live="polite">
            {{ t('invitations.resent', { email: invitation.email }) }}
        </Alert>

        <ConfirmDialog
            :open="confirmingRevoke"
            :title="t('invitations.revoke.title')"
            :confirm-label="
                revoke.failure.value
                    ? t('actions.retry')
                    : t('invitations.revoke.confirm')
            "
            :cancel-label="t('actions.cancel')"
            :busy="revoke.pending.value"
            :error="revoke.failure.value"
            @confirm="confirmRevoke"
            @close="confirmingRevoke = false"
        >
            <p>
                {{ t('invitations.revoke.body', { email: invitation.email }) }}
            </p>
        </ConfirmDialog>
    </li>
</template>
