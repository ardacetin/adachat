# Kurulum ve işletim

> Ada Chat'i üretimde çalıştırma, ayakta tutma ve güncelleme.
> İngilizce: [deployment.md](deployment.md). İki belge aynı içeriği taşır;
> komutlar ve dosya adları aynıdır.

Ada ya Docker ile (tek imaj, `compose.production.yml`) ya da doğrudan bir
Ubuntu 24.04 sunucusunda çalışır. İkisinde de çalışan parçalar aynıdır:

| Parça | Görevi |
|---|---|
| nginx | HTTPS, statik dosyalar, istekleri PHP-FPM'e iletir; akan sohbet yanıtlarını arabelleğe almamalıdır |
| PHP-FPM (PHP 8.4) | Uygulama; akan her yanıt bitene kadar bir işçiyi (worker) meşgul eder |
| MySQL 8.4 LTS | Tüm veriler; desteklenen tek veritabanı |
| Redis | Oturumlar ve önbellek (bütçeler Redis'e hiç bağlı değildir) |
| Zamanlayıcı | Her dakika `php artisan schedule:run`: bütçe bakımı, gece mutabakatı ve saklama süresi temizliği |

V1'de kuyruk işçisi (queue worker) yoktur: zamanlayıcı dışında arka planda
işlenen bir şey yoktur.

## 1. Boyutlandırma

Sunucunun asıl işi yapay zekâ sağlayıcılarını beklemektir; bu yüzden işlemci
gücünden çok PHP-FPM işçi sayısı önemlidir. Her sohbet yanıtı akış boyunca bir
işçiyi meşgul eder (genellikle 5–60 saniye, en fazla `ADA_PROVIDER_TIMEOUT`,
300 sn).

**Pratik kural:** `pm.max_children` = en yoğun anda aynı anda akan yanıt
sayısı + sayfa yüklemeleri için 10. Her işçi yaklaşık 40–60 MB bellek ister.

| En yoğun anda aktif kullanıcı | Aynı anda akan yanıt | `pm.max_children` | Sunucu |
|---|---|---|---|
| 100'e kadar | ~5 | 16 | 2 vCPU, 4 GB RAM |
| 500'e kadar | ~20 | 32 | 4 vCPU, 8 GB RAM |
| 2.000'e kadar | ~60 | 80 | 8 vCPU, 16 GB RAM |

Tüm işçiler meşgulken yeni istekler bir işçi boşalana kadar bekler;
kullanıcılar sayfayı yavaş görür. Bütçe politikalarındaki "Eşzamanlı yanıt"
ayarı (Yönetim → Bütçe politikaları) bir kullanıcının aynı anda kaç yanıt
üretebileceğini sınırlar. MySQL az kaynak ister: 2 GB RAM ve 20 GB disk uzun
süre yeter (mesajlar metindir).

## 2. Docker ile

Gerekenler: Compose eklentisiyle Docker Engine 24+, HTTPS için sunucuda bir
nginx (veya Caddy, Apache).

```bash
git clone https://github.com/ardacetin/adachat.git /opt/ada && cd /opt/ada
git checkout <son sürüm etiketi>
cp deploy/env.production.example .env
docker run --rm php:8.4-cli php -r 'echo "base64:".base64_encode(random_bytes(32)), PHP_EOL;'
#   → çıktıyı APP_KEY'e yazın ve bir kopyasını çevrimdışı saklayın (bkz. §7)
nano .env                                   # CHANGE ile işaretli her değer
docker compose -f compose.production.yml up -d --build
docker compose -f compose.production.yml exec app php artisan ada:install
docker compose -f compose.production.yml exec app php artisan ada:user:promote siz@kurum.edu.tr --role=super_admin
docker compose -f compose.production.yml exec app php artisan ada:doctor
```

- `app` konteyneri nginx ve PHP-FPM'i yetkisiz bir kullanıcıyla çalıştırır ve
  açılışta veritabanı migration'larını uygular. `scheduler` konteyneri aynı
  imajdan `php artisan schedule:work` çalıştırır.
- Veriler `ada_mysql` (veritabanı), `ada_storage` (logolar, sohbet ekleri,
  derlenmiş görünümler) ve `ada_redis` birimlerinde (volume) durur.
- Uygulama `127.0.0.1:8080` adresini dinler. Önüne sunucudaki nginx'i koyun:
  [`deploy/nginx/ada-docker-proxy.conf`](../deploy/nginx/ada-docker-proxy.conf).
  Compose dosyası `TRUSTED_PROXIES=*` ayarlar; port sunucunun dışından
  erişilemediği için bu güvenlidir. Portu başka türlü yayınlarsanız
  değiştirin.
- İşçi sayısı: `.env` içinde `PHP_FPM_MAX_CHILDREN` (varsayılan 24, bkz. §1).
- Loglar: `docker compose -f compose.production.yml logs -f app`, her satırda
  bir JSON nesnesi.

## 3. Docker olmadan (Ubuntu 24.04)

### 3.1 Paketler

```bash
sudo add-apt-repository ppa:ondrej/php      # PHP 8.4
sudo apt install nginx redis-server unzip git \
    php8.4-fpm php8.4-cli php8.4-mysql php8.4-intl php8.4-mbstring php8.4-xml \
    php8.4-curl php8.4-zip php8.4-bcmath php8.4-redis php8.4-opcache
```

- **MySQL 8.4:** Ubuntu 24.04 MySQL 8.0 ile gelir. 8.4 LTS'i MySQL APT
  deposundan (dev.mysql.com/downloads/repo/apt) kurun ya da yönetilen/ayrı
  bir veritabanı sunucusu kullanın. `ada:doctor` eski sürümlerde uyarır.
- **Composer:** getcomposer.org/download.
- **Node.js 22** yalnızca ön yüzü derlemek için gerekir (nodesource veya
  nvm). Başka bir makinede derleyip `public/build` klasörünü de
  kopyalayabilirsiniz.

Veritabanı ve kullanıcı (saat dilimini Ada her bağlantıda kendisi ayarlar):

```sql
CREATE DATABASE ada CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE USER 'ada'@'localhost' IDENTIFIED BY '<uzun rastgele parola>';
GRANT ALL PRIVILEGES ON ada.* TO 'ada'@'localhost';
```

### 3.2 Uygulama

```bash
sudo git clone https://github.com/ardacetin/adachat.git /var/www/ada
sudo chown -R $USER:www-data /var/www/ada && cd /var/www/ada
git checkout <son sürüm etiketi>
composer install --no-dev --optimize-autoloader
npm ci && npm run build
cp deploy/env.production.example .env && nano .env   # CHANGE ile işaretli her değer
php artisan key:generate                              # sonra APP_KEY'i çevrimdışı saklayın (§7)
php artisan migrate --force
php artisan storage:link
php artisan ada:install
php artisan optimize
sudo chown -R www-data:www-data storage bootstrap/cache
php artisan ada:user:promote siz@kurum.edu.tr --role=super_admin
```

### 3.3 PHP-FPM, nginx, zamanlayıcı

```bash
sudo cp deploy/php-fpm/ada.conf /etc/php/8.4/fpm/pool.d/ada.conf   # pm.max_children'ı ayarlayın (§1)
sudo systemctl restart php8.4-fpm
sudo cp deploy/nginx/ada.conf /etc/nginx/sites-available/ada        # ai.example.edu'yu değiştirin
sudo ln -s /etc/nginx/sites-available/ada /etc/nginx/sites-enabled/ada
sudo certbot --nginx -d ai.kurum.edu.tr                             # veya kurumun sertifikası
sudo nginx -t && sudo systemctl reload nginx
sudo cp deploy/cron/ada /etc/cron.d/ada
sudo -u www-data php artisan ada:doctor                             # bir dakika sonra: zamanlayıcı OK
```

Önemli nginx ayarları (örnekte zaten var): akan yanıtlar için
`fastcgi_read_timeout 420s` ve arabellekleme olmaması — Ada
`X-Accel-Buffering: no` başlığını gönderir, nginx buna uyar — ve
`fastcgi_buffer_size 32k`: Ada'nın yanıt başlıkları (varlık ön yükleme
bağlantıları, güvenlik politikası) nginx'in varsayılan 4–8 KB'ından büyüktür;
aksi halde "502 Bad Gateway" ve nginx hata kaydında
`upstream sent too big header` görülür. Tarayıcı ile
nginx arasındaki başka bir katman (yük dengeleyici, WAF, Cloudflare) da
yanıtları arabelleğe almamalıdır; yoksa yanıtlar sonunda tek parça halinde
görünür.

Burada nginx PHP-FPM ile doğrudan konuşur; `TRUSTED_PROXIES`'i boş bırakın.
nginx'in önünde HTTPS'i sonlandıran bir yük dengeleyici varsa
`TRUSTED_PROXIES`'e onun adres(ler)ini yazın: istemci adresi (denetim kaydı,
istek sınırları) ve https bilgisi (güvenli çerezler, HSTS) ancak o zaman
`X-Forwarded-*` başlıklarından alınır.

### 3.4 Loglar

`LOG_CHANNEL=json`, her satıra bir JSON nesnesi olacak şekilde
`storage/logs/ada-YYYY-AA-GG.log` dosyalarına yazar ve `LOG_DAILY_DAYS` (14)
gün saklar; logrotate gerekmez. İstemler, yanıtlar ve API anahtarları asla
loglanmaz.

### 3.5 E-posta

Ada, tavan uyarılarını (kurumun aylık tavanının %80'i ve %100'ü) ve aylık
kullanım raporunu (her ayın sonunda, CSV ekleriyle; elle göndermek için
`php artisan ada:reports:monthly --month=2026-09 --to=siz@kurum.edu.tr`)
Yönetim → Kurum → Bildirim e-postaları alanındaki adreslere gönderir. `.env` içinde bir SMTP sunucusu tanımlayın:

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=smtp.kurum.edu.tr
MAIL_PORT=587
MAIL_USERNAME=…
MAIL_PASSWORD=…
MAIL_FROM_ADDRESS="ada@kurum.edu.tr"
MAIL_FROM_NAME="Ada Chat"
```

Google Workspace: `smtp-relay.gmail.com` (Google Admin'deki SMTP geçiş
hizmeti) veya uygulama şifresiyle `smtp.gmail.com`. Değişikliği uygulayın
(`php artisan optimize`), sonra aynı yönetim sayfasındaki **Deneme e-postası
gönder** düğmesini kullanın. İletileri kuyruk işçisi değil zamanlayıcı
gönderir. `MAIL_MAILER=log` iken iletiler yalnızca log kaydına yazılır;
adresler tanımlı ama e-posta yapılandırılmamışsa `ada:doctor` uyarır.

## 4. Güvenlik kontrol listesi

- [ ] `php artisan ada:doctor` geçiyor (debug kapalı, https, geliştirici
      girişi kapalı, güvenli çerezler, zamanlayıcı, giriş, sağlayıcılar).
- [ ] Google Admin: SAML uygulamasının kullanıcıları için 2 adımlı doğrulama
      zorunlu (Güvenlik → Kimlik doğrulama → 2 Adımlı Doğrulama). Ada'da
      parola yoktur; tek giriş yolu kimlik sağlayıcıdır.
- [ ] Microsoft Entra ID (kullanılıyorsa): tek kiracılı uygulama kaydı,
      Koşullu Erişim ile zorunlu çok faktörlü kimlik doğrulama ve takvimde
      istemci gizli anahtarının (client secret) son kullanma tarihi
      ([authentication.md §1a](authentication.md#1a-openid-connect-microsoft-entra-id-generic)).
- [ ] Güvenlik duvarı: yalnızca 80 ve 443 açık; MySQL ve Redis yalnızca
      localhost'u veya özel ağı dinliyor.
- [ ] `TRUSTED_PROXIES` yalnızca kendi proxy'lerinizi içeriyor (veya boş).
- [ ] `.env` yalnızca dağıtım kullanıcısı ve www-data tarafından okunabiliyor
      (`chmod 640`).
- [ ] `APP_KEY` ve veritabanı parolası çevrimdışı saklanıyor (§7).
- [ ] Yedekler alınıyor ve bir geri yükleme denendi (§5).
- [ ] Bir erişilebilirlik izleyicisi `https://<adres>/up` adresini
      kontrol ediyor.

## 5. Yedekleme

Neyin yedeği alınmalı:

| Ne | Neden | Ne sıklıkla |
|---|---|---|
| Veritabanı | Her şey: kullanıcılar, sohbetler, bütçeler, kullanım, ayarlar, şifreli API anahtarları | Her gece |
| `storage/app` | Yüklenen logolar ve favicon; sohbet ekleri (`private/attachments`, sohbetleriyle birlikte silinir) | Her gece |
| `.env`, özellikle `APP_KEY` | Anahtar olmadan kayıtlı sağlayıcı API anahtarları çözülemez | Bir kez ve her değişiklikten sonra — **çevrimdışı, veritabanı yedeklerinden ayrı** |

[`deploy/backup.sh`](../deploy/backup.sh) tutarlı bir döküm
(`--single-transaction`, kilitsiz) ve storage arşivini
`/var/backups/ada/<zaman>/` altına yazar, dökümün eksiksiz olduğunu kontrol
eder ve `KEEP_DAYS` (14) günden eski yedekleri siler:

```bash
# /etc/cron.d/ada-backup, Docker olmadan
30 2 * * * root ADA_DIR=/var/www/ada /var/www/ada/deploy/backup.sh >> /var/log/ada-backup.log 2>&1
# Docker ile
30 2 * * * root cd /opt/ada && ADA_DOCKER=1 deploy/backup.sh >> /var/log/ada-backup.log 2>&1
```

Yedek klasörünü başka bir makineye veya depolamaya kopyalayın (rsync,
restic, borg). Yedekler sohbet içeriği barındırır: veritabanı gibi koruyun.

## 6. Geri yükleme

1. Ada'yı §2 veya §3'teki gibi, önceki **aynı `APP_KEY`** ile (çevrimdışı
   kopyadan) kurun ve durdurun: `php artisan down` veya
   `docker compose -f compose.production.yml stop app scheduler`.
2. Veritabanını yükleyin:
   ```bash
   gunzip -c database.sql.gz | mysql -u ada -p ada
   # Docker:
   gunzip -c database.sql.gz | docker compose -f compose.production.yml exec -T mysql \
       sh -c 'exec mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"'
   ```
3. Dosyaları geri koyun: `tar -C /var/www/ada/storage -xzf storage-app.tar.gz`
   (Docker: `gunzip -c storage-app.tar.gz | docker compose -f compose.production.yml exec -T app tar -C /app/storage -xf -`).
4. Yedek eski bir sürümdense migration'ları uygulayın, sonra başlatın:
   `php artisan migrate --force && php artisan optimize && php artisan up`
   (Docker: `docker compose -f compose.production.yml up -d`).
5. `php artisan ada:doctor` çalıştırın, giriş yapın, bir sohbet açın,
   Yönetim → Sağlayıcılar'da anahtarların göründüğünü (••••1234) kontrol edin.

Canlıya geçmeden önce ve büyük güncellemelerden sonra bir test makinesinde
geri yüklemeyi bir kez deneyin.

## 7. Uygulama anahtarı (APP_KEY)

`APP_KEY`, veritabanındaki sağlayıcı API anahtarlarını, oturumları ve
çerezleri şifreler. Kurulumda bir kez üretin ve gelişigüzel değiştirmeyin:

- Çevrimdışı saklayın (parola yöneticisi, mühürlü zarf), veritabanı
  yedeklerinden ayrı. Anahtarı olmayan bir veritabanı yedeği API anahtarlarını
  kaybeder (yeniden girilebilirler); anahtarla birlikte çalınan bir yedek ise
  onları açığa çıkarır.
- Git'e, destek kayıtlarına veya sohbetlere asla yazmayın.

**Anahtarı değiştirme** (ör. anahtarı bilen biri ayrıldığında veya sızmış
olabileceğinde):

1. Yeni anahtar üretin: `php artisan key:generate --show`.
2. `.env` içinde `APP_PREVIOUS_KEYS=<eski anahtar>` ve `APP_KEY=<yeni anahtar>`
   yapın; uygulayın (`php artisan optimize` ve PHP-FPM'i yeniden yükleyin ya
   da `docker compose -f compose.production.yml up -d`).
3. Kayıtlı API anahtarlarını yeniden şifreleyin:
   `php artisan ada:credentials:reencrypt`
   (Docker: `docker compose -f compose.production.yml exec app php artisan ada:credentials:reencrypt`).
   Çözemediği anahtarları listeler; bunları Yönetim → Sağlayıcılar'da yeniden
   girin.
4. `APP_PREVIOUS_KEYS`'i kaldırın ve yeniden uygulayın. Herkes yeniden giriş
   yapar.
5. Anahtar sızdıysa sağlayıcılardaki API anahtarlarını da yenileyin.

## 8. Güncelleme

Önce [değişiklik günlüğünü](../CHANGELOG.md) okuyun: bir sürüm aşağıdaki
adımların ötesinde bir şey gerektiriyorsa orada yazar. Yedek alın (§5).

Docker ile:

```bash
cd /opt/ada && git fetch --tags && git checkout <yeni etiket>
docker compose -f compose.production.yml up -d --build   # migration'lar açılışta çalışır
docker compose -f compose.production.yml exec app php artisan ada:doctor
```

Docker olmadan:

```bash
cd /var/www/ada
php artisan down --retry=60          # akmakta olan yanıtlar tamamlanır
git fetch --tags && git checkout <yeni etiket>
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan optimize
sudo systemctl reload php8.4-fpm     # aksi halde opcache yeni dosyaları görmez
php artisan up
php artisan ada:doctor
```

Geri dönmek için: önceki etikete geçip adımları tekrarlayın; yeni sürümün
migration'ları veriyi değiştirdiyse (değişiklik günlüğü belirtir) yedeği geri
yükleyin.

## 9. İzleme

- `https://<adres>/up`, uygulama çalışırken 200 döner: bir erişilebilirlik
  izleyicisini buraya yönlendirin.
- `php artisan ada:doctor` bir sorun olduğunda sıfırdan farklı kodla çıkar;
  cron'dan veya izleme sisteminizden çalıştırıp hata durumunda uyarı alın.
  SAML sertifikasının süresi dolmadan 30 gün önce de uyarır; OpenID Connect
  sağlayıcısına erişilebildiğini ve saatlerin uyuştuğunu da denetler.
- Yönetim → Genel bakış harcamaları, aktif kullanıcıları ve olağandışı
  kullanımı gösterir; denetim kaydı her yönetimsel değişikliği gösterir.
- Loglar: JSON satırları (§3.4); kullanıcılar hata bildirdiğinde
  `AI provider request failed` ifadesini arayın.

## 10. Kurumda yaygınlaştırma

Kademeli geçiş, yapılandırma ve bütçe hatalarını az kişi etkilenirken
yakalar. Kontrol listesi (Beykoz Üniversitesi'ndeki ilk geçiş için):

**Pilottan önce**

- [ ] Kurulum tamam; `ada:doctor` geçiyor; yedekleme ve geri yükleme denendi.
- [ ] Google Workspace SAML uygulaması: başta yalnızca bir pilot organizasyon
      birimi veya grubu için açık (Google Admin → uygulama → Kullanıcı
      erişimi).
- [ ] Sağlayıcılar ve modeller eklendi (Yönetim → Modeller, "Kolay" mod
      fiyatları Ada'nın kataloğundan alır); takma adların adları ve
      açıklamaları anlaşılır ("Hızlı", "Gelişmiş").
- [ ] Gruplar ve politikalar: kendi bütçe politikası olan bir **Pilot**
      grubu; Varsayılan grupta temkinli bir limit (veya henüz takma ad yok).
- [ ] Kullanım bildirimi metni kişisel verilerin korunmasından sorumlu kişi
      tarafından gözden geçirildi (Yönetim → Gizlilik); saklama süreleri
      belirlendi.
- [ ] Kurumsal görünüm: ad, logolar, renkler, varsayılan dil.
- [ ] Destek iletişim bilgisi ve kısa bir kullanım kılavuzu pilot
      kullanıcılara duyuruldu.

**Pilot (2–4 hafta, farklı birimlerden 20–50 kişi)**

- [ ] Haftalık: Genel bakış'ta harcama ile beklenti, olağandışı kullanım,
      hata oranları, limitine ulaşan kullanıcılar.
- [ ] Geri bildirim toplayın: istenen modeller, çok sıkı veya gevşek
      limitler, sorunlar.
- [ ] Fiyatları, takma adları, limitleri ayarlayın; mutabakat raporlarını
      kontrol edin.

**Geniş kullanım**

- [ ] SAML uygulamasını herkese (veya sıradaki birimlere) açın; Varsayılan
      gruba takma adlar atayın; kurum genelindeki limitleri belirleyin.
- [ ] Duyurun: ne olduğu, hangi verinin nereye gittiği (kullanım
      bildirimi), bütçelerin nasıl işlediği, nereden yardım alınacağı.
- [ ] İlk ay: Genel bakış'ı önce günlük, sonra haftalık izleyin; PHP-FPM'i
      gerçek yoğunluğa göre boyutlandırın (§1).
