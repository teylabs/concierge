import { inject, type App, type InjectionKey } from 'vue';
import type { ConciergeAdapter } from './adapter';
import {
    translate,
    type ConciergeLabelKey,
    type ConciergeLabels,
} from './labels';

export type ConciergeOptions = {
    adapter: ConciergeAdapter;
    labels?: ConciergeLabels;
    /** BCP 47 locale for dates and numbers. Defaults to the browser locale. */
    locale?: string;
};

export type ConciergeContext = {
    adapter: ConciergeAdapter;
    t: (
        key: ConciergeLabelKey,
        params?: Record<string, string | number>,
    ) => string;
    locale: string | undefined;
};

export const conciergeKey: InjectionKey<ConciergeContext> = Symbol('concierge');

export function createConcierge(options: ConciergeOptions) {
    const context: ConciergeContext = {
        adapter: options.adapter,
        t: (key, params) => translate(options.labels ?? {}, key, params),
        locale: options.locale,
    };

    return {
        install(app: App) {
            app.provide(conciergeKey, context);
        },
    };
}

export function useConcierge(): ConciergeContext {
    const context = inject(conciergeKey, null);

    if (context === null) {
        throw new Error(
            'Concierge is not installed. Call app.use(createConcierge({ adapter })) before rendering Concierge pages.',
        );
    }

    return context;
}
