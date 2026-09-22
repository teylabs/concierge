import { router } from '@inertiajs/vue3';
import {
    failureFromStatus,
    type ConciergeAdapter,
    type ConciergeSubmitResult,
} from './adapter';

/**
 * Adapter for hosts rendering Concierge pages through Inertia. This is the only
 * module that imports @inertiajs/vue3.
 */
export function createInertiaAdapter(): ConciergeAdapter {
    return {
        visit(url, options = {}) {
            return new Promise((resolve) => {
                router.get(url, (options.data ?? {}) as never, {
                    preserveScroll: options.preserveScroll ?? false,
                    preserveState: options.preserveState ?? false,
                    replace: options.replace ?? false,
                    onFinish: () => resolve(),
                });
            });
        },

        submit({ method, url, data }) {
            return new Promise<ConciergeSubmitResult>((resolve) => {
                let result: ConciergeSubmitResult | null = null;

                router.visit(url, {
                    method,
                    data: (data ?? {}) as never,
                    preserveScroll: true,
                    onSuccess: () => {
                        result = { ok: true };
                    },
                    onError: (errors) => {
                        result = { ok: false, errors: flattenErrors(errors) };
                    },
                    onHttpException: (response) => {
                        result = {
                            ok: false,
                            failure: failureFromStatus(response.status),
                        };

                        return false;
                    },
                    onNetworkError: () => {
                        result = { ok: false, failure: 'network' };

                        return false;
                    },
                    onFinish: () => {
                        resolve(result ?? { ok: false, failure: 'server' });
                    },
                });
            });
        },
    };
}

function flattenErrors(
    errors: Record<string, unknown>,
): Record<string, string> {
    return Object.fromEntries(
        Object.entries(errors).map(([key, value]) => [
            key,
            Array.isArray(value) ? value.join(' ') : String(value),
        ]),
    );
}
