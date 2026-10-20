import { expect, test } from '@playwright/test';
import { openHarness, requests, visits } from './support';

test.describe('members index', () => {
    test('renders members, roles, ownership and invitations', async ({
        page,
    }) => {
        await openHarness(page, 'members');

        await expect(
            page.getByRole('heading', { name: 'Members', level: 1 }),
        ).toBeVisible();
        const rows = page.locator('tbody tr');
        await expect(rows).toHaveCount(3);
        await expect(rows.nth(0)).toContainText('Owner');
        await expect(rows.nth(0)).toContainText('You');
        await expect(rows.nth(2)).toContainText('Suspended');
        await expect(
            page.getByRole('link', { name: 'Invite member' }),
        ).toHaveAttribute('href', '/workspaces/7/invitations/create');

        const invitations = page.getByRole('region', { name: 'Invitations' });
        await expect(invitations.getByRole('listitem')).toHaveCount(2);
        await expect(invitations).toContainText('Sent Sep 20, 2026');
        await expect(invitations).toContainText('Not sent yet');
        await expect(invitations).not.toContainText(/deliver/i);
    });

    test('renders only actions whose links the server provided', async ({
        page,
    }) => {
        await openHarness(page, 'members');
        const rows = page.locator('tbody tr');

        await expect(rows.nth(0).getByRole('button')).toHaveCount(0);
        await expect(rows.nth(1).getByRole('button')).toHaveText([
            'Transfer ownership',
            'Suspend',
            'Remove',
        ]);
        await expect(rows.nth(2).getByRole('button')).toHaveText([
            'Reinstate',
            'Remove',
        ]);

        const expired = page
            .getByRole('listitem')
            .filter({ hasText: 'margaret@example.com' });
        await expect(expired.getByRole('button')).toHaveText(['Resend']);
    });

    test('shows no management controls to a read-only viewer', async ({
        page,
    }) => {
        await openHarness(page, 'members', 'readOnly');

        await expect(page.locator('tbody').getByRole('button')).toHaveCount(0);
        await expect(
            page.getByRole('link', { name: 'Invite member' }),
        ).toHaveCount(0);
        await expect(page.locator('tbody').getByRole('link')).toHaveCount(0);
        await expect(
            page.getByRole('region', { name: 'Invitations' }),
        ).toHaveCount(0);
    });

    test('debounces search into a single refresh visit', async ({ page }) => {
        await openHarness(page, 'members');

        await page
            .getByLabel('Search members')
            .pressSequentially('ada', { delay: 30 });
        await page.clock.runFor(400);

        await expect
            .poll(() => visits(page))
            .toEqual([
                {
                    url: '/workspaces/7/members',
                    options: {
                        data: { search: 'ada' },
                        preserveScroll: true,
                        preserveState: true,
                        replace: true,
                    },
                },
            ]);
    });

    test('filters by status immediately', async ({ page }) => {
        await openHarness(page, 'members');

        await page.getByLabel('Status').selectOption('suspended');

        await expect
            .poll(() => visits(page))
            .toEqual([
                expect.objectContaining({
                    url: '/workspaces/7/members',
                    options: expect.objectContaining({
                        data: { status: 'suspended' },
                    }),
                }),
            ]);
    });

    test('pages through server-provided pagination links', async ({ page }) => {
        await openHarness(page, 'members');
        const pager = page.getByRole('navigation', { name: 'Pagination' });

        await expect(pager).toContainText('Page 1 of 2 · 5 total');
        await expect(
            pager.getByRole('button', { name: 'Previous' }),
        ).toBeDisabled();
        await pager.getByRole('link', { name: 'Next' }).click();

        await expect
            .poll(() => visits(page))
            .toEqual([
                expect.objectContaining({
                    url: '/workspaces/7/members?page=2',
                }),
            ]);
    });

    test('shows a filtered empty state that clears filters', async ({
        page,
    }) => {
        await openHarness(page, 'members', 'noResults');

        await expect(page.getByText('No matching members')).toBeVisible();
        await page
            .getByRole('button', { name: 'Clear filters' })
            .last()
            .click();

        await expect
            .poll(() => visits(page))
            .toEqual([
                expect.objectContaining({
                    url: '/workspaces/7/members',
                    options: expect.objectContaining({ data: {} }),
                }),
            ]);
    });
});

test.describe('member actions', () => {
    test('suspends through a keyboard-operable confirmation dialog', async ({
        page,
    }) => {
        await openHarness(page, 'members');
        const suspend = page
            .locator('tbody tr')
            .nth(1)
            .getByRole('button', { name: 'Suspend' });

        await suspend.focus();
        await page.keyboard.press('Enter');
        const dialog = page.getByRole('dialog', {
            name: 'Suspend Ada Lovelace?',
        });
        await expect(dialog).toBeVisible();
        await expect(dialog).toContainText('including open sessions');

        await page.keyboard.press('Escape');
        await expect(dialog).toBeHidden();
        await expect(suspend).toBeFocused();
        expect(await requests(page)).toEqual([]);

        await page.keyboard.press('Enter');
        await dialog.getByRole('button', { name: 'Suspend' }).click();

        await expect(dialog).toBeHidden();
        expect(await requests(page)).toEqual([
            { method: 'post', url: '/workspaces/7/members/2/suspend' },
        ]);
    });

    test('holds the dialog open and ignores repeat confirms while pending', async ({
        page,
    }) => {
        await openHarness(page, 'members');
        await page.evaluate(() => window.concierge.hold());
        await page
            .locator('tbody tr')
            .nth(2)
            .getByRole('button', { name: 'Remove' })
            .click();
        const dialog = page.getByRole('dialog', {
            name: 'Remove Grace Hopper from Teylabs?',
        });
        const confirm = dialog.getByRole('button', { name: 'Remove' });

        await confirm.click();
        await expect(confirm).toBeDisabled();
        await expect(confirm).toHaveAttribute('aria-busy', 'true');
        await dialog.evaluate((element) =>
            (element as HTMLFormElement).querySelector('form')?.requestSubmit(),
        );
        await page.keyboard.press('Escape');
        await expect(dialog).toBeVisible();

        await page.evaluate(() => window.concierge.release());
        await expect(dialog).toBeHidden();
        expect(await requests(page)).toEqual([
            { method: 'delete', url: '/workspaces/7/members/3' },
        ]);
    });

    test('reports a failed action and retries the same request', async ({
        page,
    }) => {
        await openHarness(page, 'members');
        await page.evaluate(() =>
            window.concierge.respond(
                { ok: false, failure: 'server' },
                { ok: true },
            ),
        );
        await page
            .locator('tbody tr')
            .nth(2)
            .getByRole('button', { name: 'Reinstate' })
            .click();
        const dialog = page.getByRole('dialog', {
            name: 'Reinstate Grace Hopper?',
        });

        await dialog.getByRole('button', { name: 'Reinstate' }).click();
        await expect(dialog.getByRole('alert')).toHaveText(
            'Something went wrong on our side. Please try again.',
        );
        await dialog.getByRole('button', { name: 'Try again' }).click();

        await expect(dialog).toBeHidden();
        expect(await requests(page)).toEqual([
            { method: 'post', url: '/workspaces/7/members/3/reinstate' },
            { method: 'post', url: '/workspaces/7/members/3/reinstate' },
        ]);
    });

    test('surfaces a server policy denial returned as a validation error', async ({
        page,
    }) => {
        await openHarness(page, 'members');
        await page.evaluate(() =>
            window.concierge.respond({
                ok: false,
                errors: { membership: 'The owner cannot be suspended.' },
            }),
        );
        await page
            .locator('tbody tr')
            .nth(1)
            .getByRole('button', { name: 'Suspend' })
            .click();
        const dialog = page.getByRole('dialog');

        await dialog.getByRole('button', { name: 'Suspend' }).click();

        await expect(dialog.getByRole('alert')).toHaveText(
            'The owner cannot be suspended.',
        );
    });

    test('requires typing the recipient name before transferring ownership', async ({
        page,
    }) => {
        await openHarness(page, 'members');
        await page
            .locator('tbody tr')
            .nth(1)
            .getByRole('button', { name: 'Transfer ownership' })
            .click();
        const dialog = page.getByRole('dialog', {
            name: 'Transfer ownership to Ada Lovelace?',
        });
        const confirm = dialog.getByRole('button', {
            name: 'Transfer ownership',
        });

        await expect(dialog).toContainText('asked to confirm your password');
        await expect(confirm).toBeDisabled();
        await dialog.getByLabel('Type Ada Lovelace to confirm').fill('Ada');
        await expect(confirm).toBeDisabled();
        await dialog
            .getByLabel('Type Ada Lovelace to confirm')
            .fill('Ada Lovelace');
        await confirm.click();

        expect(await requests(page)).toEqual([
            {
                method: 'post',
                url: '/workspaces/7/members/2/transfer-ownership',
            },
        ]);
    });
});

test.describe('invitations', () => {
    test('resends and confirms without claiming delivery', async ({ page }) => {
        await openHarness(page, 'members');
        const item = page
            .getByRole('listitem')
            .filter({ hasText: 'linus@example.com' });

        await item.getByRole('button', { name: 'Resend' }).click();

        await expect(item.getByRole('status')).toHaveText(
            'Invitation to linus@example.com was resent.',
        );
        expect(await requests(page)).toEqual([
            {
                method: 'post',
                url: '/workspaces/7/invitations/01JAB0000000000000000000PX/resend',
            },
        ]);
    });

    test('offers retry when a resend fails', async ({ page }) => {
        await openHarness(page, 'members');
        await page.evaluate(() =>
            window.concierge.respond('throw', { ok: true }),
        );
        const item = page
            .getByRole('listitem')
            .filter({ hasText: 'linus@example.com' });

        await item.getByRole('button', { name: 'Resend' }).click();
        await expect(item.getByRole('alert')).toContainText(
            'could not reach the server',
        );
        await item.getByRole('button', { name: 'Try again' }).click();

        await expect(item.getByRole('status')).toBeVisible();
        expect(await requests(page)).toHaveLength(2);
    });

    test('revokes after confirmation with DELETE', async ({ page }) => {
        await openHarness(page, 'members');
        const item = page
            .getByRole('listitem')
            .filter({ hasText: 'linus@example.com' });

        await item.getByRole('button', { name: 'Revoke' }).click();
        const dialog = page.getByRole('dialog', { name: 'Revoke invitation?' });
        await expect(dialog).toContainText('linus@example.com');
        await dialog.getByRole('button', { name: 'Revoke invitation' }).click();

        await expect(dialog).toBeHidden();
        expect(await requests(page)).toEqual([
            {
                method: 'delete',
                url: '/workspaces/7/invitations/01JAB0000000000000000000PX',
            },
        ]);
    });

    test('labels expired invitations', async ({ page }) => {
        await openHarness(page, 'members');
        const item = page
            .getByRole('listitem')
            .filter({ hasText: 'margaret@example.com' });

        await expect(item).toContainText('Expired');
        await expect(item).toContainText('Expired 2 weeks ago');
    });
});

test.describe('owner role display', () => {
    test('shows the owner in the Roles column instead of "No roles"', async ({
        page,
    }) => {
        await openHarness(page, 'members', 'roleless');
        const [ownerRow, rolelessRow] = [
            page.locator('tbody tr').nth(0),
            page.locator('tbody tr').nth(1),
        ];

        const ownerRoles = ownerRow.locator('td').nth(1);
        await expect(ownerRoles).toHaveText('Owner');
        await expect(ownerRow.locator('td').nth(0)).not.toContainText('Owner');
        await expect(ownerRow.locator('td').nth(0)).toContainText('You');
        await expect(ownerRow).not.toContainText('No roles');

        await expect(rolelessRow.locator('td').nth(1)).toHaveText('No roles');
    });
});

test.describe('avatars', () => {
    test('shows initials on a stable colour beside every member', async ({
        page,
    }) => {
        const external: string[] = [];
        page.on('request', (request) => {
            if (!request.url().startsWith('http://127.0.0.1:8152')) {
                external.push(request.url());
            }
        });
        await openHarness(page, 'members');
        const avatars = page.locator('tbody [data-concierge-avatar]');

        await expect(avatars).toHaveText(['JT', 'AL', 'GH']);
        await expect(avatars.nth(0)).toHaveClass(/concierge-avatar-indigo/);
        await expect(avatars.nth(1)).toHaveClass(/concierge-avatar-sky/);
        await expect(avatars.nth(0)).toHaveAttribute('aria-hidden', 'true');
        expect(external).toEqual([]);
    });

    test('shows a photo when the app supplies one and falls back to initials when it fails to load', async ({
        page,
    }) => {
        await openHarness(page, 'members', 'photos');
        const avatars = page.locator('tbody [data-concierge-avatar]');

        await expect(avatars.nth(0).locator('img')).toBeVisible();
        await expect(avatars.nth(0)).toHaveText('');
        await expect(avatars.nth(1)).toHaveText('AL');
        await expect(avatars.nth(1).locator('img')).toHaveCount(0);
        await expect(avatars.nth(2)).toHaveText('GH');
    });
});

test.describe('members index tabs', () => {
    test('shows Members and Invitations tabs with the server counts, the current one marked', async ({
        page,
    }) => {
        await openHarness(page, 'members', 'tabs');

        const tabs = page.getByRole('navigation', {
            name: 'Members and invitations',
        });
        await expect(tabs.getByRole('link')).toHaveText([
            'Members 5',
            'Invitations 2',
        ]);
        await expect(
            tabs.getByRole('link', { name: /Members/ }),
        ).toHaveAttribute('aria-current', 'page');
        await expect(
            tabs.getByRole('link', { name: /Invitations/ }),
        ).not.toHaveAttribute('aria-current');
        await expect(
            tabs.getByRole('link', { name: /Invitations/ }),
        ).toHaveAttribute('href', '/workspaces/7/members?tab=invitations');
        await expect(page.locator('tbody tr')).toHaveCount(3);
        await expect(
            page.getByRole('region', { name: 'Invitations' }),
        ).toHaveCount(0);
    });

    test('switching to the Invitations tab visits its URL', async ({
        page,
    }) => {
        await openHarness(page, 'members', 'tabs');

        await page.getByRole('link', { name: /Invitations/ }).click();

        expect(await visits(page)).toEqual([
            expect.objectContaining({
                url: '/workspaces/7/members?tab=invitations',
            }),
        ]);
    });

    test('the Invitations tab lists invitations only, and searches through the filter query', async ({
        page,
    }) => {
        await openHarness(page, 'members', 'invitations');

        await expect(
            page.getByRole('link', { name: /Invitations/ }),
        ).toHaveAttribute('aria-current', 'page');
        await expect(page.locator('tbody tr')).toHaveCount(0);
        const invitations = page.getByRole('region', { name: 'Invitations' });
        await expect(invitations.getByRole('listitem')).toHaveCount(2);

        await invitations.getByRole('searchbox').fill('mar');
        await page.waitForTimeout(400);

        const sent = await visits(page);
        expect(sent).toHaveLength(1);
        expect(sent[0]).toMatchObject({
            url: '/workspaces/7/members',
            options: expect.objectContaining({
                data: { tab: 'invitations', filter: { search: 'mar' } },
            }),
        });
    });

    test('the Invitations tab says so when there are none', async ({
        page,
    }) => {
        await openHarness(page, 'members', 'invitationsEmpty');

        await expect(page.getByText('No pending invitations')).toBeVisible();
    });

    test('the tabs and the lists stay usable on a phone with no sideways scroll', async ({
        page,
    }) => {
        await page.setViewportSize({ width: 390, height: 800 });
        await openHarness(page, 'members', 'tabs');

        await expect(
            page.getByRole('link', { name: /Invitations/ }),
        ).toBeVisible();
        expect(
            await page.evaluate(
                () =>
                    document.documentElement.scrollWidth <=
                    document.documentElement.clientWidth,
            ),
        ).toBe(true);
    });
});
