import { expect, test } from '@playwright/test';
import { openHarness, requests, visits } from './support';

const statesWithoutAccept = [
    'sign_in_required',
    'register_required',
    'wrong_account',
    'unverified',
    'expired',
    'revoked',
    'invalid',
    'accepted',
    'already_member',
    'suspended',
    'authority_lapsed',
    'workspace_unavailable',
    'grants_changed',
];

test('accepts a ready invitation with an explicit POST', async ({ page }) => {
    await openHarness(page, 'accept', 'ready');

    await expect(
        page.getByRole('heading', { name: 'Join Teylabs' }),
    ).toBeVisible();
    await expect(
        page.getByText('This invitation is for linus@example.com.'),
    ).toBeVisible();
    await page.getByRole('button', { name: 'Accept invitation' }).click();

    expect(await requests(page)).toEqual([
        { method: 'post', url: '/invitations/opaque-token/accept' },
    ]);
});

test('retries acceptance after a failure without duplicating while pending', async ({
    page,
}) => {
    await openHarness(page, 'accept', 'ready');
    await page.evaluate(() =>
        window.concierge.respond({ ok: false, failure: 'expired' }),
    );

    const accept = page.getByRole('button', { name: 'Accept invitation' });
    await accept.click();
    await expect(page.getByRole('alert')).toHaveText(
        'Your session expired. Reload the page and try again.',
    );

    await page.evaluate(() => window.concierge.hold());
    await page.getByRole('button', { name: 'Try again' }).click();
    await expect(accept).toBeDisabled();
    await expect(accept).toHaveAttribute('aria-busy', 'true');
    await accept.click({ force: true });
    await page.evaluate(() => window.concierge.release());

    await expect(accept).toBeEnabled();
    expect(await requests(page)).toHaveLength(2);
});

for (const state of statesWithoutAccept) {
    test(`offers no accept action in the ${state} state`, async ({ page }) => {
        await openHarness(page, 'accept', state);

        await expect(page.locator(`[data-state="${state}"]`)).toBeVisible();
        await expect(
            page.getByRole('button', { name: 'Accept invitation' }),
        ).toHaveCount(0);
    });
}

test('describes workspace_unavailable and grants_changed without leaking grants', async ({
    page,
}) => {
    await openHarness(page, 'accept', 'grants_changed');
    await expect(
        page.getByText('Ask a workspace admin to send a new invitation.'),
    ).toBeVisible();
    await expect(page.getByText('You will join with these roles:')).toHaveCount(
        0,
    );

    await openHarness(page, 'accept', 'workspace_unavailable');
    await expect(
        page.getByText('Teylabs is not accepting new members right now.'),
    ).toBeVisible();
});

test('routes sign in and registration through host links', async ({ page }) => {
    await openHarness(page, 'accept', 'sign_in_required');

    await page.getByRole('link', { name: 'Sign in' }).click();
    await page.getByRole('link', { name: 'Create account' }).click();

    expect((await visits(page)).map((visit) => visit.url)).toEqual([
        '/login',
        '/register',
    ]);
});

test('signs out of the wrong account with POST', async ({ page }) => {
    await openHarness(page, 'accept', 'wrong_account');

    await expect(page.getByText('different account')).toBeVisible();
    await page.getByRole('button', { name: 'Sign out' }).click();

    expect(await requests(page)).toEqual([{ method: 'post', url: '/logout' }]);
});

test('reveals nothing about an invalid invitation', async ({ page }) => {
    await openHarness(page, 'accept', 'invalid');

    await expect(
        page.getByRole('heading', { name: 'Invitation' }),
    ).toBeVisible();
    await expect(page.locator('.concierge')).not.toContainText('@');
});

test.describe('action order', () => {
    const cases: [string, string[]][] = [
        ['ready', ['Accept invitation']],
        ['sign_in_required', ['Sign in', 'Create account']],
        ['register_required', ['Create account', 'Sign in']],
        ['unverified', ['Continue', 'Sign out']],
        ['wrong_account', ['Sign out']],
    ];

    for (const [state, expected] of cases) {
        test(`puts the primary action first in the ${state} state`, async ({
            page,
        }) => {
            await openHarness(page, 'accept', state);
            const actions = page
                .getByTestId('accept-actions')
                .locator('a, button');

            await expect(actions).toHaveText(expected);
            // The first action is the only solid primary one.
            await expect(actions.first()).toHaveClass(
                /concierge-primary-solid\)/,
            );
            for (const later of await actions
                .all()
                .then((all) => all.slice(1))) {
                await expect(later).not.toHaveClass(
                    /bg-\(--concierge-primary-solid\)/,
                );
            }
            // Tab order follows visual order.
            await page.keyboard.press('Tab');
            await expect(actions.first()).toBeFocused();
        });
    }
});

test('names the app when an app-wide invitation has no workspace', async ({
    page,
}) => {
    await openHarness(page, 'acceptApp', 'ready');

    await expect(
        page.getByRole('heading', { name: 'Join Teylabs' }),
    ).toBeVisible();
    await expect(
        page.getByText('Accept the invitation to join Teylabs.'),
    ).toBeVisible();
    await page.getByRole('button', { name: 'Accept invitation' }).click();

    expect(await requests(page)).toEqual([
        { method: 'post', url: '/invitations/opaque-token/accept' },
    ]);
});

test('asks an admin, not a workspace admin, for a new app-wide invitation', async ({
    page,
}) => {
    await openHarness(page, 'acceptApp', 'expired');

    await expect(
        page.getByText('Ask an admin to send a new one.'),
    ).toBeVisible();
    await expect(page.locator('.concierge')).not.toContainText(/workspace/i);
});

test('keeps the plain title for an unknown app-wide invitation', async ({
    page,
}) => {
    await openHarness(page, 'acceptApp', 'invalid');

    await expect(
        page.getByRole('heading', { name: 'Invitation' }),
    ).toBeVisible();
});
