<?php

return [

    'saved' => 'Ayarlar kaydedildi.',
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

];
