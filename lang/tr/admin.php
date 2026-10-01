<?php

return [

    'saved' => 'Ayarlar kaydedildi.',
    'test_mail_sent' => 'Deneme e-postası gönderildi: :to.',
    'test_mail_failed' => 'Deneme e-postası gönderilemedi. MAIL_* ayarlarını kontrol edin; nedeni log kaydında.',
    'policy_in_use' => 'Bu bütçe politikası bir grup tarafından kullanılıyor, silinemez.',
    'group_not_deletable' => 'Varsayılan grup ve üyesi olan gruplar silinemez.',
    'last_super_admin' => 'Son etkin süper yönetici düşürülemez veya devre dışı bırakılamaz.',
    'credit_exceeds_spent' => 'İade, bu ay harcanan tutardan büyük olamaz.',
    'report_range_order' => 'Bitiş tarihi başlangıçtan önce olamaz.',
    'report_range_too_long' => 'Bir rapor en fazla :days günü kapsayabilir.',
    'primary_color_contrast' => 'Bu renk çok açık: düğmelerin okunabilir kalması için beyaza karşı en az 3:1 kontrast gerekir.',
    'alias_max_tokens' => 'Çıktı sınırı modelin üst sınırını (:max token) aşamaz.',

    'provider_check' => [
        'ok' => 'Bağlantı başarılı: API anahtarı çalışıyor.',
        'failed' => 'Bağlantı başarısız: :reason',
    ],

    // App\Domain\AI\Exceptions\ProviderException::code()
    'provider_errors' => [
        'provider_unavailable' => 'anahtar reddedildi veya sağlayıcıya ulaşılamıyor.',
        'rate_limited' => 'sağlayıcı istekleri sınırlandırıyor.',
        'overloaded' => 'sağlayıcı aşırı yüklü.',
        'context_too_long' => 'istek bu model için çok uzun.',
        'content_filtered' => 'sağlayıcı içeriği filtreledi.',
        'timeout' => 'sağlayıcı zamanında yanıt vermedi.',
        'invalid_request' => 'sağlayıcı isteği reddetti.',
        'token_count_unavailable' => 'token sayımı kullanılamıyor.',
    ],

    'report_csv' => [
        'user' => 'Kullanıcı',
        'email' => 'E-posta',
        'group' => 'Grup',
        'provider' => 'Sağlayıcı',
        'detail_provider' => 'Sürücü',
        'model' => 'Model',
        'detail_model' => 'Sağlayıcı',
        'day' => 'Gün',
        'month' => 'Ay',
        'requests' => 'İstek',
        'input_tokens' => 'Girdi token',
        'output_tokens' => 'Çıktı token',
        'cost' => 'Maliyet (USD)',
    ],
];
