import { Link } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import type { Paginated } from '@/types/pagination';

/** Previous / next links for a paginated list; filters stay in the URL. */
export default function Pagination({ page }: { page: Paginated<unknown> }) {
    const { t } = useTranslation('admin');

    if (page.last_page <= 1) {
        return null;
    }

    return (
        <nav
            className="flex items-center justify-between gap-4 text-sm"
            aria-label={t('common.page', {
                page: page.current_page,
                last: page.last_page,
            })}
        >
            <span className="text-muted-foreground">
                {t('common.page', {
                    page: page.current_page,
                    last: page.last_page,
                })}
            </span>
            <div className="flex gap-2">
                <Button
                    asChild={page.prev_page_url !== null}
                    variant="outline"
                    size="sm"
                    disabled={page.prev_page_url === null}
                >
                    {page.prev_page_url === null ? (
                        <span>{t('common.previous')}</span>
                    ) : (
                        <Link href={page.prev_page_url} preserveScroll>
                            {t('common.previous')}
                        </Link>
                    )}
                </Button>
                <Button
                    asChild={page.next_page_url !== null}
                    variant="outline"
                    size="sm"
                    disabled={page.next_page_url === null}
                >
                    {page.next_page_url === null ? (
                        <span>{t('common.next')}</span>
                    ) : (
                        <Link href={page.next_page_url} preserveScroll>
                            {t('common.next')}
                        </Link>
                    )}
                </Button>
            </div>
        </nav>
    );
}
