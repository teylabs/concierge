import { expect, test } from '@playwright/test';
import { openHarness, requests, visits } from './support';

test('sends email and chosen roles to the server-provided form URL', async ({
    page,
}) => {
    await openHarness(page, 'inviteUser');

    await expect(
        page.getByRole('heading', { name: 'Invite user', level: 1 }),
    ).toBeVisible();
    await expect(page.locator('.concierge')).not.toContainText(/workspace/i);
    await page.getByLabel('Email address').fill('linus@example.com');
    await page.getByRole('checkbox', { name: /Presenter/ }).check();
    await page.getByRole('button', { name: 'Send invitation' }).click();

    await expect
        .poll(() => requests(page))
        .toEqual([
            {
                method: 'post',
                url: '/users/invitations',
                data: { email: 'linus@example.com', roles: ['presenter'] },
            },
        ]);
});

test('shows field errors and focuses the email field', async ({ page }) => {
    await openHarness(page, 'inviteUser');
    await page.evaluate(() =>
        window.concierge.respond({
            ok: false,
            errors: { email: 'That person already has an account.' },
        }),
    );

    await page.getByLabel('Email address').fill('ada@example.com');
    await page.getByRole('checkbox', { name: /Admin/ }).check();
    await page.getByRole('button', { name: 'Send invitation' }).click();

    const email = page.getByLabel('Email address');
    await expect(email).toHaveAttribute('aria-invalid', 'true');
    await expect(email).toHaveAccessibleDescription(
        'That person already has an account.',
    );
    await expect(email).toBeFocused();
});

test('disables the form when no role can be granted', async ({ page }) => {
    await openHarness(page, 'inviteUser', 'noGrantableRoles');

    await expect(
        page.getByText('You are not allowed to grant any roles.'),
    ).toBeVisible();
    await expect(page.getByLabel('Email address')).toBeDisabled();
    await expect(
        page.getByRole('button', { name: 'Send invitation' }),
    ).toBeDisabled();
});

test('cancels through the server-provided link', async ({ page }) => {
    await openHarness(page, 'inviteUser');

    await page.getByRole('link', { name: 'Cancel' }).click();

    await expect
        .poll(() => visits(page))
        .toEqual([expect.objectContaining({ url: '/users' })]);
});
