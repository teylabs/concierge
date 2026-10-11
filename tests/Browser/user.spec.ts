import { expect, test } from '@playwright/test';
import { openHarness, requests, visits } from './support';

test('saves a changed name and email with PUT to the profile form URL', async ({
    page,
}) => {
    await openHarness(page, 'user');
    const save = page.getByRole('button', { name: 'Save profile' });

    await expect(save).toBeDisabled();
    await page.getByLabel('Name').fill('Ada King');
    await page.getByLabel('Email').fill('ada.king@example.com');
    await save.click();

    await expect
        .poll(() => requests(page))
        .toEqual([
            {
                method: 'put',
                url: '/users/11',
                data: { name: 'Ada King', email: 'ada.king@example.com' },
            },
        ]);
});

test('shows rejected profile fields accessibly', async ({ page }) => {
    await openHarness(page, 'user');
    await page.evaluate(() =>
        window.concierge.respond({
            ok: false,
            errors: { email: 'That email address is already taken.' },
        }),
    );

    await page.getByLabel('Email').fill('grace@example.com');
    await page.getByRole('button', { name: 'Save profile' }).click();

    const email = page.getByLabel('Email');
    await expect(email).toHaveAttribute('aria-invalid', 'true');
    await expect(email).toHaveAccessibleDescription(
        'That email address is already taken.',
    );
});

test('confirms a saved profile once the server returns it', async ({
    page,
}) => {
    await openHarness(page, 'user');

    await page.getByLabel('Name').fill('Ada King');
    await page.getByRole('button', { name: 'Save profile' }).click();
    await page.evaluate(() =>
        window.concierge.setProps({
            profile: {
                payload: { name: 'Ada King', email: 'ada@example.com' },
                metadata: { formUrl: '/users/11' },
            },
        }),
    );

    await expect(page.getByRole('status')).toHaveText('Profile saved.');
});

test('saves changed roles with PUT to the grants form URL', async ({
    page,
}) => {
    await openHarness(page, 'user');
    const save = page.getByRole('button', { name: 'Save roles' });

    await expect(page.getByRole('checkbox', { name: /Admin/ })).toBeChecked();
    await expect(save).toBeDisabled();
    await page.getByRole('checkbox', { name: /Presenter/ }).check();
    await save.click();

    await expect
        .poll(() => requests(page))
        .toEqual([
            {
                method: 'put',
                url: '/users/11/roles',
                data: { roles: ['admin', 'presenter'] },
            },
        ]);
});

test('shows a rejected role grant', async ({ page }) => {
    await openHarness(page, 'user');
    await page.evaluate(() =>
        window.concierge.respond({
            ok: false,
            errors: { roles: 'Admins cannot grant the admin role.' },
        }),
    );

    await page.getByRole('checkbox', { name: /Presenter/ }).check();
    await page.getByRole('button', { name: 'Save roles' }).click();

    await expect(
        page.getByText('Admins cannot grant the admin role.'),
    ).toBeVisible();
});

test('omits the editors and actions when the server grants none', async ({
    page,
}) => {
    await openHarness(page, 'user', 'readOnly');

    await expect(
        page.getByText('You cannot edit the profile of this user.'),
    ).toBeVisible();
    await expect(
        page.getByText('You cannot change the roles of this user.'),
    ).toBeVisible();
    await expect(
        page.getByText('There are no actions available for this user.'),
    ).toBeVisible();
    await expect(page.getByRole('textbox')).toHaveCount(0);
    await expect(page.getByRole('checkbox')).toHaveCount(0);
    await expect(page.getByRole('button')).toHaveCount(0);
});

test('removes through the access section', async ({ page }) => {
    await openHarness(page, 'user');

    await page.getByRole('button', { name: 'Remove' }).click();
    await page
        .getByRole('dialog', { name: 'Remove Ada Lovelace?' })
        .getByRole('button', { name: 'Remove' })
        .click();

    await expect
        .poll(() => requests(page))
        .toEqual([{ method: 'delete', url: '/users/11' }]);
});

test('says users, never workspace, and offers no deactivation', async ({
    page,
}) => {
    await openHarness(page, 'user');

    await expect(page.locator('.concierge')).not.toContainText(/workspace/i);
    await expect(
        page.getByText(/deactivate|password reset|impersonat/i),
    ).toHaveCount(0);
});

test('navigates back through the server-provided link', async ({ page }) => {
    await openHarness(page, 'user');

    await page.getByRole('link', { name: /Back to users/ }).click();

    await expect
        .poll(() => visits(page))
        .toEqual([expect.objectContaining({ url: '/users' })]);
});

test('lists the owner under Roles rather than "No roles"', async ({ page }) => {
    await openHarness(page, 'user', 'readOnly');
    const details = page.getByRole('region', { name: 'Account' });

    await expect(details.locator('dd').nth(2)).toHaveText('Owner');
    await expect(details).not.toContainText('No roles');
});
