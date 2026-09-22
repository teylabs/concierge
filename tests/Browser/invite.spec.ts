import { expect, test } from '@playwright/test';
import { openHarness, requests, visits } from './support';

test('sends email and chosen roles to the server-provided form URL', async ({
    page,
}) => {
    await openHarness(page, 'invite');

    await page.getByLabel('Email address').fill('linus@example.com');
    await page.getByRole('checkbox', { name: /Presenter/ }).check();
    await page.getByRole('button', { name: 'Send invitation' }).click();

    await expect
        .poll(() => requests(page))
        .toEqual([
            {
                method: 'post',
                url: '/workspaces/7/invitations',
                data: { email: 'linus@example.com', roles: ['presenter'] },
            },
        ]);
});

test('shows field errors accessibly and focuses the email field', async ({
    page,
}) => {
    await openHarness(page, 'invite');
    await page.evaluate(() =>
        window.concierge.respond({
            ok: false,
            errors: {
                email: 'linus@example.com has already been invited.',
                'roles.0': 'The selected role cannot be granted.',
            },
        }),
    );

    await page.getByLabel('Email address').fill('linus@example.com');
    await page.getByRole('checkbox', { name: /Admin/ }).check();
    await page.getByRole('button', { name: 'Send invitation' }).click();

    const email = page.getByLabel('Email address');
    await expect(email).toHaveAttribute('aria-invalid', 'true');
    await expect(email).toHaveAccessibleDescription(
        'linus@example.com has already been invited.',
    );
    await expect(email).toBeFocused();
    await expect(
        page.getByText('The selected role cannot be granted.'),
    ).toBeVisible();
    await expect(page.getByRole('button', { name: 'Try again' })).toHaveCount(
        0,
    );
});

test('sends one request for repeated submits while pending', async ({
    page,
}) => {
    await openHarness(page, 'invite');
    await page.evaluate(() => window.concierge.hold());

    await page.getByLabel('Email address').fill('linus@example.com');
    await page.getByRole('checkbox', { name: /Presenter/ }).check();
    const submit = page.getByRole('button', { name: 'Send invitation' });
    await submit.click();
    await expect(submit).toBeDisabled();
    await page.getByLabel('Email address').press('Enter');
    await page.evaluate(() => window.concierge.release());

    await expect(submit).toBeEnabled();
    expect(await requests(page)).toHaveLength(1);
});

test('retries the identical request after a transport failure', async ({
    page,
}) => {
    await openHarness(page, 'invite');
    await page.evaluate(() =>
        window.concierge.respond(
            { ok: false, failure: 'network' },
            { ok: true },
        ),
    );

    await page.getByLabel('Email address').fill('linus@example.com');
    await page.getByRole('checkbox', { name: /Presenter/ }).check();
    await page.getByRole('button', { name: 'Send invitation' }).click();
    await expect(page.getByRole('alert')).toContainText(
        'could not reach the server',
    );
    await page.getByRole('button', { name: 'Try again' }).click();

    await expect(page.getByRole('alert')).toHaveCount(0);
    const sent = await requests(page);
    expect(sent).toHaveLength(2);
    expect(sent[1]).toEqual(sent[0]);
});

test('supports keyboard-only role selection and submission', async ({
    page,
}) => {
    await openHarness(page, 'invite');

    await page.getByLabel('Email address').fill('linus@example.com');
    await page.keyboard.press('Tab');
    await page.keyboard.press('Space');
    await page.keyboard.press('Tab');
    await page.keyboard.press('Tab');
    await page.keyboard.press('Tab');
    await expect(
        page.getByRole('button', { name: 'Send invitation' }),
    ).toBeFocused();
    await page.keyboard.press('Enter');

    await expect
        .poll(() => requests(page))
        .toEqual([
            expect.objectContaining({
                data: { email: 'linus@example.com', roles: ['admin'] },
            }),
        ]);
});

test('cancels to the server-provided URL', async ({ page }) => {
    await openHarness(page, 'invite');

    await page.getByRole('link', { name: 'Cancel' }).click();

    await expect
        .poll(() => visits(page))
        .toEqual([expect.objectContaining({ url: '/workspaces/7/members' })]);
});

test('disables inviting when no roles are grantable', async ({ page }) => {
    await openHarness(page, 'invite', 'noGrantableRoles');

    await expect(
        page.getByText('You are not allowed to grant any roles'),
    ).toBeVisible();
    await expect(
        page.getByRole('button', { name: 'Send invitation' }),
    ).toBeDisabled();
    await expect(page.getByRole('checkbox')).toHaveCount(0);
});
