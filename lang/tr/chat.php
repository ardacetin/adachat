<?php

return [
    'assistant_not_allowed' => 'Bu asistan sizin için kullanılabilir değil.',
    'assistant_model_locked' => 'Asistanla yapılan sohbetler her zaman asistanın modelini kullanır.',
    'web_search_unavailable' => 'Bu modelle web araması kullanılamıyor.',
    'alias_not_allowed' => 'Bu model sizin kullanımınıza açık değil.',

    'attachments' => [
        'unsupported_type' => 'Bu dosya türü desteklenmiyor. Görsel (PNG, JPEG, WebP, GIF), PDF, Word, Excel ya da PowerPoint (.docx, .xlsx, .pptx) veya metin ve kod dosyası ekleyebilirsiniz.',
        'unreadable_document' => 'Belge okunamadı. Bozuk ya da parola korumalı olabilir.',
        'scanned_pdf_unsupported' => 'Bu PDF metin içermiyor (taranmış olabilir) ve bu model PDF\'leri doğrudan okuyamıyor. Dosya okuyabilen bir model seçin.',
        'too_large' => 'Dosya çok büyük (en çok :max MB).',
        'image_too_large' => 'Görsel çok büyük (her kenarda en çok :max piksel).',
        'unreadable_image' => 'Görsel okunamadı.',
        'too_many_pending' => 'Gönderilmemiş çok fazla dosya var. Önce bazılarını gönderin ya da kaldırın.',
        'too_many' => 'Bir mesaja en çok :max dosya eklenebilir.',
        'invalid' => 'Eklenen dosyalardan biri artık kullanılamıyor. Kaldırıp yeniden ekleyin.',
        'no_text' => 'Bu dosyadan metin okunamadı. Taranmış PDF\'ler asistan belgesi olarak desteklenmiyor.',
        'document_limit' => 'Asistanın belgeleri her mesaja en çok :max token ekleyebilir. Bir belgeyi kaldırın veya kısaltın.',
        'context_limit' => 'Bu belgeyle asistanın talimatları modelin bağlam penceresinin yarısından fazlasını kullanırdı.',
        'vision_unsupported' => 'Bu model görselleri okuyamaz. Başka bir model seçin ya da görselleri kaldırın.',
    ],
    'export' => [
        'untitled' => 'Adsız sohbet',
        'exported' => 'Ada Chat\'ten :date tarihinde dışa aktarıldı',
        'you' => 'Siz',
        'assistant' => 'Asistan (:model)',
        'attachments' => 'Ekler: :names',
        'sources' => 'Kaynaklar',
    ],
];
