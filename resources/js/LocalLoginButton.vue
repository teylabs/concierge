<script setup lang="ts">
/**
 * One-click local sign-in. A plain HTML form POST to the server-supplied URL, so it
 * needs neither the Concierge plugin nor Inertia: the host passes the offer it got
 * from the server and a CSRF token it obtained from its own session. Renders nothing
 * when there is no offer (every environment except an enabled, configured `local`).
 *
 * The form carries the token and nothing else: the server signs in as the account in
 * its own configuration and ignores anything submitted.
 */
import { onBeforeUnmount, onMounted, ref } from 'vue';
import type { LocalLoginOffer } from './types';
import Button from './ui/Button.vue';

const props = withDefaults(
    defineProps<{
        offer: LocalLoginOffer | null | undefined;
        csrfToken: string;
        label?: string | null;
        hint?: string;
    }>(),
    {
        label: null,
        hint: 'Local development only. This skips the password and two-factor.',
    },
);

const busy = ref(false);

function onSubmit(event: SubmitEvent) {
    // A second click before the navigation lands must not post twice.
    if (busy.value) {
        event.preventDefault();

        return;
    }

    busy.value = true;
}

// Returning through the back/forward cache restores the page as it was left, busy.
function onPageShow(event: PageTransitionEvent) {
    if (event.persisted) {
        busy.value = false;
    }
}

onMounted(() => window.addEventListener('pageshow', onPageShow));
onBeforeUnmount(() => window.removeEventListener('pageshow', onPageShow));
</script>

<template>
    <form
        v-if="props.offer"
        method="post"
        :action="props.offer.url"
        data-concierge="local-login"
        class="concierge flex flex-col gap-2 font-(family-name:--concierge-font)"
        @submit="onSubmit"
    >
        <input type="hidden" name="_token" :value="props.csrfToken" />
        <Button type="submit" variant="muted" tier="outline" :busy="busy">
            {{ props.label ?? `Sign in as ${props.offer.email}` }}
        </Button>
        <p class="text-xs text-(--concierge-text-muted)">{{ props.hint }}</p>
    </form>
</template>
