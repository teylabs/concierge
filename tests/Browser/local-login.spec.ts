import { expect, test } from '@playwright/test';

const form = '[data-concierge="local-login"]';

test('offers a one-click sign-in with a native POST form and the CSRF token', async ({
    page,
}) => {
    await page.goto('/local-login.html');

    const button = page.getByRole('button', {
        name: 'Sign in as local@example.test',
    });
    await expect(button).toBeVisible();
    await expect(
        page.getByText('Local development only', { exact: false }),
    ).toBeVisible();

    await expect(page.locator(form)).toHaveAttribute('method', 'post');
    await expect(page.locator(form)).toHaveAttribute('action', '/local-login');

    // The token is the only field: no identity and no password can be submitted.
    const fields = page.locator(
        `${form} input, ${form} textarea, ${form} select`,
    );
    await expect(fields).toHaveCount(1);
    await expect(fields.first()).toHaveAttribute('type', 'hidden');
    await expect(fields.first()).toHaveAttribute('name', '_token');
    await expect(fields.first()).toHaveValue('harness-token');

    // The token never appears in the URL.
    expect(page.url()).not.toContain('harness-token');
    await expect(page.locator(form)).not.toHaveAttribute(
        'action',
        /harness-token/,
    );
});

test('renders nothing without an offer', async ({ page }) => {
    await page.goto('/local-login.html?offer=none');

    await expect(page.locator('#app')).toBeAttached();
    await expect(page.locator(form)).toHaveCount(0);
    await expect(page.getByRole('button')).toHaveCount(0);
});

test('posts the token once and ignores a second click while navigating', async ({
    page,
}) => {
    const posts: { url: string; body: string | null }[] = [];

    await page.route('**/local-login', async (route) => {
        posts.push({
            url: route.request().url(),
            body: route.request().postData(),
        });
        // Hold the response so the page is still "busy" when the second click lands.
        await new Promise((resolve) => setTimeout(resolve, 1500));
        await route.fulfill({
            status: 200,
            contentType: 'text/html',
            body: '<p>signed in</p>',
        });
    });

    await page.goto('/local-login.html');

    // Driven from inside the page in one step: Playwright actions and evaluates wait
    // for a pending navigation, and the held response keeps this document alive
    // only until the navigation commits.
    const busy = await page.evaluate(async (selector) => {
        const element = document.querySelector<HTMLFormElement>(selector)!;
        const button = element.querySelector('button')!;

        button.click();
        await new Promise((resolve) => setTimeout(resolve, 50));

        const state = {
            disabled: button.disabled,
            ariaBusy: button.getAttribute('aria-busy'),
        };
        // A second submit before the navigation lands, bypassing the disabled button.
        element.requestSubmit();

        return state;
    }, form);

    expect(busy).toEqual({ disabled: true, ariaBusy: 'true' });

    await expect(page.getByText('signed in')).toBeVisible();

    expect(posts).toHaveLength(1);
    expect(new URL(posts[0]!.url).pathname).toBe('/local-login');
    expect(posts[0]!.body).toBe('_token=harness-token');
});
