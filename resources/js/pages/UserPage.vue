<script setup lang="ts">
/**
 * User detail sections: Account summary, Profile (name and email; read-only
 * when the server sends profile: null), Roles (read-only when it sends
 * grants: null) and Access actions. Email changes are the app's business:
 * nothing here clears verification or sends a notice.
 */
import { computed, reactive, ref, watch } from 'vue';
import UserActions from '../internal/UserActions.vue';
import { formatDate } from '../internal/format';
import { shouldIntercept } from '../internal/navigation';
import { useSubmit } from '../internal/useSubmit';
import { useConcierge } from '../plugin';
import type { UserPageProps } from '../types';
import Alert from '../ui/Alert.vue';
import Avatar from '../ui/Avatar.vue';
import Badge from '../ui/Badge.vue';
import Button from '../ui/Button.vue';
import FormField from '../ui/FormField.vue';
import PageHeader from '../ui/PageHeader.vue';
import RoleChecklist from '../ui/RoleChecklist.vue';
import Surface from '../ui/Surface.vue';
import { controlClasses } from '../ui/controlClasses';

const props = defineProps<UserPageProps>();

const { adapter, t, locale } = useConcierge();
const rolesRequest = useSubmit();
const profileRequest = useSubmit();

const form = reactive({ roles: [...(props.grants?.payload.roles ?? [])] });
const profileForm = reactive({
    name: props.profile?.payload.name ?? '',
    email: props.profile?.payload.email ?? '',
});
const rolesSaved = ref(false);
const profileSaved = ref(false);

watch(
    () => props.grants?.payload.roles,
    (roles) => {
        form.roles = [...(roles ?? [])];
    },
);

watch(
    () => props.profile?.payload,
    (payload) => {
        profileForm.name = payload?.name ?? '';
        profileForm.email = payload?.email ?? '';
    },
);

const rolesDirty = computed(() => {
    const original = [...(props.grants?.payload.roles ?? [])].sort();
    const current = [...form.roles].sort();

    return original.join('\u0000') !== current.join('\u0000');
});

const profileDirty = computed(
    () =>
        profileForm.name !== (props.profile?.payload.name ?? '') ||
        profileForm.email !== (props.profile?.payload.email ?? ''),
);

const rolesError = computed(
    () =>
        rolesRequest.errors.value.roles ??
        Object.entries(rolesRequest.errors.value).find(([key]) =>
            key.startsWith('roles.'),
        )?.[1] ??
        null,
);

const statusVariant = computed(
    () => ({ active: 'success', deactivated: 'muted' })[props.user.status],
);

const params = computed(() => ({ name: props.user.user.name }));

async function saveRoles() {
    if (!props.grants) {
        return;
    }

    rolesSaved.value = false;
    const result = await rolesRequest.submit({
        method: 'put',
        url: props.grants.metadata.formUrl,
        data: { roles: form.roles },
    });
    rolesSaved.value = result?.ok === true;
}

async function saveProfile() {
    if (!props.profile) {
        return;
    }

    profileSaved.value = false;
    const result = await profileRequest.submit({
        method: 'put',
        url: props.profile.metadata.formUrl,
        data: { name: profileForm.name, email: profileForm.email },
    });
    profileSaved.value = result?.ok === true;
}

function back(event: MouseEvent) {
    if (shouldIntercept(event)) {
        event.preventDefault();
        void adapter.visit(props.metadata.links.back);
    }
}
</script>

<template>
    <div
        class="concierge mx-auto max-w-3xl space-y-6 font-(family-name:--concierge-font)"
    >
        <slot name="header">
            <PageHeader :title="user.user.name" :description="user.user.email">
                <template #leading>
                    <Avatar :avatar="user.user.avatar" size="lg" />
                </template>
                <template #breadcrumbs>
                    <slot name="breadcrumbs">
                        <a
                            :href="metadata.links.back"
                            class="text-sm font-medium text-(--concierge-primary-solid) hover:underline focus-visible:outline-2 focus-visible:outline-(--concierge-focus)"
                            @click="back"
                            >← {{ t('user.back') }}</a
                        >
                    </slot>
                </template>
            </PageHeader>
        </slot>

        <Surface as="section" aria-labelledby="concierge-user-details">
            <h2
                id="concierge-user-details"
                class="border-b border-(--concierge-border) px-4 py-3 text-base font-semibold text-(--concierge-text) sm:px-6"
            >
                {{ t('user.details') }}
            </h2>
            <dl class="grid gap-4 px-4 py-4 text-sm sm:grid-cols-3 sm:px-6">
                <div class="space-y-1">
                    <dt class="text-(--concierge-text-muted)">
                        {{ t('user.status') }}
                    </dt>
                    <dd class="flex flex-wrap gap-1.5">
                        <Badge
                            :label="t(`users.status.${user.status}`)"
                            :variant="statusVariant"
                        />
                        <Badge
                            v-if="user.isSelf"
                            :label="t('users.you')"
                            variant="info"
                        />
                        <Badge
                            v-if="user.isOperator"
                            :label="t('users.operator')"
                            variant="warning"
                        />
                    </dd>
                </div>
                <div class="space-y-1">
                    <dt class="text-(--concierge-text-muted)">
                        {{ t('user.joined') }}
                    </dt>
                    <dd class="text-(--concierge-text)">
                        <time v-if="user.joinedAt" :datetime="user.joinedAt">{{
                            formatDate(user.joinedAt, locale)
                        }}</time>
                        <span v-else>{{ t('user.notJoined') }}</span>
                    </dd>
                </div>
                <div class="space-y-1">
                    <dt class="text-(--concierge-text-muted)">
                        {{ t('user.roles') }}
                    </dt>
                    <dd class="flex flex-wrap gap-1.5">
                        <Badge
                            v-if="user.isOwner"
                            :label="t('users.owner')"
                            variant="primary-solid"
                        />
                        <Badge
                            v-for="badge in user.roles"
                            :key="badge.value"
                            :label="badge.label"
                            :variant="badge.variant"
                        />
                        <span
                            v-if="!user.roles.length && !user.isOwner"
                            class="text-(--concierge-text-muted)"
                            >{{ t('users.noRoles') }}</span
                        >
                    </dd>
                </div>
            </dl>
        </Surface>

        <Surface
            v-if="profile"
            as="form"
            :clip="false"
            novalidate
            aria-labelledby="concierge-user-profile"
            @submit.prevent="saveProfile"
        >
            <div class="space-y-4 p-4 sm:p-6">
                <div>
                    <h2
                        id="concierge-user-profile"
                        class="text-base font-semibold text-(--concierge-text)"
                    >
                        {{ t('user.profile') }}
                    </h2>
                    <p class="text-sm text-(--concierge-text-muted)">
                        {{ t('user.profileDescription') }}
                    </p>
                </div>
                <FormField
                    v-slot="{ id, describedBy, invalid }"
                    :label="t('user.name')"
                    :error="profileRequest.errors.value.name"
                >
                    <input
                        :id="id"
                        v-model="profileForm.name"
                        type="text"
                        name="name"
                        autocomplete="off"
                        required
                        :aria-describedby="describedBy"
                        :aria-invalid="invalid"
                        :class="controlClasses"
                    />
                </FormField>
                <FormField
                    v-slot="{ id, describedBy, invalid }"
                    :label="t('user.email')"
                    :error="profileRequest.errors.value.email"
                >
                    <input
                        :id="id"
                        v-model="profileForm.email"
                        type="email"
                        name="email"
                        autocomplete="off"
                        required
                        :aria-describedby="describedBy"
                        :aria-invalid="invalid"
                        :class="controlClasses"
                    />
                </FormField>
                <Alert
                    v-if="profileRequest.failure.value"
                    variant="danger"
                    live="assertive"
                >
                    {{ profileRequest.failure.value }}
                    <template
                        v-if="!Object.keys(profileRequest.errors.value).length"
                        #actions
                    >
                        <Button
                            size="sm"
                            variant="danger"
                            tier="outline"
                            :busy="profileRequest.pending.value"
                            @click="profileRequest.retry"
                            >{{ t('actions.retry') }}</Button
                        >
                    </template>
                </Alert>
                <Alert
                    v-else-if="profileSaved && !profileDirty"
                    variant="success"
                    live="polite"
                    >{{ t('user.profileSaved') }}</Alert
                >
            </div>
            <div
                class="flex justify-end rounded-b-md border-t border-(--concierge-border) bg-(--concierge-surface-muted) px-4 py-3 sm:px-6"
            >
                <Button
                    type="submit"
                    :busy="profileRequest.pending.value"
                    :disabled="!profileDirty"
                    >{{ t('user.saveProfile') }}</Button
                >
            </div>
        </Surface>
        <Alert v-else variant="info">{{ t('user.profileReadOnly') }}</Alert>

        <Surface
            v-if="grants"
            as="form"
            :clip="false"
            aria-labelledby="concierge-user-roles"
            @submit.prevent="saveRoles"
        >
            <div class="space-y-4 p-4 sm:p-6">
                <div>
                    <h2
                        id="concierge-user-roles"
                        class="text-base font-semibold text-(--concierge-text)"
                    >
                        {{ t('user.roles') }}
                    </h2>
                    <p class="text-sm text-(--concierge-text-muted)">
                        {{ t('user.rolesDescription', params) }}
                    </p>
                </div>
                <FormField
                    v-slot="{ describedBy, invalid }"
                    as="fieldset"
                    label-hidden
                    :label="t('user.roles')"
                    :error="rolesError"
                >
                    <RoleChecklist
                        v-model="form.roles"
                        name="roles"
                        :options="grants.metadata.roleOptions"
                        :described-by="describedBy"
                        :invalid="invalid"
                    />
                </FormField>
                <Alert
                    v-if="rolesRequest.failure.value"
                    variant="danger"
                    live="assertive"
                >
                    {{ rolesRequest.failure.value }}
                    <template
                        v-if="!Object.keys(rolesRequest.errors.value).length"
                        #actions
                    >
                        <Button
                            size="sm"
                            variant="danger"
                            tier="outline"
                            :busy="rolesRequest.pending.value"
                            @click="rolesRequest.retry"
                            >{{ t('actions.retry') }}</Button
                        >
                    </template>
                </Alert>
                <Alert
                    v-else-if="rolesSaved && !rolesDirty"
                    variant="success"
                    live="polite"
                    >{{ t('user.rolesSaved') }}</Alert
                >
            </div>
            <div
                class="flex justify-end rounded-b-md border-t border-(--concierge-border) bg-(--concierge-surface-muted) px-4 py-3 sm:px-6"
            >
                <Button
                    type="submit"
                    :busy="rolesRequest.pending.value"
                    :disabled="!rolesDirty"
                    >{{ t('user.saveRoles') }}</Button
                >
            </div>
        </Surface>
        <Alert v-else variant="info">{{ t('user.rolesReadOnly') }}</Alert>

        <Surface as="section" aria-labelledby="concierge-user-access">
            <div class="space-y-4 p-4 sm:p-6">
                <div>
                    <h2
                        id="concierge-user-access"
                        class="text-base font-semibold text-(--concierge-text)"
                    >
                        {{ t('user.access') }}
                    </h2>
                    <p class="text-sm text-(--concierge-text-muted)">
                        {{ t('user.accessDescription') }}
                    </p>
                </div>
                <UserActions v-if="user.hasActions" :user="user" size="md" />
                <p v-else class="text-sm text-(--concierge-text-muted)">
                    {{ t('user.noActions') }}
                </p>
            </div>
        </Surface>

        <slot name="aside" />
    </div>
</template>
