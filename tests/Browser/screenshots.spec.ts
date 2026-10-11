import { test } from '@playwright/test';
import { openHarness } from './support';

const screens: [string, string][] = [
    ['members', 'default'],
    ['members', 'readOnly'],
    ['members', 'noResults'],
    ['invite', 'default'],
    ['member', 'default'],
    ['accept', 'ready'],
    ['accept', 'wrong_account'],
    ['accept', 'unverified'],
    ['members', 'roleless'],
    ['members', 'photos'],
];

for (const [name, fixture] of screens) {
    test(`captures ${name} ${fixture}`, async ({ page }, info) => {
        await openHarness(page, name, fixture);
        await page.screenshot({
            path: `build/screenshots/${info.project.name}/${name}-${fixture}.png`,
            fullPage: true,
        });
    });
}

test('captures an open confirmation dialog', async ({ page }, info) => {
    await openHarness(page, 'members');
    await page
        .locator('tbody tr')
        .nth(1)
        .getByRole('button', { name: 'Transfer ownership' })
        .click();
    await page.screenshot({
        path: `build/screenshots/${info.project.name}/members-transfer-dialog.png`,
    });
});
