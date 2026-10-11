<script setup lang="ts">
/**
 * App-wide users index: search, role and status filters, and paginated users
 * with server-authorised row actions. The app's own columns come from
 * metadata.columns and render through the "column" slot.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import UserActions from '../internal/UserActions.vue';
import { formatDate } from '../internal/format';
import { shouldIntercept } from '../internal/navigation';
import { useConcierge } from '../plugin';
import type { UserRow, UsersPageProps } from '../types';
import Avatar from '../ui/Avatar.vue';
import Badge from '../ui/Badge.vue';
import Button from '../ui/Button.vue';
import EmptyState from '../ui/EmptyState.vue';
import PageHeader from '../ui/PageHeader.vue';
import Pagination from '../ui/Pagination.vue';
import Spinner from '../ui/Spinner.vue';
import Surface from '../ui/Surface.vue';
import { controlClasses } from '../ui/controlClasses';

const props = defineProps<UsersPageProps>();

const { adapter, t, locale } = useConcierge();

const search = ref(props.metadata.search);
const role = ref(props.metadata.role ?? '');
const status = ref(props.metadata.status ?? '');
const loading = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;

const statuses = ['active', 'deactivated'] as const;

const filtered = computed(
    () =>
        props.metadata.search !== '' ||
        props.metadata.role !== null ||
        props.metadata.status !== null,
);

watch(
    () =>
        [
            props.metadata.search,
            props.metadata.role,
            props.metadata.status,
        ] as const,
    ([nextSearch, nextRole, nextStatus]) => {
        if (timer === undefined) {
            search.value = nextSearch;
            role.value = nextRole ?? '';
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

    if (role.value !== '') {
        data.role = role.value;
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
    role.value = '';
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

function statusVariant(row: UserRow): string {
    return { active: 'success', deactivated: 'muted' }[row.status];
}
</script>

<template>
    <div class="concierge space-y-6 font-(family-name:--concierge-font)">
        <slot name="header">
            <PageHeader :title="t('users.title')">
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
                        >{{ t('users.invite') }}</Button
                    >
                </template>
            </PageHeader>
        </slot>

        <Surface
            as="section"
            :aria-busy="loading || undefined"
            aria-labelledby="concierge-users-heading"
        >
            <h2 id="concierge-users-heading" class="sr-only">
                {{ t('users.title') }}
            </h2>
            <form
                role="search"
                class="flex flex-col gap-3 border-b border-(--concierge-border) px-4 py-3 sm:flex-row sm:items-end sm:px-6"
                @submit.prevent="refresh"
            >
                <label class="block min-w-0 flex-1 space-y-1.5">
                    <span
                        class="block text-sm font-medium text-(--concierge-text)"
                        >{{ t('users.search') }}</span
                    >
                    <input
                        v-model="search"
                        type="search"
                        name="search"
                        :placeholder="t('users.searchPlaceholder')"
                        :class="controlClasses"
                        @input="onSearchInput"
                    />
                </label>
                <label class="block space-y-1.5 sm:w-48">
                    <span
                        class="block text-sm font-medium text-(--concierge-text)"
                        >{{ t('users.role') }}</span
                    >
                    <select
                        v-model="role"
                        name="role"
                        :class="controlClasses"
                        @change="refresh"
                    >
                        <option value="">{{ t('users.role.all') }}</option>
                        <option
                            v-for="option in metadata.roleOptions"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>
                </label>
                <label class="block space-y-1.5 sm:w-48">
                    <span
                        class="block text-sm font-medium text-(--concierge-text)"
                        >{{ t('users.status') }}</span
                    >
                    <select
                        v-model="status"
                        name="status"
                        :class="controlClasses"
                        @change="refresh"
                    >
                        <option value="">{{ t('users.status.all') }}</option>
                        <option
                            v-for="value in statuses"
                            :key="value"
                            :value="value"
                        >
                            {{ t(`users.status.${value}`) }}
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
                        <Spinner />{{ t('users.loading') }}
                    </span>
                    <Button
                        v-else-if="filtered"
                        variant="muted"
                        tier="ghost"
                        @click="clearFilters"
                        >{{ t('users.clearFilters') }}</Button
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
                <table v-if="users.length" class="w-full text-left text-sm">
                    <thead
                        class="hidden bg-(--concierge-surface-muted) text-xs font-medium tracking-wide text-(--concierge-text-muted) uppercase md:table-header-group"
                    >
                        <tr>
                            <th scope="col" class="px-6 py-2.5">
                                {{ t('users.column.user') }}
                            </th>
                            <th scope="col" class="px-3 py-2.5">
                                {{ t('users.column.roles') }}
                            </th>
                            <th scope="col" class="px-3 py-2.5">
                                {{ t('users.column.status') }}
                            </th>
                            <th scope="col" class="px-3 py-2.5">
                                {{ t('users.column.joined') }}
                            </th>
                            <th
                                v-for="column in metadata.columns"
                                :key="column.key"
                                scope="col"
                                class="px-3 py-2.5"
                            >
                                {{ column.label }}
                            </th>
                            <th scope="col" class="px-6 py-2.5 text-right">
                                <span class="sr-only">{{
                                    t('users.column.actions')
                                }}</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-(--concierge-border)">
                        <tr
                            v-for="row in users"
                            :key="row.id"
                            class="flex flex-wrap items-center gap-x-3 gap-y-2 px-4 py-4 md:table-row md:px-0 md:py-0"
                        >
                            <td class="w-full md:w-auto md:px-6 md:py-3">
                                <div class="flex items-center gap-3">
                                    <Avatar
                                        :avatar="row.user.avatar"
                                        size="sm"
                                    />
                                    <div class="min-w-0">
                                        <div
                                            class="flex flex-wrap items-center gap-2"
                                        >
                                            <a
                                                v-if="row.links.show"
                                                :href="row.links.show"
                                                class="font-medium text-(--concierge-text) underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-(--concierge-focus)"
                                                @click="
                                                    open(row.links.show, $event)
                                                "
                                                >{{ row.user.name }}</a
                                            >
                                            <span
                                                v-else
                                                class="font-medium text-(--concierge-text)"
                                                >{{ row.user.name }}</span
                                            >
                                            <Badge
                                                v-if="row.isSelf"
                                                :label="t('users.you')"
                                                variant="info"
                                            />
                                        </div>
                                        <div
                                            class="text-(--concierge-text-muted)"
                                        >
                                            {{ row.user.email }}
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td class="md:px-3 md:py-3">
                                <div class="flex flex-wrap gap-1.5">
                                    <Badge
                                        v-if="row.isOperator"
                                        :label="t('users.operator')"
                                        variant="warning"
                                    />
                                    <Badge
                                        v-if="row.isOwner"
                                        :label="t('users.owner')"
                                        variant="primary-solid"
                                    />
                                    <Badge
                                        v-for="badge in row.roles"
                                        :key="badge.value"
                                        :label="badge.label"
                                        :variant="badge.variant"
                                    />
                                    <span
                                        v-if="!row.roles.length && !row.isOwner"
                                        class="text-(--concierge-text-muted)"
                                        >{{ t('users.noRoles') }}</span
                                    >
                                </div>
                            </td>
                            <td class="md:px-3 md:py-3">
                                <Badge
                                    :label="t(`users.status.${row.status}`)"
                                    :variant="statusVariant(row)"
                                />
                            </td>
                            <td
                                class="text-sm text-(--concierge-text-muted) md:px-3 md:py-3"
                            >
                                <time
                                    v-if="row.joinedAt"
                                    :datetime="row.joinedAt"
                                    >{{
                                        formatDate(row.joinedAt, locale)
                                    }}</time
                                >
                            </td>
                            <td
                                v-for="column in metadata.columns"
                                :key="column.key"
                                :data-column="column.key"
                                class="text-sm text-(--concierge-text-muted) md:px-3 md:py-3"
                            >
                                <slot
                                    name="column"
                                    :column="column"
                                    :user="row"
                                    :value="row.columns[column.key] ?? null"
                                    >{{ row.columns[column.key] ?? '' }}</slot
                                >
                            </td>
                            <td
                                class="w-full empty:hidden md:w-auto md:px-6 md:py-3"
                            >
                                <div
                                    v-if="row.hasActions"
                                    class="flex flex-wrap gap-2 md:justify-end"
                                >
                                    <UserActions :user="row" />
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <EmptyState
                    v-else-if="filtered"
                    :title="t('users.noResults.title')"
                >
                    {{ t('users.noResults.body') }}
                    <template #actions>
                        <Button
                            variant="muted"
                            tier="outline"
                            @click="clearFilters"
                            >{{ t('users.clearFilters') }}</Button
                        >
                    </template>
                </EmptyState>
                <slot v-else name="empty">
                    <EmptyState :title="t('users.empty.title')">
                        {{ t('users.empty.body') }}
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

        <slot name="aside" />
    </div>
</template>
