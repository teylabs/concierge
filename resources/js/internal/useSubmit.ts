import { ref } from 'vue';
import type { ConciergeSubmitRequest, ConciergeSubmitResult } from '../adapter';
import { useConcierge } from '../plugin';

/**
 * Pending/error state for one mutation. A second submit while one is pending
 * is ignored, so double clicks never send duplicate requests. retry() resends
 * the last request unchanged; the server's idempotency decides the outcome.
 */
export function useSubmit() {
    const { adapter, t } = useConcierge();

    const pending = ref(false);
    const errors = ref<Record<string, string>>({});
    const failure = ref<string | null>(null);
    let last: ConciergeSubmitRequest | null = null;

    async function submit(
        request: ConciergeSubmitRequest,
    ): Promise<ConciergeSubmitResult | null> {
        if (pending.value) {
            return null;
        }

        pending.value = true;
        errors.value = {};
        failure.value = null;
        last = request;

        try {
            const result = await adapter.submit(request);

            if (!result.ok) {
                if ('errors' in result) {
                    errors.value = result.errors;
                    failure.value = firstNonFieldError(result.errors);
                } else {
                    failure.value = t(`errors.${result.failure}`);
                }
            }

            return result;
        } catch {
            failure.value = t('errors.network');

            return { ok: false, failure: 'network' };
        } finally {
            pending.value = false;
        }
    }

    function retry() {
        return last ? submit(last) : Promise.resolve(null);
    }

    function reset() {
        errors.value = {};
        failure.value = null;
    }

    return { pending, errors, failure, submit, retry, reset };
}

/**
 * Validation errors for fields the form does not render (for example a
 * policy denial keyed "membership") would otherwise be invisible.
 */
const fieldKeys = /^(email|roles)(\.\d+)?$/;

function firstNonFieldError(errors: Record<string, string>): string | null {
    const entry = Object.entries(errors).find(([key]) => !fieldKeys.test(key));

    return entry ? entry[1] : null;
}
