import { expect, test as base } from '@playwright/test';

/**
 * Every spec fails on a Content Security Policy violation or an uncaught
 * error in the page: the policy is active in these tests (built assets).
 */
export const test = base.extend<{ pageProblems: string[] }>({
    pageProblems: [
        async ({ page }, use) => {
            const problems: string[] = [];

            page.on('pageerror', (error) => problems.push(error.message));
            page.on('console', (message) => {
                if (
                    message.type() === 'error' &&
                    /Content Security Policy|Refused to (load|execute|apply)/i.test(
                        message.text(),
                    )
                ) {
                    problems.push(message.text());
                }
            });

            await use(problems);

            expect(problems).toEqual([]);
        },
        { auto: true },
    ],
});

export { expect };
