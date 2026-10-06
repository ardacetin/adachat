# Ada Chat

**Ada Chat**; üniversiteler ve kurumlar için merkezi kimlik doğrulama, çoklu
AI sağlayıcı erişimi, kullanıcı bazlı bütçeler, kullanım muhasebesi ve yönetim
araçları sunan, açık kaynaklı ve kurumun kendi sunucusunda çalışan (self-hosted)
bir kurumsal AI gateway ve sohbet platformudur.

[English](README.md)

> **Durum: 1.4.0** ([sürüm notları](docs/releases/v1.4.0.md#türkçe-özet)): karşılama
> sayfası, eklenen kullanıcılara hoş geldin e-postası ve ikisinin metnini
> düzenleyen yönetim sayfası. 1.3 sürümü
> sağlayıcıların kendi araçlarıyla web araması, kullanıcılara bütçe uyarıları,
> memnuniyet raporlu yanıt geri bildirimi ve salt okunur sohbet paylaşımı
> getirdi. 1.2 sürümü
> fiyat kataloğuyla kolay model fiyatlandırması, Microsoft Entra ID ve OpenID
> Connect ile giriş, e-posta adresiyle kullanıcı ekleme, sohbetlerde arama,
> sabitleme ve dışa aktarma, talimat ve belgeli kurum içi asistanlar getirmişti.
> 1.1 sürümü kurum geneli harcama tavanı, CSV dışa aktarma ve aylık rapor,
> OpenAI uyumlu sunucular ve sohbette dosya ekleri getirmişti. 1.0'ın M0–M11
> kilometre taşları: temel altyapı, Google Workspace (SAML) ile giriş, kurum
> ayarları ve marka, AI sağlayıcıları ve model takma adları, bütçe motoru,
> akışlı sohbet, gruplar, bütçe politikaları ve kullanıcı kullanımı,
> kullanıcı yönetimi, denetim kaydı, gösterge paneli ve raporlar,
> sağlamlaştırma ve üretim kurulumu (Docker imajı, sunucu rehberi).
> Kurulum: [docs/deployment.tr.md](docs/deployment.tr.md). Mimari [`docs/`](docs/) klasöründe (İngilizce) belgelenmiştir; bkz.
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

**İsim.** Ada, adını
[Ada Lovelace](https://tr.wikipedia.org/wiki/Ada_Lovelace)'tan (1815–1852)
alır. Lovelace, Charles Babbage'ın Analitik Makinesi için ilk bilgisayar
programı sayılan algoritmayı yazmış ve bu makinelerin sayıların ötesinde de
iş görebileceğini öngörmüştür.

## Özellikler

- Kurum içi asistanlar (bir model üzerine talimatlar ve belgeler, gruba
  göre) ve
  sohbetlerde arama, sabitleme, Markdown/PDF dışa aktarma
- Oturum açmış meslektaşlar için sohbetin salt okunur anlık kopyasına bağlantı
- Yanıt geri bildirimi (beğen / beğenme) ve içeriksiz memnuniyet raporu
- İzin verilen domainlerle sınırlı giriş: Google Workspace (SAML 2.0) ve/veya
  Microsoft Entra ID ile diğer OpenID Connect sağlayıcıları
- Türkçe ve İngilizce arayüz, karanlık mod, kurum markalaması
- Roller (super admin, admin, user) ve gruplar
- Provider abstraction üzerinden OpenAI, Anthropic ve Google Gemini; ayrıca
  OpenAI uyumlu sunucular (OpenRouter, Groq, Ollama, vLLM, LM Studio)
- Model kaydı, model alias'ları ve grup bazlı model izinleri
- Streaming sohbet, Markdown gösterimi, konuşma geçmişi
- Dosya ekleri: görsel, PDF, Word, Excel, PowerPoint, metin ve kod dosyaları
- Sağlayıcıların kendi arama araçlarıyla, kaynak gösteren web araması; her
  mesajda ayrıca açılır ve bütçeden düşer
- Sert sınırlı aylık USD bütçeleri (provider token sayımı, rezervasyon,
  çıktı limitinin kalan bütçeye göre düşürülmesi); %80 ve %100'de
  kullanıcıya uyarı
- Fiyat snapshot'lı kullanım muhasebesi, raporlar ve yönetim paneli
- Kurum geneli aylık harcama tavanı, CSV dışa aktarma ve aylık e-posta raporu
- Şifrelenmiş provider anahtarları ve audit log
- Ubuntu + Nginx + PHP-FPM + MySQL + Redis ya da Docker ile kurulum

Kapsam dışında: RAG, agent'lar, web araması dışında araçlar, görsel üretimi,
ses ve multi-tenant SaaS.

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
OpenID Connect (Microsoft Entra ID ve diğerleri) `OIDC_*` değerleriyle
kurulur ([kimlik doğrulama §1a](docs/authentication.md#1a-openid-connect-microsoft-entra-id-generic));
yerelde geliştirme girişini de kullanabilirsiniz. Tüm kontroller:
`composer ci:check`.

Bütçe işleri (yarım kalan rezervasyonların süresinin dolması, günlük
mutabakat) ve gece çalışan saklama temizliği (`ada:retention:prune`) Laravel
zamanlayıcısıyla çalışır: yerelde `php artisan
schedule:work`, üretimde her dakika `php artisan schedule:run` çalıştıran bir
cron girdisi. Varsayılan bütçe politikasının aylık limiti kurulumda
`ADA_DEFAULT_MONTHLY_LIMIT_USD` değerinden alınır. `php artisan ada:doctor`
çalışan bir kurulumu (ayarlar, veritabanı, zamanlayıcı, giriş, yapay zekâ
sağlayıcıları) kontrol eder ve düzeltilmesi gereken bir şey varsa hata koduyla
biter.

## Belgeler

| Belge | İçerik |
|---|---|
| [Mimari](docs/architecture.md) | Genel tasarım, domain sınırları, istek ve streaming akışı, deployment |
| [Veritabanı tasarımı](docs/database-design.md) | Tablolar, ilişkiler, index'ler, para hassasiyeti, MySQL kuralları |
| [Bütçe motoru](docs/budget-engine.md) | Bütçe dönemleri, token sayımı, rezervasyon, settlement, eşzamanlılık |
| [Kimlik doğrulama](docs/authentication.md) | SAML (Google Workspace) ve OpenID Connect (Entra ID) ile giriş, roller |
| [Yanıt geri bildirimi](docs/feedback.md) | Beğen / beğenme ve içeriksiz memnuniyet raporu |
| [Sohbet paylaşımı](docs/sharing.md) | Oturum açmış kullanıcılar için sohbetin salt okunur anlık kopyasına bağlantı |
| [Asistanlar](docs/assistants.md) | Kurum içi asistanlar: talimatlar, erişim, sohbetler |
| [Provider mimarisi](docs/provider-architecture.md) | Provider arayüzü, adapter'lar, token sayaçları, kullanım normalizasyonu |
| [Frontend mimarisi](docs/frontend-architecture.md) | React/Inertia yapısı, streaming state, i18n, tema |
| [Güvenlik](docs/security.md) | Tehditler ve kontroller |
| [Güvenlik incelemesi](docs/security-review.md) | Dahili OWASP ASVS Seviye 2 incelemesi |
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
