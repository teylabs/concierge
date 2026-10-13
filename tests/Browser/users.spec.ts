import { expect, test } from '@playwright/test';
import { openHarness, requests, visits } from './support';

test.describe('users index', () => {
    test('renders users, roles, ownership and status without workspace wording', async ({
        page,
    }) => {
        await openHarness(page, 'users', 'withDeactivated');

        await expect(
            page.getByRole('heading', { name: 'Users', level: 1 }),
        ).toBeVisible();
        const rows = page.locator('tbody tr');
        await expect(rows).toHaveCount(3);
        await expect(rows.nth(0)).toContainText('Owner');
        await expect(rows.nth(0)).toContainText('You');
        await expect(rows.nth(0)).toContainText('Active');
        await expect(rows.nth(2)).toContainText('Deactivated');
        await expect(
            page.getByRole('link', { name: 'Invite user' }),
        ).toHaveAttribute('href', '/users/create');
        await expect(page.locator('.concierge')).not.toContainText(
            /workspace/i,
        );
    });

    test('hides deactivated users by default and lists Active first', async ({
        page,
    }) => {
        await openHarness(page, 'users');

        await expect(page.locator('tbody tr')).toHaveCount(2);
        await expect(page.locator('tbody')).not.toContainText('Deactivated');
        await expect(page.getByLabel('Status')).toHaveValue('active');
        await expect(
            page.getByRole('button', { name: 'Clear filters' }),
        ).toHaveCount(0);
    });

    test('renders a primary action and a menu only where the server provided links', async ({
        page,
    }, info) => {
        await openHarness(page, 'users', 'withDeactivated');
        const rows = page.locator('tbody tr');

        await expect(rows.nth(0).getByRole('button')).toHaveCount(0);
        await expect(rows.nth(1).getByRole('button')).toHaveText([
            'Deactivate',
            'More actions',
        ]);
        await expect(rows.nth(2).getByRole('button')).toHaveText([
            'Restore',
            'More actions',
        ]);

        const active = rows
            .nth(1)
            .getByRole('button', { name: 'More actions' });
        await active.click();
        await expect(page.getByRole('menuitem')).toHaveText([
            'Force password reset',
            'Remove',
        ]);
        await page.screenshot({
            path: `build/screenshots/${info.project.name}/users-active-menu.png`,
            fullPage: true,
        });
        await page.getByRole('heading', { name: 'Users', level: 1 }).click();
        await expect(page.getByRole('menu')).toBeHidden();
        await expect(active).toHaveAttribute('aria-expanded', 'false');
        await expect(active).toBeFocused();
        expect(await requests(page)).toEqual([]);

        await active.click();
        await page.getByLabel('Search users').click();
        await expect(page.getByRole('menu')).toBeHidden();
        await expect(page.getByLabel('Search users')).toBeFocused();

        const restored = rows
            .nth(2)
            .getByRole('button', { name: 'More actions' });
        await restored.focus();
        await restored.press('Enter');
        const remove = page.getByRole('menuitem', { name: 'Remove' });
        await expect(page.getByRole('menuitem')).toHaveText(['Remove']);
        await expect(remove).toBeFocused();
        await page.keyboard.press('ArrowDown');
        await expect(remove).toBeFocused();
        await page.screenshot({
            path: `build/screenshots/${info.project.name}/users-deactivated-menu.png`,
            fullPage: true,
        });
        await page.keyboard.press('Space');
        const dialog = page.getByRole('dialog', {
            name: 'Remove Grace Hopper?',
        });
        await expect(dialog).toBeVisible();
        await page.keyboard.press('Escape');
        await expect(restored).toBeFocused();
        expect(await requests(page)).toEqual([]);
    });

    test('keeps index actions on one line without horizontal overflow', async ({
        page,
    }) => {
        await openHarness(page, 'users', 'withDeactivated');

        for (const index of [1, 2]) {
            const row = page.locator('tbody tr').nth(index);
            const primary = await row.getByRole('button').nth(0).boundingBox();
            const trigger = await row.getByRole('button').nth(1).boundingBox();
            expect(primary).not.toBeNull();
            expect(trigger).not.toBeNull();
            expect(primary!.y).toBe(trigger!.y);
            expect(primary!.x + primary!.width).toBeLessThan(trigger!.x);
            expect(trigger!.x + trigger!.width).toBeLessThanOrEqual(
                page.viewportSize()!.width,
            );
        }
        expect(
            await page.evaluate(() => document.documentElement.scrollWidth),
        ).toBeLessThanOrEqual(page.viewportSize()!.width);
    });

    test('shows no management controls to a read-only viewer', async ({
        page,
    }) => {
        await openHarness(page, 'users', 'readOnly');

        await expect(page.locator('tbody').getByRole('button')).toHaveCount(0);
        await expect(
            page.getByRole('link', { name: 'Invite user' }),
        ).toHaveCount(0);
    });

    test('marks a shielded operator account', async ({ page }) => {
        await openHarness(page, 'users', 'operator');

        await expect(page.locator('tbody tr').nth(0)).toContainText('Operator');
        await expect(
            page.locator('tbody tr').nth(0).getByRole('button'),
        ).toHaveCount(0);
    });

    test('debounces search into a single refresh visit', async ({ page }) => {
        await openHarness(page, 'users');

        await page
            .getByLabel('Search users')
            .pressSequentially('ada', { delay: 30 });
        await page.clock.runFor(400);

        await expect
            .poll(() => visits(page))
            .toEqual([
                {
                    url: '/users',
                    options: {
                        data: { search: 'ada' },
                        preserveScroll: true,
                        preserveState: true,
                        replace: true,
                    },
                },
            ]);
    });

    test('filters by role and by status immediately', async ({ page }) => {
        await openHarness(page, 'users');

        await page.getByLabel('Role').selectOption('admin');
        await page.getByLabel('Status').selectOption('deactivated');

        await expect
            .poll(() => visits(page))
            .toEqual([
                expect.objectContaining({
                    url: '/users',
                    options: expect.objectContaining({
                        data: { role: 'admin' },
                    }),
                }),
                expect.objectContaining({
                    url: '/users',
                    options: expect.objectContaining({
                        data: { role: 'admin', status: 'deactivated' },
                    }),
                }),
            ]);
    });

    test('omits the status from the visit when going back to Active', async ({
        page,
    }) => {
        await openHarness(page, 'users', 'filtered');

        await page.getByLabel('Status').selectOption('active');

        await expect
            .poll(() => visits(page))
            .toEqual([
                expect.objectContaining({
                    url: '/users',
                    options: expect.objectContaining({
                        data: { role: 'presenter' },
                    }),
                }),
            ]);
    });

    test('offers the active, deactivated and all statuses', async ({
        page,
    }) => {
        await openHarness(page, 'users');

        await expect(page.getByLabel('Status').locator('option')).toHaveText([
            'Active',
            'Deactivated',
            'Active and deactivated',
        ]);
        await expect(page.getByLabel('Role').locator('option')).toHaveText([
            'All roles',
            'Admin',
            'Presenter',
        ]);
    });

    test('keeps the server filters selected and clears them', async ({
        page,
    }) => {
        await openHarness(page, 'users', 'filtered');

        await expect(page.getByLabel('Role')).toHaveValue('presenter');
        await expect(page.getByLabel('Status')).toHaveValue('deactivated');
        await page.getByRole('button', { name: 'Clear filters' }).click();

        await expect
            .poll(() => visits(page))
            .toEqual([
                expect.objectContaining({
                    url: '/users',
                    options: expect.objectContaining({ data: {} }),
                }),
            ]);
    });

    test('pages through server-provided pagination links', async ({ page }) => {
        await openHarness(page, 'users');
        const pager = page.getByRole('navigation', { name: 'Pagination' });

        await expect(pager).toContainText('Page 1 of 2 · 5 total');
        await pager.getByRole('link', { name: 'Next' }).click();

        await expect
            .poll(() => visits(page))
            .toEqual([expect.objectContaining({ url: '/users?page=2' })]);
    });

    test('shows empty and filtered empty states', async ({ page }) => {
        await openHarness(page, 'users', 'empty');
        await expect(page.getByText('No users yet')).toBeVisible();

        await openHarness(page, 'users', 'noResults');
        await expect(page.getByText('No matching users')).toBeVisible();
    });

    test('adds no columns unless the app defined some', async ({ page }) => {
        await openHarness(page, 'users');

        await expect(page.locator('thead th')).toHaveText([
            'User',
            'Roles',
            'Status',
            'Joined',
            'Actions',
        ]);
    });

    test("renders the app's columns, in order, before the actions", async ({
        page,
    }) => {
        await openHarness(page, 'users', 'columns');
        const rows = page.locator('tbody tr');

        await expect(page.locator('thead th')).toHaveText([
            'User',
            'Roles',
            'Status',
            'Joined',
            'Plan',
            'Seats',
            'Actions',
        ]);
        await expect(rows.nth(1).locator('[data-column="plan"]')).toHaveText(
            'Pro',
        );
        await expect(rows.nth(1).locator('[data-column="seats"]')).toHaveText(
            '2',
        );
        await expect(rows.nth(2).locator('[data-column="plan"]')).toHaveText(
            '',
        );
    });
});

test.describe('user actions', () => {
    test('removes through a confirmation dialog with a DELETE', async ({
        page,
    }) => {
        await openHarness(page, 'users', 'withDeactivated');
        await page
            .locator('tbody tr')
            .nth(2)
            .getByRole('button', { name: 'More actions' })
            .click();
        await page.getByRole('menuitem', { name: 'Remove' }).click();
        const dialog = page.getByRole('dialog', {
            name: 'Remove Grace Hopper?',
        });

        await expect(dialog).toContainText('Their account is deleted');
        await expect(dialog).not.toContainText(/workspace/i);
        expect(await requests(page)).toEqual([]);
        await dialog.getByRole('button', { name: 'Remove' }).click();

        await expect(dialog).toBeHidden();
        expect(await requests(page)).toEqual([
            { method: 'delete', url: '/users/12' },
        ]);
    });

    test('closes without a request on Escape', async ({ page }) => {
        await openHarness(page, 'users');
        await page
            .locator('tbody tr')
            .nth(1)
            .getByRole('button', { name: 'More actions' })
            .click();
        await page.getByRole('menuitem', { name: 'Remove' }).click();
        await page.keyboard.press('Escape');

        await expect(page.getByRole('dialog')).toBeHidden();
        expect(await requests(page)).toEqual([]);
    });

    test('shows a failed removal and retries it', async ({ page }) => {
        await openHarness(page, 'users');
        await page.evaluate(() =>
            window.concierge.respond({ ok: false, failure: 'forbidden' }),
        );
        await page
            .locator('tbody tr')
            .nth(1)
            .getByRole('button', { name: 'More actions' })
            .click();
        await page.getByRole('menuitem', { name: 'Remove' }).click();
        const dialog = page.getByRole('dialog');

        await dialog.getByRole('button', { name: 'Remove' }).click();
        await expect(dialog.getByRole('alert')).toHaveText(
            'You are not allowed to do that.',
        );
        await dialog.getByRole('button', { name: 'Try again' }).click();

        await expect(dialog).toBeHidden();
        expect(await requests(page)).toHaveLength(2);
    });

    test('deactivates with an optional reason through a POST', async ({
        page,
    }) => {
        await openHarness(page, 'users');
        await page
            .locator('tbody tr')
            .nth(1)
            .getByRole('button', { name: 'Deactivate' })
            .click();
        const dialog = page.getByRole('dialog', {
            name: 'Deactivate Ada Lovelace?',
        });

        await expect(dialog).not.toContainText(/workspace/i);
        await dialog
            .getByLabel('Reason (optional)')
            .fill('  Left the company ');
        expect(await requests(page)).toEqual([]);
        await dialog.getByRole('button', { name: 'Deactivate' }).click();

        await expect(dialog).toBeHidden();
        expect(await requests(page)).toEqual([
            {
                method: 'post',
                url: '/users/11/deactivate',
                data: { reason: 'Left the company' },
            },
        ]);
    });

    test('deactivates without a reason by sending no data', async ({
        page,
    }) => {
        await openHarness(page, 'users');
        await page
            .locator('tbody tr')
            .nth(1)
            .getByRole('button', { name: 'Deactivate' })
            .click();
        await page
            .getByRole('dialog')
            .getByRole('button', { name: 'Deactivate' })
            .click();

        expect(await requests(page)).toEqual([
            { method: 'post', url: '/users/11/deactivate' },
        ]);
    });

    test('forces a password reset from the menu without resetting two-factor', async ({
        page,
    }) => {
        await openHarness(page, 'users');
        await page
            .locator('tbody tr')
            .nth(1)
            .getByRole('button', { name: 'More actions' })
            .click();
        await page
            .getByRole('menuitem', { name: 'Force password reset' })
            .click();
        const dialog = page.getByRole('dialog', {
            name: 'Force a password reset for Ada Lovelace?',
        });

        expect(await requests(page)).toEqual([]);
        await dialog
            .getByRole('button', { name: 'Force password reset' })
            .click();
        await expect(dialog).toBeHidden();
        expect(await requests(page)).toEqual([
            { method: 'post', url: '/users/11/force-password-reset' },
        ]);
    });

    test('restores a deactivated user through a POST', async ({ page }) => {
        await openHarness(page, 'users', 'withDeactivated');
        await page
            .locator('tbody tr')
            .nth(2)
            .getByRole('button', { name: 'Restore' })
            .click();
        const dialog = page.getByRole('dialog', {
            name: 'Restore Grace Hopper?',
        });

        await expect(dialog.getByRole('textbox')).toHaveCount(0);
        await dialog.getByRole('button', { name: 'Restore' }).click();

        await expect(dialog).toBeHidden();
        expect(await requests(page)).toEqual([
            { method: 'post', url: '/users/12/restore' },
        ]);
    });

    test('shows a failed deactivation and retries it', async ({ page }) => {
        await openHarness(page, 'users');
        await page.evaluate(() =>
            window.concierge.respond({ ok: false, failure: 'forbidden' }),
        );
        await page
            .locator('tbody tr')
            .nth(1)
            .getByRole('button', { name: 'Deactivate' })
            .click();
        const dialog = page.getByRole('dialog');

        await dialog.getByRole('button', { name: 'Deactivate' }).click();
        await expect(dialog.getByRole('alert')).toHaveText(
            'You are not allowed to do that.',
        );
        await dialog.getByRole('button', { name: 'Try again' }).click();

        await expect(dialog).toBeHidden();
        expect(await requests(page)).toHaveLength(2);
    });

    test('hides deactivate and restore for a read-only viewer and an operator', async ({
        page,
    }) => {
        await openHarness(page, 'users', 'readOnly');
        await expect(
            page.getByRole('button', { name: /deactivate|restore/i }),
        ).toHaveCount(0);

        await openHarness(page, 'users', 'operator');
        await expect(
            page.getByRole('button', { name: /deactivate|restore/i }),
        ).toHaveCount(0);
    });
});
