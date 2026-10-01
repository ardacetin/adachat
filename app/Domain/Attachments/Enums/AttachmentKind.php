<?php

namespace App\Domain\Attachments\Enums;

enum AttachmentKind: string
{
    /** Sent to the model as an image part (models with vision only). */
    case Image = 'image';

    /** Text or code, sent inline in the message text (every model). */
    case Text = 'text';

    /** Sent natively to models that read PDFs, otherwise as its extracted text. */
    case Pdf = 'pdf';

    /** Word, Excel or PowerPoint (OOXML), always sent as extracted text. */
    case Document = 'document';
}
