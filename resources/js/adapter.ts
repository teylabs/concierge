/**
 * The only way Concierge UI talks to the host. Every URL passed in comes from
 * server-provided props; the UI never builds application routes.
 */
export type ConciergeMethod = 'post' | 'put' | 'delete';

export type ConciergeVisitOptions = {
    data?: Record<string, unknown>;
    preserveScroll?: boolean;
    preserveState?: boolean;
    replace?: boolean;
};

export type ConciergeSubmitRequest = {
    method: ConciergeMethod;
    url: string;
    data?: Record<string, unknown>;
};

export type ConciergeFailure = 'network' | 'server' | 'forbidden' | 'expired';

export type ConciergeSubmitResult =
    | { ok: true }
    | { ok: false; errors: Record<string, string> }
    | { ok: false; failure: ConciergeFailure };

export type ConciergeAdapter = {
    /** GET navigation. Resolves once the visit settles, successfully or not. */
    visit(url: string, options?: ConciergeVisitOptions): Promise<void>;
    /** Mutation. Validation errors and transport failures resolve, never reject. */
    submit(request: ConciergeSubmitRequest): Promise<ConciergeSubmitResult>;
};

export function failureFromStatus(status: number): ConciergeFailure {
    if (status === 403) {
        return 'forbidden';
    }

    if (status === 419) {
        return 'expired';
    }

    return 'server';
}
