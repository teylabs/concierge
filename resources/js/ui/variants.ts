/**
 * Literal class maps per semantic variant. Class strings stay literal so
 * Tailwind can see them; never build them at runtime. Unknown variants fall
 * back to muted.
 */
export const variantNames = [
    'primary',
    'success',
    'warning',
    'danger',
    'info',
    'muted',
] as const;

export type Variant = (typeof variantNames)[number];
export type Tier = 'solid' | 'pale' | 'outline' | 'ghost';

export const solid: Record<Variant, string> = {
    primary:
        'bg-(--concierge-primary-solid) text-(--concierge-primary-solid-fg) hover:bg-(--concierge-primary-solid-hover)',
    success:
        'bg-(--concierge-success-solid) text-(--concierge-success-solid-fg) hover:bg-(--concierge-success-solid-hover)',
    warning:
        'bg-(--concierge-warning-solid) text-(--concierge-warning-solid-fg) hover:bg-(--concierge-warning-solid-hover)',
    danger: 'bg-(--concierge-danger-solid) text-(--concierge-danger-solid-fg) hover:bg-(--concierge-danger-solid-hover)',
    info: 'bg-(--concierge-info-solid) text-(--concierge-info-solid-fg) hover:bg-(--concierge-info-solid-hover)',
    muted: 'bg-(--concierge-muted-solid) text-(--concierge-muted-solid-fg) hover:bg-(--concierge-muted-solid-hover)',
};

export const pale: Record<Variant, string> = {
    primary:
        'bg-(--concierge-primary-pale) text-(--concierge-primary-pale-fg) ring-(--concierge-primary-outline)',
    success:
        'bg-(--concierge-success-pale) text-(--concierge-success-pale-fg) ring-(--concierge-success-outline)',
    warning:
        'bg-(--concierge-warning-pale) text-(--concierge-warning-pale-fg) ring-(--concierge-warning-outline)',
    danger: 'bg-(--concierge-danger-pale) text-(--concierge-danger-pale-fg) ring-(--concierge-danger-outline)',
    info: 'bg-(--concierge-info-pale) text-(--concierge-info-pale-fg) ring-(--concierge-info-outline)',
    muted: 'bg-(--concierge-muted-pale) text-(--concierge-muted-pale-fg) ring-(--concierge-muted-outline)',
};

export const outline: Record<Variant, string> = {
    primary:
        'bg-(--concierge-surface) text-(--concierge-primary-pale-fg) ring-(--concierge-primary-outline) hover:bg-(--concierge-primary-pale)',
    success:
        'bg-(--concierge-surface) text-(--concierge-success-pale-fg) ring-(--concierge-success-outline) hover:bg-(--concierge-success-pale)',
    warning:
        'bg-(--concierge-surface) text-(--concierge-warning-pale-fg) ring-(--concierge-warning-outline) hover:bg-(--concierge-warning-pale)',
    danger: 'bg-(--concierge-surface) text-(--concierge-danger-pale-fg) ring-(--concierge-danger-outline) hover:bg-(--concierge-danger-pale)',
    info: 'bg-(--concierge-surface) text-(--concierge-info-pale-fg) ring-(--concierge-info-outline) hover:bg-(--concierge-info-pale)',
    muted: 'bg-(--concierge-surface) text-(--concierge-text) ring-(--concierge-border-strong) hover:bg-(--concierge-surface-muted)',
};

export const ghost: Record<Variant, string> = {
    primary:
        'text-(--concierge-primary-solid) hover:bg-(--concierge-primary-pale)',
    success:
        'text-(--concierge-success-solid) hover:bg-(--concierge-success-pale)',
    warning:
        'text-(--concierge-warning-solid) hover:bg-(--concierge-warning-pale)',
    danger: 'text-(--concierge-danger-solid) hover:bg-(--concierge-danger-pale)',
    info: 'text-(--concierge-info-solid) hover:bg-(--concierge-info-pale)',
    muted: 'text-(--concierge-text-muted) hover:bg-(--concierge-muted-pale)',
};

const solidBadge: Record<Variant, string> = {
    primary:
        'bg-(--concierge-primary-solid) text-(--concierge-primary-solid-fg) ring-transparent',
    success:
        'bg-(--concierge-success-solid) text-(--concierge-success-solid-fg) ring-transparent',
    warning:
        'bg-(--concierge-warning-solid) text-(--concierge-warning-solid-fg) ring-transparent',
    danger: 'bg-(--concierge-danger-solid) text-(--concierge-danger-solid-fg) ring-transparent',
    info: 'bg-(--concierge-info-solid) text-(--concierge-info-solid-fg) ring-transparent',
    muted: 'bg-(--concierge-muted-solid) text-(--concierge-muted-solid-fg) ring-transparent',
};

const aliases: Record<string, Variant> = {
    secondary: 'muted',
    light: 'muted',
    gray: 'muted',
    dark: 'muted',
    brand: 'primary',
    accent: 'info',
};

export function normalizeVariant(value: string | null | undefined): Variant {
    if (!value) {
        return 'muted';
    }

    return (variantNames as readonly string[]).includes(value)
        ? (value as Variant)
        : (aliases[value] ?? 'muted');
}

/**
 * Badge variants from the server may carry a tier suffix:
 * "danger", "danger-solid", "danger-outline".
 */
export function badgeClasses(value: string | null | undefined): string {
    const [base, suffix] = splitTier(value ?? '');
    const variant = normalizeVariant(base);

    if (suffix === 'solid') {
        return solidBadge[variant];
    }

    if (suffix === 'outline') {
        return outline[variant];
    }

    return pale[variant];
}

function splitTier(value: string): [string, 'solid' | 'outline' | null] {
    if (value.endsWith('-solid')) {
        return [value.slice(0, -6), 'solid'];
    }

    if (value.endsWith('-outline')) {
        return [value.slice(0, -8), 'outline'];
    }

    return [value, null];
}
