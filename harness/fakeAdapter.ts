import type {
    ConciergeAdapter,
    ConciergeSubmitRequest,
    ConciergeSubmitResult,
    ConciergeVisitOptions,
} from '../resources/js/adapter';

export type RecordedVisit = { url: string; options: ConciergeVisitOptions };

type Scripted = ConciergeSubmitResult | 'throw';

/**
 * Scripted stand-in for the host adapter. Tests queue responses and can hold
 * a request open to observe pending state. Every call is recorded so tests
 * assert exactly what the UI would have sent to the server.
 */
export function createFakeAdapter() {
    const requests: ConciergeSubmitRequest[] = [];
    const visits: RecordedVisit[] = [];
    const queue: Scripted[] = [];
    const held: (() => void)[] = [];
    let holding = false;

    const adapter: ConciergeAdapter = {
        async visit(url, options = {}) {
            visits.push({ url, options: JSON.parse(JSON.stringify(options)) });
        },
        async submit(request) {
            requests.push(JSON.parse(JSON.stringify(request)));

            if (holding) {
                await new Promise<void>((resolve) => held.push(resolve));
            }

            const next = queue.shift() ?? { ok: true };

            if (next === 'throw') {
                throw new Error('Scripted transport failure');
            }

            return next;
        },
    };

    const control = {
        requests,
        visits,
        respond(...results: Scripted[]) {
            queue.push(...results);
        },
        hold() {
            holding = true;
        },
        release() {
            holding = false;
            held.splice(0).forEach((resolve) => resolve());
        },
    };

    return { adapter, control };
}
