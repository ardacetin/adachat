<?php

return [
    'alias_not_allowed' => 'This model is not available to you.',

    'attachments' => [
        'unsupported_type' => 'This file type is not supported. Attach images (PNG, JPEG, WebP, GIF), PDF, Word, Excel or PowerPoint files (.docx, .xlsx, .pptx), or text and code files.',
        'unreadable_document' => 'The document could not be read. It may be damaged or password-protected.',
        'scanned_pdf_unsupported' => 'This PDF contains no text (it may be scanned), and this model cannot read PDFs directly. Choose a model that reads files.',
        'too_large' => 'The file is too large (at most :max MB).',
        'image_too_large' => 'The image is too large (at most :max pixels on each side).',
        'unreadable_image' => 'The image could not be read.',
        'too_many_pending' => 'Too many unsent files. Send or remove some first.',
        'too_many' => 'At most :max files can be attached to a message.',
        'invalid' => 'An attached file is no longer available. Remove it and attach it again.',
        'vision_unsupported' => 'This model cannot read images. Choose another model or remove the images.',
    ],
];
