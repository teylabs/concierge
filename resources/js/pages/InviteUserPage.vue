<script setup lang="ts">
/**
 * Invite form for the app: email plus role checklist, posted to metadata.formUrl.
 * Role choices come only from the server; the server re-validates every grant.
 */
import { computed, reactive, ref } from 'vue';
import { shouldIntercept } from '../internal/navigation';
import { useSubmit } from '../internal/useSubmit';
import { useConcierge } from '../plugin';
import type { InviteUserPageProps } from '../types';
import Alert from '../ui/Alert.vue';
import Button from '../ui/Button.vue';
import FormField from '../ui/FormField.vue';
import PageHeader from '../ui/PageHeader.vue';
import RoleChecklist from '../ui/RoleChecklist.vue';
import Surface from '../ui/Surface.vue';
import { controlClasses } from '../ui/controlClasses';

const props = defineProps<InviteUserPageProps>();

const { adapter, t } = useConcierge();
const { pending, errors, failure, submit, retry } = useSubmit();

const form = reactive({
    email: props.payload.email,
    roles: [...props.payload.roles],
});

const emailInput = ref<HTMLInputElement | null>(null);

const canGrant = computed(() => props.metadata.roleOptions.length > 0);

const rolesError = computed(
    () =>
        errors.value.roles ??
        Object.entries(errors.value).find(([key]) =>
            key.startsWith('roles.'),
        )?.[1] ??
        null,
);

async function send() {
    const result = await submit({
        method: 'post',
        url: props.metadata.formUrl,
        data: { email: form.email, roles: form.roles },
    });

    if (result && !result.ok && 'errors' in result && result.errors.email) {
        emailInput.value?.focus();
    }
}

function cancel(event: MouseEvent) {
    if (shouldIntercept(event)) {
        event.preventDefault();
        void adapter.visit(props.metadata.links.cancel);
    }
}
</script>

<template>
    <div
        class="concierge mx-auto max-w-2xl space-y-6 font-(family-name:--concierge-font)"
    >
        <slot name="header">
            <PageHeader
                :title="t('inviteUser.title')"
                :description="t('inviteUser.description')"
            >
                <template v-if="$slots.breadcrumbs" #breadcrumbs>
                    <slot name="breadcrumbs" />
                </template>
            </PageHeader>
        </slot>

        <Surface as="form" :clip="false" novalidate @submit.prevent="send">
            <div class="space-y-6 p-4 sm:p-6">
                <Alert v-if="!canGrant" variant="warning">{{
                    t('inviteUser.noRoles')
                }}</Alert>

                <FormField
                    v-slot="{ id, describedBy, invalid }"
                    :label="t('inviteUser.email')"
                    :error="errors.email"
                >
                    <input
                        :id="id"
                        ref="emailInput"
                        v-model="form.email"
                        type="email"
                        name="email"
                        autocomplete="email"
                        required
                        :disabled="!canGrant"
                        :aria-describedby="describedBy"
                        :aria-invalid="invalid"
                        :class="controlClasses"
                    />
                </FormField>

                <FormField
                    v-if="canGrant"
                    v-slot="{ describedBy, invalid }"
                    as="fieldset"
                    :label="t('inviteUser.roles')"
                    :hint="t('inviteUser.rolesHint')"
                    :error="rolesError"
                >
                    <RoleChecklist
                        v-model="form.roles"
                        name="roles"
                        :options="metadata.roleOptions"
                        :described-by="describedBy"
                        :invalid="invalid"
                    />
                </FormField>

                <Alert v-if="failure" variant="danger" live="assertive">
                    {{ failure }}
                    <template v-if="!Object.keys(errors).length" #actions>
                        <Button
                            size="sm"
                            variant="danger"
                            tier="outline"
                            :busy="pending"
                            @click="retry"
                            >{{ t('actions.retry') }}</Button
                        >
                    </template>
                </Alert>
            </div>
            <div
                class="flex flex-col-reverse gap-2 rounded-b-md border-t border-(--concierge-border) bg-(--concierge-surface-muted) px-4 py-3 sm:flex-row sm:justify-end sm:px-6"
            >
                <Button
                    variant="muted"
                    tier="outline"
                    :href="metadata.links.cancel"
                    @click="cancel"
                    >{{ t('actions.cancel') }}</Button
                >
                <Button type="submit" :busy="pending" :disabled="!canGrant">{{
                    t('inviteUser.submit')
                }}</Button>
            </div>
        </Surface>

        <slot name="aside" />
    </div>
</template>
