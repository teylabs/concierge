<script setup lang="ts">
/**
 * Member detail. Adapted from cookbook pages/UserManagement/Edit section
 * composition: Membership summary, Roles (omitted to read-only when the
 * server sends grants: null) and Access actions. No global profile,
 * password, deactivation or impersonation controls.
 */
import { computed, reactive, ref, watch } from 'vue';
import MemberActions from '../internal/MemberActions.vue';
import { formatDate } from '../internal/format';
import { shouldIntercept } from '../internal/navigation';
import { useSubmit } from '../internal/useSubmit';
import { useConcierge } from '../plugin';
import type { MemberPageProps } from '../types';
import Alert from '../ui/Alert.vue';
import Badge from '../ui/Badge.vue';
import Button from '../ui/Button.vue';
import FormField from '../ui/FormField.vue';
import PageHeader from '../ui/PageHeader.vue';
import RoleChecklist from '../ui/RoleChecklist.vue';
import Surface from '../ui/Surface.vue';

const props = defineProps<MemberPageProps>();

const { adapter, t, locale } = useConcierge();
const { pending, errors, failure, submit, retry } = useSubmit();

const form = reactive({ roles: [...(props.grants?.payload.roles ?? [])] });
const saved = ref(false);

watch(
    () => props.grants?.payload.roles,
    (roles) => {
        form.roles = [...(roles ?? [])];
    },
);

const dirty = computed(() => {
    const original = [...(props.grants?.payload.roles ?? [])].sort();
    const current = [...form.roles].sort();

    return original.join('\u0000') !== current.join('\u0000');
});

const rolesError = computed(
    () =>
        errors.value.roles ??
        Object.entries(errors.value).find(([key]) =>
            key.startsWith('roles.'),
        )?.[1] ??
        null,
);

const statusVariant = computed(
    () =>
        ({ active: 'success', suspended: 'warning', removed: 'muted' })[
            props.member.status
        ],
);

const params = computed(() => ({
    name: props.member.user.name,
    workspace: props.workspace.name,
}));

async function saveRoles() {
    if (!props.grants) {
        return;
    }

    saved.value = false;
    const result = await submit({
        method: 'put',
        url: props.grants.metadata.formUrl,
        data: { roles: form.roles },
    });
    saved.value = result?.ok === true;
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
            <PageHeader
                :title="member.user.name"
                :description="member.user.email"
            >
                <template #breadcrumbs>
                    <slot name="breadcrumbs">
                        <a
                            :href="metadata.links.back"
                            class="text-sm font-medium text-(--concierge-primary-solid) hover:underline focus-visible:outline-2 focus-visible:outline-(--concierge-focus)"
                            @click="back"
                            >← {{ t('member.back') }}</a
                        >
                    </slot>
                </template>
            </PageHeader>
        </slot>

        <Surface as="section" aria-labelledby="concierge-member-details">
            <h2
                id="concierge-member-details"
                class="border-b border-(--concierge-border) px-4 py-3 text-base font-semibold text-(--concierge-text) sm:px-6"
            >
                {{ t('member.details') }}
            </h2>
            <dl class="grid gap-4 px-4 py-4 text-sm sm:grid-cols-3 sm:px-6">
                <div class="space-y-1">
                    <dt class="text-(--concierge-text-muted)">
                        {{ t('member.status') }}
                    </dt>
                    <dd class="flex flex-wrap gap-1.5">
                        <Badge
                            :label="t(`members.status.${member.status}`)"
                            :variant="statusVariant"
                        />
                        <Badge
                            v-if="member.isSelf"
                            :label="t('members.you')"
                            variant="info"
                        />
                    </dd>
                </div>
                <div class="space-y-1">
                    <dt class="text-(--concierge-text-muted)">
                        {{ t('member.joined') }}
                    </dt>
                    <dd class="text-(--concierge-text)">
                        <time
                            v-if="member.joinedAt"
                            :datetime="member.joinedAt"
                            >{{ formatDate(member.joinedAt, locale) }}</time
                        >
                        <span v-else>{{ t('member.notJoined') }}</span>
                    </dd>
                </div>
                <div class="space-y-1">
                    <dt class="text-(--concierge-text-muted)">
                        {{ t('member.roles') }}
                    </dt>
                    <dd class="flex flex-wrap gap-1.5">
                        <Badge
                            v-if="member.isOwner"
                            :label="t('members.owner')"
                            variant="primary-solid"
                        />
                        <Badge
                            v-for="role in member.roles"
                            :key="role.value"
                            :label="role.label"
                            :variant="role.variant"
                        />
                        <span
                            v-if="!member.roles.length && !member.isOwner"
                            class="text-(--concierge-text-muted)"
                            >{{ t('members.noRoles') }}</span
                        >
                    </dd>
                </div>
            </dl>
        </Surface>

        <Surface
            v-if="grants"
            as="form"
            :clip="false"
            aria-labelledby="concierge-member-roles"
            @submit.prevent="saveRoles"
        >
            <div class="space-y-4 p-4 sm:p-6">
                <div>
                    <h2
                        id="concierge-member-roles"
                        class="text-base font-semibold text-(--concierge-text)"
                    >
                        {{ t('member.roles') }}
                    </h2>
                    <p class="text-sm text-(--concierge-text-muted)">
                        {{ t('member.rolesDescription', params) }}
                    </p>
                </div>
                <FormField
                    v-slot="{ describedBy, invalid }"
                    as="fieldset"
                    label-hidden
                    :label="t('member.roles')"
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
                <Alert
                    v-else-if="saved && !dirty"
                    variant="success"
                    live="polite"
                    >{{ t('member.rolesSaved') }}</Alert
                >
            </div>
            <div
                class="flex justify-end rounded-b-md border-t border-(--concierge-border) bg-(--concierge-surface-muted) px-4 py-3 sm:px-6"
            >
                <Button type="submit" :busy="pending" :disabled="!dirty">{{
                    t('member.saveRoles')
                }}</Button>
            </div>
        </Surface>
        <Alert v-else variant="info">{{ t('member.rolesReadOnly') }}</Alert>

        <Surface as="section" aria-labelledby="concierge-member-access">
            <div class="space-y-4 p-4 sm:p-6">
                <div>
                    <h2
                        id="concierge-member-access"
                        class="text-base font-semibold text-(--concierge-text)"
                    >
                        {{ t('member.access') }}
                    </h2>
                    <p class="text-sm text-(--concierge-text-muted)">
                        {{ t('member.accessDescription', params) }}
                    </p>
                </div>
                <MemberActions
                    v-if="member.hasActions"
                    :member="member"
                    :workspace-name="workspace.name"
                    size="md"
                />
                <p v-else class="text-sm text-(--concierge-text-muted)">
                    {{ t('member.noActions') }}
                </p>
            </div>
        </Surface>

        <slot name="aside" />
    </div>
</template>
