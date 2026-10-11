<script setup lang="ts">
/**
 * Workspace members index: search/status filter, paginated members with
 * server-authorised row actions, and pending/expired invitations.
 * Uses membership semantics with no global account controls.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import InvitationItem from '../internal/InvitationItem.vue';
import MemberActions from '../internal/MemberActions.vue';
import { formatDate } from '../internal/format';
import { shouldIntercept } from '../internal/navigation';
import { useConcierge } from '../plugin';
import type { MemberRow, MembersPageProps } from '../types';
import Avatar from '../ui/Avatar.vue';
import Badge from '../ui/Badge.vue';
import Button from '../ui/Button.vue';
import EmptyState from '../ui/EmptyState.vue';
import PageHeader from '../ui/PageHeader.vue';
import Pagination from '../ui/Pagination.vue';
import Spinner from '../ui/Spinner.vue';
import Surface from '../ui/Surface.vue';
import { controlClasses } from '../ui/controlClasses';

const props = defineProps<MembersPageProps>();

const { adapter, t, locale } = useConcierge();

const search = ref(props.metadata.search);
const status = ref(props.metadata.status ?? '');
const loading = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;

const statuses = ['active', 'suspended', 'removed'] as const;

const filtered = computed(
    () => props.metadata.search !== '' || props.metadata.status !== null,
);

watch(
    () => [props.metadata.search, props.metadata.status] as const,
    ([nextSearch, nextStatus]) => {
        if (timer === undefined) {
            search.value = nextSearch;
            status.value = nextStatus ?? '';
        }
    },
);

function refresh() {
    clearTimeout(timer);
    timer = undefined;
    loading.value = true;

    const data: Record<string, string> = {};

    if (search.value.trim() !== '') {
        data.search = search.value.trim();
    }

    if (status.value !== '') {
        data.status = status.value;
    }

    adapter
        .visit(props.metadata.refreshUrl, {
            data,
            preserveScroll: true,
            preserveState: true,
            replace: true,
        })
        .finally(() => {
            loading.value = false;
        });
}

function onSearchInput() {
    clearTimeout(timer);
    timer = setTimeout(refresh, 300);
}

function clearFilters() {
    search.value = '';
    status.value = '';
    refresh();
}

onBeforeUnmount(() => clearTimeout(timer));

function navigate(url: string, event: MouseEvent) {
    if (!shouldIntercept(event)) {
        return;
    }

    event.preventDefault();
    loading.value = true;
    adapter
        .visit(url, { preserveScroll: false, preserveState: true })
        .finally(() => {
            loading.value = false;
        });
}

function open(url: string, event: MouseEvent) {
    if (shouldIntercept(event)) {
        event.preventDefault();
        void adapter.visit(url);
    }
}

function statusVariant(member: MemberRow): string {
    return { active: 'success', suspended: 'warning', removed: 'muted' }[
        member.status
    ];
}
</script>

<template>
    <div class="concierge space-y-6 font-(family-name:--concierge-font)">
        <slot name="header">
            <PageHeader
                :title="t('members.title')"
                :description="workspace.name"
            >
                <template v-if="$slots.breadcrumbs" #breadcrumbs>
                    <slot name="breadcrumbs" />
                </template>
                <template
                    v-if="metadata.abilities.invite && metadata.links.invite"
                    #actions
                >
                    <Button
                        :href="metadata.links.invite"
                        @click="open(metadata.links.invite, $event)"
                        >{{ t('members.invite') }}</Button
                    >
                </template>
            </PageHeader>
        </slot>

        <Surface
            as="section"
            :aria-busy="loading || undefined"
            aria-labelledby="concierge-members-heading"
        >
            <h2 id="concierge-members-heading" class="sr-only">
                {{ t('members.title') }}
            </h2>
            <form
                role="search"
                class="flex flex-col gap-3 border-b border-(--concierge-border) px-4 py-3 sm:flex-row sm:items-end sm:px-6"
                @submit.prevent="refresh"
            >
                <label class="block min-w-0 flex-1 space-y-1.5">
                    <span
                        class="block text-sm font-medium text-(--concierge-text)"
                        >{{ t('members.search') }}</span
                    >
                    <input
                        v-model="search"
                        type="search"
                        name="search"
                        :placeholder="t('members.searchPlaceholder')"
                        :class="controlClasses"
                        @input="onSearchInput"
                    />
                </label>
                <label class="block space-y-1.5 sm:w-48">
                    <span
                        class="block text-sm font-medium text-(--concierge-text)"
                        >{{ t('members.status') }}</span
                    >
                    <select
                        v-model="status"
                        name="status"
                        :class="controlClasses"
                        @change="refresh"
                    >
                        <option value="">{{ t('members.status.all') }}</option>
                        <option
                            v-for="value in statuses"
                            :key="value"
                            :value="value"
                        >
                            {{ t(`members.status.${value}`) }}
                        </option>
                    </select>
                </label>
                <div
                    v-if="loading || filtered"
                    class="flex h-9 items-center gap-3"
                >
                    <span
                        v-if="loading"
                        class="inline-flex items-center gap-2 text-sm text-(--concierge-text-muted)"
                        role="status"
                    >
                        <Spinner />{{ t('members.loading') }}
                    </span>
                    <Button
                        v-else-if="filtered"
                        variant="muted"
                        tier="ghost"
                        @click="clearFilters"
                        >{{ t('members.clearFilters') }}</Button
                    >
                </div>
            </form>

            <div
                :class="
                    loading
                        ? 'opacity-60 transition-opacity'
                        : 'transition-opacity'
                "
            >
                <table v-if="members.length" class="w-full text-left text-sm">
                    <thead
                        class="hidden bg-(--concierge-surface-muted) text-xs font-medium tracking-wide text-(--concierge-text-muted) uppercase md:table-header-group"
                    >
                        <tr>
                            <th scope="col" class="px-6 py-2.5">
                                {{ t('members.column.member') }}
                            </th>
                            <th scope="col" class="px-3 py-2.5">
                                {{ t('members.column.roles') }}
                            </th>
                            <th scope="col" class="px-3 py-2.5">
                                {{ t('members.column.status') }}
                            </th>
                            <th scope="col" class="px-3 py-2.5">
                                {{ t('members.column.joined') }}
                            </th>
                            <th scope="col" class="px-6 py-2.5 text-right">
                                <span class="sr-only">{{
                                    t('members.column.actions')
                                }}</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-(--concierge-border)">
                        <tr
                            v-for="member in members"
                            :key="member.id"
                            class="flex flex-wrap items-center gap-x-3 gap-y-2 px-4 py-4 md:table-row md:px-0 md:py-0"
                        >
                            <td class="w-full md:w-auto md:px-6 md:py-3">
                                <div class="flex items-center gap-3">
                                    <Avatar
                                        :avatar="member.user.avatar"
                                        size="sm"
                                    />
                                    <div class="min-w-0">
                                        <div
                                            class="flex flex-wrap items-center gap-2"
                                        >
                                            <a
                                                v-if="member.links.show"
                                                :href="member.links.show"
                                                class="font-medium text-(--concierge-text) underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-(--concierge-focus)"
                                                @click="
                                                    open(
                                                        member.links.show,
                                                        $event,
                                                    )
                                                "
                                                >{{ member.user.name }}</a
                                            >
                                            <span
                                                v-else
                                                class="font-medium text-(--concierge-text)"
                                                >{{ member.user.name }}</span
                                            >
                                            <Badge
                                                v-if="member.isSelf"
                                                :label="t('members.you')"
                                                variant="info"
                                            />
                                        </div>
                                        <div
                                            class="text-(--concierge-text-muted)"
                                        >
                                            {{ member.user.email }}
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td class="md:px-3 md:py-3">
                                <div class="flex flex-wrap gap-1.5">
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
                                        v-if="
                                            !member.roles.length &&
                                            !member.isOwner
                                        "
                                        class="text-(--concierge-text-muted)"
                                        >{{ t('members.noRoles') }}</span
                                    >
                                </div>
                            </td>
                            <td class="md:px-3 md:py-3">
                                <Badge
                                    :label="
                                        t(`members.status.${member.status}`)
                                    "
                                    :variant="statusVariant(member)"
                                />
                            </td>
                            <td
                                class="text-sm text-(--concierge-text-muted) md:px-3 md:py-3"
                            >
                                <time
                                    v-if="member.joinedAt"
                                    :datetime="member.joinedAt"
                                    >{{
                                        formatDate(member.joinedAt, locale)
                                    }}</time
                                >
                            </td>
                            <td
                                class="w-full empty:hidden md:w-auto md:px-6 md:py-3"
                            >
                                <div
                                    v-if="member.hasActions"
                                    class="flex flex-wrap gap-2 md:justify-end"
                                >
                                    <MemberActions
                                        :member="member"
                                        :workspace-name="workspace.name"
                                    />
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <EmptyState
                    v-else-if="filtered"
                    :title="t('members.noResults.title')"
                >
                    {{ t('members.noResults.body') }}
                    <template #actions>
                        <Button
                            variant="muted"
                            tier="outline"
                            @click="clearFilters"
                            >{{ t('members.clearFilters') }}</Button
                        >
                    </template>
                </EmptyState>
                <slot v-else name="empty">
                    <EmptyState :title="t('members.empty.title')">
                        {{
                            t('members.empty.body', {
                                workspace: workspace.name,
                            })
                        }}
                    </EmptyState>
                </slot>
            </div>

            <Pagination
                v-if="pagination.lastPage > 1"
                :pagination="pagination"
                :label="t('pagination.label')"
                :summary="
                    t('pagination.summary', {
                        current: pagination.currentPage,
                        last: pagination.lastPage,
                        total: pagination.total,
                    })
                "
                :previous-label="t('pagination.previous')"
                :next-label="t('pagination.next')"
                @navigate="navigate"
            />
        </Surface>

        <Surface
            v-if="invitations.length"
            as="section"
            aria-labelledby="concierge-invitations-heading"
        >
            <div class="border-b border-(--concierge-border) px-4 py-3 sm:px-6">
                <h2
                    id="concierge-invitations-heading"
                    class="text-base font-semibold text-(--concierge-text)"
                >
                    {{ t('invitations.title') }}
                </h2>
                <p class="text-sm text-(--concierge-text-muted)">
                    {{ t('invitations.description') }}
                </p>
            </div>
            <ul class="divide-y divide-(--concierge-border)">
                <InvitationItem
                    v-for="invitation in invitations"
                    :key="invitation.id"
                    :invitation="invitation"
                />
            </ul>
        </Surface>

        <slot name="aside" />
    </div>
</template>
