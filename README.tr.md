# Ada Chat

**Ada Chat**; üniversiteler ve kurumlar için merkezi kimlik doğrulama, çoklu
AI sağlayıcı erişimi, kullanıcı bazlı bütçeler, kullanım muhasebesi ve yönetim
araçları sunan, açık kaynaklı ve kurumun kendi sunucusunda çalışan (self-hosted)
bir kurumsal AI gateway ve sohbet platformudur.

[English](README.md)

> **Durum: erken geliştirme.** M0–M7 kilometre taşları tamamlandı: temel
> altyapı, Google Workspace (SAML) ile giriş, kurum ayarları ve marka, AI
> sağlayıcıları ve model takma adları, bütçe motoru, akışlı sohbet ve
> gruplar, bütçe politikaları ve kullanıcı kullanımı. Sıradaki: kullanıcı
> yönetimi ve denetim kaydı (M8). Mimari [`docs/`](docs/) klasöründe (İngilizce) belgelenmiştir; bkz.
> [yol haritası](docs/v1-roadmap.md).

## Neden Ada?

Personel ve akademisyenler ChatGPT, Claude, Gemini gibi servisler için ayrı
ayrı kurumsal abonelik talep ediyor. Kişi başı abonelik pahalıdır, yönetimi ve
raporlanması zordur, güvenlik politikalarını merkezi olarak uygulamayı
imkânsızlaştırır.

Ada ile kurum, AI sağlayıcılarının API hesaplarını bir kez tanımlar ve
herkese kontrollü erişim sağlar:

- Personel kurumsal hesabıyla giriş yapar (V1'de Google Workspace).
- Kurumun izin verdiği modelleri "Hızlı", "Gelişmiş" gibi sade isimlerle kullanır.
- Her kullanıcının USD cinsinden aylık bütçesi vardır (ör. 10 USD). Bütçe
  bittiğinde yeni istek gönderilemez; her ay otomatik yenilenir.
- Yöneticiler harcama ve kullanımı görür — konuşma içeriklerini görmez.

## Planlanan V1 özellikleri

- İzin verilen domainlerle sınırlı Google Workspace girişi (SAML 2.0)
- Türkçe ve İngilizce arayüz, karanlık mod, kurum markalaması
- Roller (super admin, admin, user) ve gruplar
- Provider abstraction üzerinden OpenAI, Anthropic ve Google Gemini
- Model kaydı, model alias'ları ve grup bazlı model izinleri
- Streaming sohbet, Markdown gösterimi, konuşma geçmişi
- Sert sınırlı aylık USD bütçeleri (provider token sayımı, rezervasyon,
  çıktı limitinin kalan bütçeye göre düşürülmesi)
- Fiyat snapshot'lı kullanım muhasebesi, raporlar ve yönetim paneli
- Şifrelenmiş provider anahtarları ve audit log
- Ubuntu + Nginx + PHP-FPM + MySQL + Redis ya da Docker ile kurulum

V1 kapsamı dışında: RAG, web arama, agent'lar, araçlar, görsel üretimi, ses,
dosya yükleme ve multi-tenant SaaS.

## Teknoloji

PHP 8.4+, Laravel 13, MySQL 8.4 LTS, Redis, Inertia.js, React, TypeScript,
Tailwind CSS ve shadcn/ui — tek uygulama olarak deploy edilir.

## Geliştirme

Gereksinimler: PHP 8.4+, Composer, Node.js 22+, Docker (MySQL/Redis/Mailpit
için) veya yerel MySQL 8.4.

```bash
docker compose up -d            # MySQL 8.4, Redis, Mailpit
cp .env.example .env            # yerel giriş için ADA_DEV_LOGIN=true yapın
composer install && npm install
php artisan key:generate
php artisan migrate --seed      # örnek kullanıcıları oluşturur
php artisan storage:link        # yüklenen logoları yayınlar
composer dev                    # uygulama: http://localhost:8000
```

Giriş, Google Workspace'teki özel bir SAML uygulamasıyla SAML 2.0 üzerinden
yapılır: `SAML_IDP_ENTITY_ID`, `SAML_IDP_SSO_URL`, `SAML_IDP_CERT` ve
`AUTH_ALLOWED_DOMAINS` değerlerini ayarlayın; Google Admin'e Ada'nın ACS
URL'sini (`<APP_URL>/auth/saml/acs`) ve varlık kimliğini
(`<APP_URL>/auth/saml/metadata`) girin (bkz. [kimlik doğrulama](docs/authentication.md#setting-up-the-google-workspace-saml-app));
yerelde geliştirme girişini de kullanabilirsiniz. Tüm kontroller:
`composer ci:check`.

Bütçe işleri (yarım kalan rezervasyonların süresinin dolması, günlük
mutabakat) Laravel zamanlayıcısıyla çalışır: yerelde `php artisan
schedule:work`, üretimde her dakika `php artisan schedule:run` çalıştıran bir
cron girdisi. Varsayılan bütçe politikasının aylık limiti kurulumda
`ADA_DEFAULT_MONTHLY_LIMIT_USD` değerinden alınır.

## Belgeler

| Belge | İçerik |
|---|---|
| [Mimari](docs/architecture.md) | Genel tasarım, domain sınırları, istek ve streaming akışı, deployment |
| [Veritabanı tasarımı](docs/database-design.md) | Tablolar, ilişkiler, index'ler, para hassasiyeti, MySQL kuralları |
| [Bütçe motoru](docs/budget-engine.md) | Bütçe dönemleri, token sayımı, rezervasyon, settlement, eşzamanlılık |
| [Kimlik doğrulama](docs/authentication.md) | Google Workspace akışı, roller, gelecekte OIDC/SAML/LDAP |
| [Provider mimarisi](docs/provider-architecture.md) | Provider arayüzü, adapter'lar, token sayaçları, kullanım normalizasyonu |
| [Frontend mimarisi](docs/frontend-architecture.md) | React/Inertia yapısı, streaming state, i18n, tema |
| [Güvenlik](docs/security.md) | Tehditler ve kontroller |
| [V1 yol haritası](docs/v1-roadmap.md) | Milestone'lar |

## Kurumdan bağımsız tasarım

Ada'nın kodunda hiçbir kuruma ait isim, domain, renk veya logo bulunmaz. Her
kurum kendi adını, markasını, izinli domainlerini, provider'larını,
modellerini ve bütçelerini yapılandırır. İlk production kurulumu Beykoz
Üniversitesi'nde yapılacaktır; ancak Ada her kurum için geliştirilmektedir.

## Katkı

[CONTRIBUTING.md](CONTRIBUTING.md) dosyasına bakın. Güvenlik açıkları için
[SECURITY.md](SECURITY.md) — lütfen açıkları public issue olarak bildirmeyin.

## Lisans

Ada Chat, [GNU Affero General Public License v3.0 veya sonrası](LICENSE)
(`AGPL-3.0-or-later`) ile lisanslanmıştır. Ada'nın değiştirilmiş bir sürümünü
kullanıcılara ağ üzerinden sunarsanız, değiştirilmiş sürümün kaynak kodunu bu
kullanıcılara erişilebilir kılmanız gerekir.
