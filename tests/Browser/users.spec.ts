import { expect, test } from '@playwright/test';
import { openHarness, requests, visits } from './support';

test.describe('users index', () => {
    test('renders users, roles, ownership and status without workspace wording', async ({
        page,
    }) => {
        await openHarness(page, 'users');

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

    test('renders only remove, and only where the server provided a link', async ({
        page,
    }) => {
        await openHarness(page, 'users');
        const rows = page.locator('tbody tr');

        await expect(rows.nth(0).getByRole('button')).toHaveCount(0);
        await expect(rows.nth(1).getByRole('button')).toHaveText(['Remove']);
        await expect(rows.nth(2).getByRole('button')).toHaveText(['Remove']);
        await expect(
            page.getByRole('button', { name: /deactivate|reactivate/i }),
        ).toHaveCount(0);
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

    test('offers exactly the active and deactivated statuses', async ({
        page,
    }) => {
        await openHarness(page, 'users');

        await expect(page.getByLabel('Status').locator('option')).toHaveText([
            'All statuses',
            'Active',
            'Deactivated',
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
        await openHarness(page, 'users');
        await page
            .locator('tbody tr')
            .nth(2)
            .getByRole('button', { name: 'Remove' })
            .click();
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
            .getByRole('button', { name: 'Remove' })
            .click();
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
            .getByRole('button', { name: 'Remove' })
            .click();
        const dialog = page.getByRole('dialog');

        await dialog.getByRole('button', { name: 'Remove' }).click();
        await expect(dialog.getByRole('alert')).toHaveText(
            'You are not allowed to do that.',
        );
        await dialog.getByRole('button', { name: 'Try again' }).click();

        await expect(dialog).toBeHidden();
        expect(await requests(page)).toHaveLength(2);
    });
});
