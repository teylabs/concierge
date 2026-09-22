import { expect, test } from '@playwright/test';
import { openHarness, requests, visits } from './support';

test('saves changed roles with PUT to the grants form URL', async ({
    page,
}) => {
    await openHarness(page, 'member');
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
                url: '/workspaces/7/members/2/roles',
                data: { roles: ['admin', 'presenter'] },
            },
        ]);
});

test('confirms saved roles once the server returns them', async ({ page }) => {
    await openHarness(page, 'member');

    await page.getByRole('checkbox', { name: /Presenter/ }).check();
    await page.getByRole('button', { name: 'Save roles' }).click();
    await page.evaluate(() =>
        window.concierge.setProps({
            grants: {
                payload: { roles: ['admin', 'presenter'] },
                metadata: {
                    formUrl: '/workspaces/7/members/2/roles',
                    roleOptions: [
                        { value: 'admin', label: 'Admin', description: null },
                        {
                            value: 'presenter',
                            label: 'Presenter',
                            description: null,
                        },
                    ],
                },
            },
        }),
    );

    await expect(page.getByRole('status')).toHaveText('Roles saved.');
});

test('shows a rejected role grant', async ({ page }) => {
    await openHarness(page, 'member');
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
    await expect(
        page.getByRole('checkbox', { name: /Presenter/ }),
    ).toBeChecked();
});

test('omits the role editor and actions when the server grants none', async ({
    page,
}) => {
    await openHarness(page, 'member', 'readOnly');

    await expect(
        page.getByText('You cannot change the roles of this member.'),
    ).toBeVisible();
    await expect(page.getByRole('checkbox')).toHaveCount(0);
    await expect(
        page.getByText('There are no actions available for this member.'),
    ).toBeVisible();
    await expect(page.getByRole('button')).toHaveCount(0);
});

test('never renders global account controls', async ({ page }) => {
    await openHarness(page, 'member');

    await expect(
        page.getByText(/password reset|impersonat|deactivate account/i),
    ).toHaveCount(0);
});

test('navigates back through the server-provided link', async ({ page }) => {
    await openHarness(page, 'member');

    await page.getByRole('link', { name: /Back to members/ }).click();

    await expect
        .poll(() => visits(page))
        .toEqual([expect.objectContaining({ url: '/workspaces/7/members' })]);
});
