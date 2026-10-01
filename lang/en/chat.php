<?php

return [
    'alias_not_allowed' => 'This model is not available to you.',

    'attachments' => [
        'unsupported_type' => 'This file type is not supported. Attach images (PNG, JPEG, WebP, GIF) or text and code files.',
        'not_supported_yet' => 'PDF and Office files are not supported yet. Copy the text into the message or attach it as a text file.',
        'too_large' => 'The file is too large (at most :max MB).',
        'image_too_large' => 'The image is too large (at most :max pixels on each side).',
        'unreadable_image' => 'The image could not be read.',
        'too_many_pending' => 'Too many unsent files. Send or remove some first.',
        'too_many' => 'At most :max files can be attached to a message.',
        'invalid' => 'An attached file is no longer available. Remove it and attach it again.',
        'vision_unsupported' => 'This model cannot read images. Choose another model or remove the images.',
    ],
];
