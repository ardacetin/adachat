import { execFileSync } from 'node:child_process';
import { expect, test } from './fixtures';

/*
 * Sign-in with OpenID Connect end to end: the button on the login page, the
 * round trip through tests/e2e/mock-provider.mjs (code flow with PKCE, an
 * RS256 ID token) and the new account.
 */

test.beforeAll(() => {
    // The mock provider's user is from example.edu.
    execFileSync(
        'php',
        [
            'artisan',
            'tinker',
            '--execute',
            `$s = app(App\\Domain\\Institution\\Settings\\AuthSettings::class); $s->allowed_domains = array_values(array_unique([...$s->allowed_domains, 'example.edu'])); $s->save();`,
        ],
        { stdio: 'ignore' },
    );
});

test('a user signs in with OpenID Connect', async ({ page }) => {
    await page.goto('/');
    // The landing page's sign-in button goes straight to the provider.
    const back = page.waitForResponse((response) =>
        response.url().includes('/auth/oidc/callback'),
    );
    await page.getByTestId('landing-sign-in').click();
    await back;
    await page.waitForLoadState();

    // A new account sees the usage notice first (only once).
    if (new URL(page.url()).pathname === '/acknowledgment') {
        await page.getByRole('button', { name: 'I understand' }).click();
    }

    await page.waitForURL('/');
    await expect(page.getByText('Zeynep OIDC').first()).toBeVisible();
});
