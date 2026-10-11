<script setup lang="ts">
/**
 * A person's photo when the app supplies one, otherwise their initials on
 * the colour the server chose for them. A photo that fails to load falls
 * back to the initials. Decorative beside the person's name; pass `label`
 * when the avatar stands on its own.
 */
import { computed, ref, watch } from 'vue';
import type { UserAvatar } from '../types';
import { avatarClasses } from './avatarColours';

const props = withDefaults(
    defineProps<{
        avatar: UserAvatar;
        label?: string | null;
        size?: 'sm' | 'md' | 'lg';
    }>(),
    { label: null, size: 'md' },
);

const failed = ref(false);

watch(
    () => props.avatar.url,
    () => {
        failed.value = false;
    },
);

const photo = computed(() =>
    props.avatar.url && !failed.value ? props.avatar.url : null,
);

const sizes = {
    sm: 'size-8 text-xs',
    md: 'size-10 text-sm',
    lg: 'size-12 text-base',
};
</script>

<template>
    <span
        data-concierge-avatar
        :role="label ? 'img' : undefined"
        :aria-label="label ?? undefined"
        :aria-hidden="label ? undefined : 'true'"
        :class="[
            'inline-flex shrink-0 items-center justify-center overflow-hidden rounded-full font-medium select-none',
            sizes[size],
            photo
                ? 'bg-(--concierge-surface-muted) ring-1 ring-(--concierge-border) ring-inset'
                : avatarClasses(avatar.colour),
        ]"
    >
        <img
            v-if="photo"
            :src="photo"
            alt=""
            class="size-full object-cover"
            @error="failed = true"
        />
        <span v-else>{{ avatar.initials }}</span>
    </span>
</template>
