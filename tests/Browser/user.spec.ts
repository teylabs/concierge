import { expect, test } from '@playwright/test';
import { appAdmin, appOwner, deactivated } from '../../harness/fixtures';
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

    await page.getByRole('button', { name: 'More actions' }).click();
    await page.getByRole('menuitem', { name: 'Remove' }).click();
    await page
        .getByRole('dialog', { name: 'Remove Ada Lovelace?' })
        .getByRole('button', { name: 'Remove' })
        .click();

    await expect
        .poll(() => requests(page))
        .toEqual([{ method: 'delete', url: '/users/11' }]);
});

test('says users, never workspace', async ({ page }) => {
    await openHarness(page, 'user');

    await expect(page.locator('.concierge')).not.toContainText(/workspace/i);
    await expect(page.getByText(/impersonat/i)).toHaveCount(0);
});

test('offers deactivate, not restore, for an active user', async ({ page }) => {
    await openHarness(page, 'user');

    await expect(page.getByRole('button', { name: 'Restore' })).toHaveCount(0);
    await page.getByRole('button', { name: 'Deactivate' }).click();
    const dialog = page.getByRole('dialog', {
        name: 'Deactivate Ada Lovelace?',
    });

    await dialog.getByLabel('Reason (optional)').fill('Left the company');
    await dialog.getByRole('button', { name: 'Deactivate' }).click();

    await expect
        .poll(() => requests(page))
        .toEqual([
            {
                method: 'post',
                url: '/users/11/deactivate',
                data: { reason: 'Left the company' },
            },
        ]);
});

test('forces a password reset after a plain confirmation', async ({ page }) => {
    await openHarness(page, 'user');

    await page.getByRole('button', { name: 'More actions' }).click();
    await page.getByRole('menuitem', { name: 'Force password reset' }).click();
    const dialog = page.getByRole('dialog', {
        name: 'Force a password reset for Ada Lovelace?',
    });

    await expect(dialog).toContainText('signed out everywhere');
    await expect(dialog).toContainText('reset link is emailed');
    await dialog.getByLabel('Also reset two-factor authentication').check();
    await dialog.getByRole('button', { name: 'Force password reset' }).click();

    await expect
        .poll(() => requests(page))
        .toEqual([
            {
                method: 'post',
                url: '/users/11/force-password-reset',
                data: { resetTwoFactor: true },
            },
        ]);
});

test('hides force password reset for a deactivated user', async ({
    page,
}, info) => {
    await openHarness(page, 'user', 'deactivated');
    await page.evaluate((user) => window.concierge.setProps({ user }), {
        ...deactivated,
        links: { ...deactivated.links, forcePasswordReset: '/users/12/reset' },
    });
    await page.getByRole('button', { name: 'More actions' }).click();

    await expect(page.getByRole('menuitem')).toHaveText(['Remove']);
    await page.screenshot({
        path: `build/screenshots/${info.project.name}/user-deactivated-menu.png`,
        fullPage: true,
    });
});

test('offers restore, not deactivate, for a deactivated user', async ({
    page,
}) => {
    await openHarness(page, 'user', 'deactivated');

    await expect(page.getByText('Deactivated').first()).toBeVisible();
    await expect(page.getByRole('button', { name: 'Deactivate' })).toHaveCount(
        0,
    );
    await page.getByRole('button', { name: 'Restore' }).click();
    await page
        .getByRole('dialog', { name: 'Restore Grace Hopper?' })
        .getByRole('button', { name: 'Restore' })
        .click();

    await expect
        .poll(() => requests(page))
        .toEqual([{ method: 'post', url: '/users/12/restore' }]);
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

test('navigates the action menu with the keyboard and returns focus after confirmation', async ({
    page,
}, info) => {
    await openHarness(page, 'user');
    const trigger = page.getByRole('button', { name: 'More actions' });
    const reset = page.getByRole('menuitem', { name: 'Force password reset' });
    const remove = page.getByRole('menuitem', { name: 'Remove' });

    await expect(trigger).toHaveAttribute('aria-haspopup', 'menu');
    await expect(trigger).toHaveAttribute('aria-expanded', 'false');
    await trigger.focus();
    await trigger.press('ArrowDown');
    await expect(reset).toBeFocused();
    await expect(trigger).toHaveAttribute('aria-expanded', 'true');
    await expect(page.getByRole('menu')).toHaveAccessibleName('More actions');
    await page.keyboard.press('ArrowDown');
    await expect(remove).toBeFocused();
    await page.keyboard.press('ArrowDown');
    await expect(reset).toBeFocused();
    await page.keyboard.press('ArrowUp');
    await expect(remove).toBeFocused();
    await page.keyboard.press('Home');
    await expect(reset).toBeFocused();
    await page.keyboard.press('End');
    await expect(remove).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(page.getByRole('menu')).toBeHidden();
    await expect(trigger).toBeFocused();
    await expect(trigger).toHaveAttribute('aria-expanded', 'false');

    await trigger.press('ArrowUp');
    await expect(remove).toBeFocused();
    await page.screenshot({
        path: `build/screenshots/${info.project.name}/user-actions-menu.png`,
        fullPage: true,
    });
    await page.keyboard.press('Enter');
    const dialog = page.getByRole('dialog', { name: 'Remove Ada Lovelace?' });
    await expect(dialog).toBeVisible();
    await expect(page.getByRole('menu')).toBeHidden();
    await dialog.getByRole('button', { name: 'Cancel' }).click();
    await expect(trigger).toBeFocused();
    expect(await requests(page)).toEqual([]);

    await trigger.press('Space');
    await expect(reset).toBeFocused();
    await page.keyboard.press('Tab');
    await expect(page.getByRole('menu')).toBeHidden();
    await expect(
        page.getByRole('menuitem', {
            name: 'Force password reset',
            includeHidden: true,
        }),
    ).not.toBeFocused();
    await expect(trigger).not.toBeFocused();
});

for (const [kind, label, user] of [
    ['deactivate', 'Deactivate', appAdmin],
    ['restore', 'Restore', deactivated],
    ['forcePasswordReset', 'Force password reset', appAdmin],
    ['remove', 'Remove', appAdmin],
] as const) {
    test(`shows a plain ${label} button when it is the only action`, async ({
        page,
    }) => {
        await openHarness(page, 'user');
        await page.evaluate((user) => window.concierge.setProps({ user }), {
            ...user,
            links: {
                ...user.links,
                deactivate: null,
                restore: null,
                forcePasswordReset: null,
                remove: null,
                [kind]: user.links[kind],
            },
        });

        await expect(
            page.getByRole('button', { name: 'More actions' }),
        ).toHaveCount(0);
        await page.getByRole('button', { name: label, exact: true }).click();
        await expect(page.getByRole('dialog')).toBeVisible();
        expect(await requests(page)).toEqual([]);
    });
}

test('keeps multiple secondary actions in the menu when there is no primary link', async ({
    page,
}) => {
    await openHarness(page, 'user');
    await page.evaluate((user) => window.concierge.setProps({ user }), {
        ...appAdmin,
        links: { ...appAdmin.links, deactivate: null },
    });

    await expect(page.getByRole('button', { name: 'Deactivate' })).toHaveCount(
        0,
    );
    await expect(
        page.getByRole('button', { name: 'Remove', exact: true }),
    ).toHaveCount(0);
    await page.getByRole('button', { name: 'More actions' }).click();
    await expect(page.getByRole('menuitem')).toHaveText([
        'Force password reset',
        'Remove',
    ]);
});

test('hides password reset for self even with a stale link', async ({
    page,
}) => {
    await openHarness(page, 'user', 'readOnly');
    await page.evaluate((user) => window.concierge.setProps({ user }), {
        ...appOwner,
        hasActions: true,
        links: { ...appOwner.links, forcePasswordReset: '/users/10/reset' },
    });

    await expect(page.getByRole('button')).toHaveCount(0);
});
