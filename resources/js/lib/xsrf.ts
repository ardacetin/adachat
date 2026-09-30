/**
 * Laravel's XSRF-TOKEN cookie, sent back as the X-XSRF-TOKEN header on
 * requests made outside Inertia (fetch).
 */
export function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}
