<script setup lang="ts">
/**
 * Role picker: one checkbox card per server-provided option. Adapted from
 * cookbook UI/Form/OptionsCheckbox with its label/description slot pattern.
 * No direct permission editing in the pilot.
 */
import type { RoleOption } from '../types';

const model = defineModel<string[]>({ required: true });

defineProps<{
    options: RoleOption[];
    name: string;
    describedBy?: string;
    invalid?: boolean;
    disabled?: boolean;
}>();
</script>

<template>
    <div class="grid gap-2 sm:grid-cols-2" :aria-describedby="describedBy">
        <label
            v-for="option in options"
            :key="option.value"
            :class="[
                'flex cursor-pointer items-start gap-3 rounded-md bg-(--concierge-surface) p-3 ring-1 transition-colors ring-inset has-checked:bg-(--concierge-primary-pale) has-checked:ring-(--concierge-primary-outline) has-focus-visible:ring-2 has-focus-visible:ring-(--concierge-focus)',
                invalid
                    ? 'ring-(--concierge-danger-outline)'
                    : 'ring-(--concierge-border)',
                disabled ? 'cursor-not-allowed opacity-60' : '',
            ]"
        >
            <input
                v-model="model"
                type="checkbox"
                :name="`${name}[]`"
                :value="option.value"
                :disabled="disabled"
                class="mt-0.5 size-4 shrink-0 accent-(--concierge-primary-solid) focus:outline-none"
            />
            <span class="min-w-0">
                <span
                    class="block text-sm font-medium text-(--concierge-text)"
                    >{{ option.label }}</span
                >
                <span
                    v-if="option.description"
                    class="mt-0.5 block text-sm text-(--concierge-text-muted)"
                    >{{ option.description }}</span
                >
            </span>
        </label>
    </div>
</template>
