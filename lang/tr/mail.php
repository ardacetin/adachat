<?php

return [
    'footer' => 'Ada Chat — bu ileti otomatik olarak gönderildi.',

    'invitation' => [
        'subject' => ':app hesabınız oluşturuldu',
        'heading' => ':app\'e hoş geldiniz',
        'body' => ':name sizin için :institution bünyesinde bir hesap oluşturdu. Kurumun yapay zekâ asistanı :app\'i hemen kullanmaya başlayabilirsiniz.',
        'sign_in' => 'Kurum hesabınızla (:email) oturum açın. Ayrı bir şifre gerekmez.',
        'button' => 'Oturum aç',
    ],

    'user_budget' => [
        'subject_80' => ':institution: aylık AI bütçenizin %80\'ini kullandınız',
        'subject_100' => ':institution: aylık AI bütçeniz doldu',
        'heading_80' => 'Aylık bütçenizin %80\'i kullanıldı',
        'heading_100' => 'Aylık bütçeniz doldu',
        'body_80' => 'Ada Chat için aylık bütçenizin %80\'ini kullandınız. Bütçe dolduğunda, :date tarihinde yenilenene kadar yeni mesaj gönderilemez.',
        'body_100' => 'Ada Chat için aylık bütçenizin tamamını kullandınız. Bütçe :date tarihinde yenilenene kadar yeni mesaj gönderilemez. Daha fazlasına ihtiyacınız varsa yöneticinize başvurun.',
        'amounts' => 'Şimdiye kadar kullanılan: :spent / :limit.',
        'button' => 'Kullanımınızı görün',
        'settings' => 'Bu e-postaları Ayarlar → Bildirimler sayfasından kapatabilirsiniz.',
    ],
    'cap_alert' => [
        'subject_80' => ':institution: aylık yapay zekâ bütçesinin %80\'i kullanıldı',
        'subject_100' => ':institution: aylık yapay zekâ bütçesi tükendi',
        'heading_80' => 'Aylık bütçenin %80\'i kullanıldı',
        'heading_100' => 'Aylık bütçe tükendi',
        'body_80' => ':institution, yapay zekâ kullanımı için belirlenen :cap aylık tavanın :used kadarını kullandı. :cap tutarına ulaşıldığında, ay :date tarihinde yenilenene kadar kimse yeni istek başlatamaz.',
        'body_100' => ':institution, yapay zekâ kullanımı için belirlenen :cap aylık tavana ulaştı (:used). Ay :date tarihinde yenilenene kadar yeni istekler reddedilir.',
        'what_now' => 'Tavanı Yönetim → Kurum sayfasından yükseltebilir, kimin ne kullandığını genel bakış ve raporlarda görebilirsiniz.',
        'button' => 'Genel bakışı aç',
    ],

    'test' => [
        'subject' => ':institution: Ada Chat deneme iletisi',
        'heading' => 'E-posta çalışıyor',
        'body' => 'Ada Chat\'ten (:institution) gönderilen bu deneme iletisi ulaştı; e-posta ayarları doğru. Tavan uyarıları ve aylık raporlar bu adrese gönderilecek.',
    ],

    'monthly' => [
        'subject' => ':institution: :month yapay zekâ kullanım raporu',
        'heading' => ':month kullanım raporu',
        'intro' => ':institution, :month ayında Ada Chat\'i şöyle kullandı. Rakamlar yalnızca maliyet ve sayılardır; mesaj içerikleri asla yer almaz.',
        'spend' => 'Harcama',
        'requests' => 'İstek',
        'users' => 'Aktif kullanıcı',
        'adjustments' => 'Elle düzeltmeler',
        'users_at_limit' => 'Bütçesini bitiren kullanıcı',
        'cap' => 'Kurum tavanı kullanımı',
        'overshoots' => ':count istek rezervasyonundan fazlaya mal oldu; ayrıntılar raporlarda.',
        'top_groups' => 'En çok harcayan gruplar',
        'top_models' => 'En çok kullanılan modeller',
        'name' => 'Ad',
        'attachments' => 'Ekte: kullanıcıya ve güne göre harcama, CSV olarak (Excel ve diğer tablolama programlarında açılır).',
        'button' => 'Raporları aç',
    ],
];
