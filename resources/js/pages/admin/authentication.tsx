import { Head, router, useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import FormField from '@/components/admin/form-field';
import CopyField from '@/components/admin/copy-field';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { edit, oidcTest, update } from '@/routes/admin/authentication';

type Props = {
    settings: {
        allowed_domains: string[];
        auto_provision: boolean;
    };
    saml: {
        acs_url: string;
        entity_id: string;
        metadata_url: string;
        name_id_format: string;
        configured: boolean;
        idp_entity_id: string | null;
        idp_sso_url: string | null;
        certificate: { fingerprint: string; expires_at: string | null } | null;
    };
    oidc: {
        enabled: boolean;
        configured: boolean;
        problem: string | null;
        preset: string;
        issuer: string | null;
        client_id: string | null;
        redirect_uri: string;
        scopes: string;
    };
};

export default function Authentication({ settings, saml, oidc }: Props) {
    const { t } = useTranslation('admin');
    const { t: tCommon } = useTranslation('common');

    const form = useForm({
        allowed_domains: settings.allowed_domains.join('\n'),
        auto_provision: settings.auto_provision,
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.put(update.url(), { preserveScroll: true });
    };

    // Errors arrive per domain (allowed_domains.0, …) or for the whole list.
    const domainErrors = Object.entries(form.errors)
        .filter(([key]) => key.startsWith('allowed_domains'))
        .map(([, message]) => message);

    return (
        <>
            <Head title={t('authentication.title')} />

            <form onSubmit={submit} className="space-y-8">
                <Heading
                    variant="small"
                    title={t('authentication.title')}
                    description={t('authentication.description')}
                />

                <FormField
                    id="allowed_domains"
                    label={t('authentication.allowedDomains')}
                    help={t('authentication.allowedDomainsHelp')}
                >
                    <Textarea
                        id="allowed_domains"
                        rows={4}
                        className="font-mono"
                        value={form.data.allowed_domains}
                        aria-describedby="allowed_domains-help"
                        aria-invalid={domainErrors.length > 0}
                        onChange={(event) =>
                            form.setData('allowed_domains', event.target.value)
                        }
                    />
                    {domainErrors.map((message) => (
                        <InputError key={message} message={message} />
                    ))}
                </FormField>

                <div className="space-y-1">
                    <div className="flex items-center gap-2">
                        <Checkbox
                            id="auto_provision"
                            checked={form.data.auto_provision}
                            aria-describedby="auto_provision-help"
                            onCheckedChange={(checked) =>
                                form.setData('auto_provision', checked === true)
                            }
                        />
                        <Label htmlFor="auto_provision">
                            {t('authentication.autoProvision')}
                        </Label>
                    </div>
                    <p
                        id="auto_provision-help"
                        className="text-xs text-muted-foreground"
                    >
                        {t('authentication.autoProvisionHelp')}
                    </p>
                </div>

                <section className="space-y-4">
                    <div className="space-y-1">
                        <h3 className="text-sm font-medium">
                            {t('authentication.saml.title')}
                        </h3>
                        <p className="text-sm text-muted-foreground">
                            {t('authentication.saml.description')}
                        </p>
                    </div>

                    <CopyField
                        id="saml-acs"
                        label={t('authentication.saml.acsUrl')}
                        value={saml.acs_url}
                    />
                    <CopyField
                        id="saml-entity"
                        label={t('authentication.saml.entityId')}
                        value={saml.entity_id}
                    />
                    <p className="text-xs text-muted-foreground">
                        {t('authentication.saml.nameIdHelp', {
                            format: saml.name_id_format,
                        })}
                    </p>

                    <div className="space-y-2 rounded-lg border p-4">
                        <div className="flex items-center gap-2">
                            <span className="text-sm font-medium">
                                {t('authentication.saml.idp')}
                            </span>
                            <Badge
                                variant={
                                    saml.configured ? 'secondary' : 'outline'
                                }
                            >
                                {saml.configured
                                    ? t('authentication.providerConfigured')
                                    : t('authentication.saml.notConfigured')}
                            </Badge>
                        </div>
                        {saml.configured ? (
                            <dl className="grid gap-1 text-xs">
                                <dt className="text-muted-foreground">
                                    {t('authentication.saml.idpEntityId')}
                                </dt>
                                <dd className="font-mono break-all">
                                    {saml.idp_entity_id}
                                </dd>
                                <dt className="text-muted-foreground">
                                    {t('authentication.saml.idpSsoUrl')}
                                </dt>
                                <dd className="font-mono break-all">
                                    {saml.idp_sso_url}
                                </dd>
                                {saml.certificate && (
                                    <>
                                        <dt className="text-muted-foreground">
                                            {t(
                                                'authentication.saml.certificate',
                                            )}
                                        </dt>
                                        <dd className="font-mono break-all">
                                            {saml.certificate.fingerprint}
                                            {saml.certificate.expires_at &&
                                                ` · ${t('authentication.saml.expires', { date: saml.certificate.expires_at })}`}
                                        </dd>
                                    </>
                                )}
                            </dl>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                {t('authentication.noProviders')}
                            </p>
                        )}
                        <p className="text-xs text-muted-foreground">
                            {t('authentication.secretsNote')}
                        </p>
                    </div>
                </section>

                <section className="space-y-4" data-test="oidc-section">
                    <div className="space-y-1">
                        <h3 className="text-sm font-medium">
                            {t('authentication.oidc.title')}
                        </h3>
                        <p className="text-sm text-muted-foreground">
                            {t('authentication.oidc.description')}
                        </p>
                    </div>

                    <CopyField
                        id="oidc-redirect"
                        label={t('authentication.oidc.redirectUri')}
                        value={oidc.redirect_uri}
                    />

                    <div className="space-y-2 rounded-lg border p-4">
                        <div className="flex items-center gap-2">
                            <span className="text-sm font-medium">
                                {t('authentication.oidc.status')}
                            </span>
                            <Badge
                                variant={
                                    oidc.configured
                                        ? 'secondary'
                                        : oidc.enabled
                                          ? 'destructive'
                                          : 'outline'
                                }
                            >
                                {oidc.configured
                                    ? t('authentication.providerConfigured')
                                    : oidc.enabled
                                      ? t('authentication.oidc.invalid')
                                      : t('authentication.oidc.off')}
                            </Badge>
                        </div>
                        {oidc.enabled ? (
                            <>
                                {oidc.problem && (
                                    <p
                                        className="text-sm text-destructive"
                                        role="alert"
                                    >
                                        {oidc.problem}
                                    </p>
                                )}
                                <dl className="grid gap-1 text-xs">
                                    <dt className="text-muted-foreground">
                                        {t('authentication.oidc.preset')}
                                    </dt>
                                    <dd>
                                        {oidc.preset === 'entra'
                                            ? t(
                                                  'authentication.oidc.presetEntra',
                                              )
                                            : t(
                                                  'authentication.oidc.presetGeneric',
                                              )}
                                    </dd>
                                    <dt className="text-muted-foreground">
                                        {t('authentication.oidc.issuer')}
                                    </dt>
                                    <dd className="font-mono break-all">
                                        {oidc.issuer ?? '—'}
                                    </dd>
                                    <dt className="text-muted-foreground">
                                        {t('authentication.oidc.clientId')}
                                    </dt>
                                    <dd className="font-mono break-all">
                                        {oidc.client_id ?? '—'}
                                    </dd>
                                    <dt className="text-muted-foreground">
                                        {t('authentication.oidc.scopes')}
                                    </dt>
                                    <dd className="font-mono">{oidc.scopes}</dd>
                                </dl>
                                {oidc.configured && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            router.post(
                                                oidcTest.url(),
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        {t('authentication.oidc.test')}
                                    </Button>
                                )}
                            </>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                {t('authentication.oidc.notConfigured')}
                            </p>
                        )}
                        <p className="text-xs text-muted-foreground">
                            {t('authentication.secretsNote')}
                        </p>
                    </div>
                </section>

                <Button type="submit" disabled={form.processing}>
                    {tCommon('actions.save')}
                </Button>
            </form>
        </>
    );
}

Authentication.layout = {
    breadcrumbs: [{ titleKey: 'admin:authentication.title', href: edit() }],
};
