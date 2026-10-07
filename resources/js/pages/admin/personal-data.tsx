import { Head, useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import FormField from '@/components/admin/form-field';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { Textarea } from '@/components/ui/textarea';
import { xsrfToken } from '@/lib/xsrf';
import { test, update } from '@/routes/admin/personal-data';

type Action = 'off' | 'warn' | 'mask' | 'block';

type Pattern = { name: string; pattern: string; action: Action };

const KINDS = ['tckn', 'iban', 'card', 'phone', 'email'] as const;

type Kind = (typeof KINDS)[number];

type Props = {
    rules: Record<Kind, Action>;
    patterns: Pattern[];
};

type TestResult = {
    found: { kind: string; action: Action; value: string }[];
    sent: string;
};

const ACTIONS: Action[] = ['off', 'warn', 'mask', 'block'];

function ActionSelect({
    id,
    label,
    value,
    onChange,
}: {
    id: string;
    label: string;
    value: Action;
    onChange: (value: Action) => void;
}) {
    const { t } = useTranslation('admin');

    return (
        <Select
            value={value}
            onValueChange={(next) => onChange(next as Action)}
        >
            <SelectTrigger id={id} aria-label={label} className="w-44">
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                {ACTIONS.map((action) => (
                    <SelectItem key={action} value={action}>
                        {t(`personalData.actions.${action}`)}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

export default function PersonalData({ rules, patterns }: Props) {
    const { t } = useTranslation('admin');
    const { t: tCommon } = useTranslation('common');
    const form = useForm({ rules, patterns });
    const [sample, setSample] = useState('');
    const [result, setResult] = useState<TestResult | null>(null);
    const [testError, setTestError] = useState<string | null>(null);
    const errors = form.errors as Record<string, string | undefined>;

    const setPattern = (index: number, changes: Partial<Pattern>) =>
        form.setData(
            'patterns',
            form.data.patterns.map((pattern, current) =>
                current === index ? { ...pattern, ...changes } : pattern,
            ),
        );

    const runTest = async () => {
        setTestError(null);
        const response = await fetch(test.url(), {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': xsrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: JSON.stringify({ ...form.data, sample }),
        });
        const body = (await response.json()) as TestResult & {
            message?: string;
        };

        if (response.ok) {
            setResult(body);
        } else {
            setResult(null);
            setTestError(body.message ?? t('personalData.testFailed'));
        }
    };

    return (
        <>
            <Head title={t('personalData.title')} />

            <form
                className="space-y-8"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.put(update.url(), { preserveScroll: true });
                }}
            >
                <Heading
                    variant="small"
                    title={t('personalData.title')}
                    description={t('personalData.description')}
                />

                <ul className="space-y-2 text-xs text-muted-foreground">
                    {ACTIONS.filter((action) => action !== 'off').map(
                        (action) => (
                            <li key={action}>
                                <span className="font-medium text-foreground">
                                    {t(`personalData.actions.${action}`)}:
                                </span>{' '}
                                {t(`personalData.actionHelp.${action}`)}
                            </li>
                        ),
                    )}
                </ul>

                <section className="space-y-3">
                    <h3 className="text-sm font-medium">
                        {t('personalData.builtIn')}
                    </h3>
                    {KINDS.map((kind) => (
                        <div
                            key={kind}
                            className="flex flex-wrap items-center justify-between gap-2"
                        >
                            <div>
                                <p className="text-sm">
                                    {t(`personalData.kinds.${kind}`)}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    {t(`personalData.kindHelp.${kind}`)}
                                </p>
                            </div>
                            <ActionSelect
                                id={`rule-${kind}`}
                                label={t(`personalData.kinds.${kind}`)}
                                value={form.data.rules[kind]}
                                onChange={(value) =>
                                    form.setData('rules', {
                                        ...form.data.rules,
                                        [kind]: value,
                                    })
                                }
                            />
                        </div>
                    ))}
                </section>

                <Separator />

                <section className="space-y-3">
                    <div className="space-y-1">
                        <h3 className="text-sm font-medium">
                            {t('personalData.patterns')}
                        </h3>
                        <p className="text-sm text-muted-foreground">
                            {t('personalData.patternsHelp')}
                        </p>
                    </div>
                    {form.data.patterns.map((pattern, index) => (
                        <div
                            key={index}
                            className="grid gap-2 sm:grid-cols-[10rem_1fr_auto_auto] sm:items-start"
                        >
                            <div>
                                <Input
                                    aria-label={t('personalData.patternName')}
                                    placeholder={t('personalData.patternName')}
                                    value={pattern.name}
                                    maxLength={40}
                                    onChange={(event) =>
                                        setPattern(index, {
                                            name: event.target.value,
                                        })
                                    }
                                />
                                <InputError
                                    message={errors[`patterns.${index}.name`]}
                                />
                            </div>
                            <div>
                                <Input
                                    aria-label={t('personalData.pattern')}
                                    placeholder={t(
                                        'personalData.patternPlaceholder',
                                    )}
                                    className="font-mono"
                                    value={pattern.pattern}
                                    maxLength={200}
                                    onChange={(event) =>
                                        setPattern(index, {
                                            pattern: event.target.value,
                                        })
                                    }
                                />
                                <InputError
                                    message={
                                        errors[`patterns.${index}.pattern`]
                                    }
                                />
                            </div>
                            <ActionSelect
                                id={`pattern-${index}-action`}
                                label={t('personalData.patternAction', {
                                    name: pattern.name,
                                })}
                                value={pattern.action}
                                onChange={(action) =>
                                    setPattern(index, { action })
                                }
                            />
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                aria-label={t('personalData.removePattern')}
                                onClick={() =>
                                    form.setData(
                                        'patterns',
                                        form.data.patterns.filter(
                                            (_, current) => current !== index,
                                        ),
                                    )
                                }
                            >
                                <Trash2 className="size-4" />
                            </Button>
                        </div>
                    ))}
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={form.data.patterns.length >= 20}
                        onClick={() =>
                            form.setData('patterns', [
                                ...form.data.patterns,
                                { name: '', pattern: '', action: 'mask' },
                            ])
                        }
                    >
                        <Plus className="size-4" />
                        {t('personalData.addPattern')}
                    </Button>
                </section>

                <Button type="submit" disabled={form.processing}>
                    {tCommon('actions.save')}
                </Button>
            </form>

            <Separator className="my-8" />

            <section className="space-y-3" data-test="personal-data-test">
                <FormField
                    id="sample"
                    label={t('personalData.test')}
                    help={t('personalData.testHelp')}
                    error={testError ?? undefined}
                >
                    <Textarea
                        id="sample"
                        rows={3}
                        value={sample}
                        maxLength={5000}
                        aria-describedby="sample-help"
                        onChange={(event) => setSample(event.target.value)}
                    />
                </FormField>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={sample.trim() === ''}
                    onClick={() => void runTest()}
                >
                    {t('personalData.runTest')}
                </Button>
                {result && (
                    <div className="space-y-2 text-sm">
                        <p>
                            {result.found.length === 0
                                ? t('personalData.nothingFound')
                                : result.found
                                      .map(
                                          (found) =>
                                              `${found.kind} (${t(`personalData.actions.${found.action}`)}): ${found.value}`,
                                      )
                                      .join(' · ')}
                        </p>
                        <p className="text-xs text-muted-foreground">
                            {t('personalData.sent')}
                        </p>
                        <pre className="rounded-md bg-muted p-3 text-xs whitespace-pre-wrap">
                            {result.sent}
                        </pre>
                    </div>
                )}
            </section>
        </>
    );
}
