/**
 * Literal class maps per avatar colour. The server picks the name from the
 * user's key, so a person keeps their colour everywhere; hosts restyle a
 * colour by overriding its --concierge-avatar-* variables. Unknown names
 * fall back to slate.
 */
export const avatarColours = [
    'slate',
    'indigo',
    'violet',
    'rose',
    'amber',
    'emerald',
    'teal',
    'sky',
] as const;

export type AvatarColour = (typeof avatarColours)[number];

const classes: Record<AvatarColour, string> = {
    slate: 'bg-(--concierge-avatar-slate) text-(--concierge-avatar-slate-fg)',
    indigo: 'bg-(--concierge-avatar-indigo) text-(--concierge-avatar-indigo-fg)',
    violet: 'bg-(--concierge-avatar-violet) text-(--concierge-avatar-violet-fg)',
    rose: 'bg-(--concierge-avatar-rose) text-(--concierge-avatar-rose-fg)',
    amber: 'bg-(--concierge-avatar-amber) text-(--concierge-avatar-amber-fg)',
    emerald:
        'bg-(--concierge-avatar-emerald) text-(--concierge-avatar-emerald-fg)',
    teal: 'bg-(--concierge-avatar-teal) text-(--concierge-avatar-teal-fg)',
    sky: 'bg-(--concierge-avatar-sky) text-(--concierge-avatar-sky-fg)',
};

export function avatarClasses(colour: string): string {
    return (avatarColours as readonly string[]).includes(colour)
        ? classes[colour as AvatarColour]
        : classes.slate;
}
