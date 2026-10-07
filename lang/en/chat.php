<?php

return [
    'assistant_not_allowed' => 'This assistant is not available to you.',
    'assistant_model_locked' => 'Conversations with an assistant always use its model.',
    'web_search_unavailable' => 'Web search is not available with this model.',
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
        'no_text' => 'No text could be read from this file. Scanned PDFs are not supported as assistant documents.',
        'document_limit' => 'The assistant\'s documents may add at most :max tokens to every message. Remove a document or shorten it.',
        'context_limit' => 'With this document, the assistant\'s instructions would use more than half of the model\'s context window.',
        'vision_unsupported' => 'This model cannot read images. Choose another model or remove the images.',
    ],
    'share' => [
        'too_many_links' => 'A conversation can have at most :max active links. Revoke one first.',
        'daily_limit' => 'You have created or copied too many shared conversations today. Try again tomorrow.',
        'withheld' => '(This answer used Google Search. Google allows showing it only to the person who asked.)',
    ],
    'export' => [
        'untitled' => 'Untitled conversation',
        'exported' => 'Exported from Ada Chat on :date',
        'you' => 'You',
        'assistant' => 'Assistant (:model)',
        'attachments' => 'Attachments: :names',
        'sources' => 'Sources',
    ],
];
