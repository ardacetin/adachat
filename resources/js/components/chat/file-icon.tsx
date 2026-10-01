import { FileText, Presentation, Sheet } from 'lucide-react';
import { cn } from '@/lib/utils';

const SHEET =
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
const SLIDES =
    'application/vnd.openxmlformats-officedocument.presentationml.presentation';

/** An icon for a non-image attachment, by its detected type. */
export default function FileIcon({
    mime,
    className,
}: {
    mime?: string;
    className?: string;
}) {
    const classes = cn('size-4 text-muted-foreground', className);

    if (mime === SHEET) {
        return <Sheet className={classes} />;
    }

    if (mime === SLIDES) {
        return <Presentation className={classes} />;
    }

    return <FileText className={classes} />;
}
