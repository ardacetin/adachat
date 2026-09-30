<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Language Lines
    |--------------------------------------------------------------------------
    */

    'failed' => 'Bu bilgiler kayıtlarımızla eşleşmiyor.',
    'throttle' => 'Çok fazla giriş denemesi yapıldı. Lütfen :seconds saniye sonra tekrar deneyin.',

    // Giriş reddetme nedenleri (App\Domain\Identity\Exceptions\RejectionReason).
    'errors' => [
        'invalid_state' => 'Giriş oturumunuzun süresi doldu. Lütfen tekrar deneyin.',
        'provider_error' => 'Kimlik sağlayıcınızla giriş başarısız oldu. Lütfen tekrar deneyin.',
        'email_not_verified' => 'E-posta adresiniz kimlik sağlayıcınız tarafından doğrulanmamış.',
        'domain_not_allowed' => 'Bu hesapla giriş yapılamaz. Lütfen kurumsal hesabınızı kullanın.',
        'not_provisioned' => 'Henüz erişiminiz yok. Lütfen yöneticinizle iletişime geçin.',
        'account_disabled' => 'Hesabınız devre dışı bırakılmış. Lütfen yöneticinizle iletişime geçin.',
        'session_expired' => 'Oturumunuz sona erdi. Lütfen yeniden giriş yapın.',
        'account_conflict' => 'Hesabınız ilişkilendirilemedi. Lütfen yöneticinizle iletişime geçin.',
    ],

];
