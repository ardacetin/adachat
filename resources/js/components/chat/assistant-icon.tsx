import {
    BookOpen,
    Briefcase,
    Calculator,
    Code,
    FlaskConical,
    GraduationCap,
    HeartPulse,
    Languages,
    LifeBuoy,
    PenLine,
    Scale,
    Sparkles,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

/** The icons an administrator can choose for an assistant (Assistant::ICONS). */
export const ASSISTANT_ICONS: Record<string, LucideIcon> = {
    sparkles: Sparkles,
    'book-open': BookOpen,
    'graduation-cap': GraduationCap,
    scale: Scale,
    languages: Languages,
    code: Code,
    calculator: Calculator,
    'flask-conical': FlaskConical,
    'heart-pulse': HeartPulse,
    briefcase: Briefcase,
    'pen-line': PenLine,
    'life-buoy': LifeBuoy,
};

export default function AssistantIcon({
    name,
    className,
}: {
    name: string;
    className?: string;
}) {
    const Icon = ASSISTANT_ICONS[name] ?? Sparkles;

    return <Icon className={className} aria-hidden />;
}
