import { expect, type Page } from '@playwright/test';

export const now = new Date('2026-09-22T12:00:00Z');

export async function openHarness(
    page: Page,
    name: string,
    fixture = 'default',
) {
    await page.clock.setFixedTime(now);
    await page.goto(`/?page=${name}&fixture=${fixture}`);
    await expect(page.locator('.concierge')).toBeVisible();
}

export function requests(page: Page) {
    return page.evaluate(() => window.concierge.requests);
}

export function visits(page: Page) {
    return page.evaluate(() => window.concierge.visits);
}
