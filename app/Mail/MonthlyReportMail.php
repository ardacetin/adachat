<?php

namespace App\Mail;

use Carbon\CarbonImmutable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The monthly usage summary with CSV attachments.
 */
class MonthlyReportMail extends Mailable
{
    /**
     * @param  array<string, mixed>  $summary  MonthlyReport::data()['summary']
     * @param  array<string, string>  $files  file name => CSV
     */
    public function __construct(
        public readonly string $institution,
        public readonly string $month,
        public readonly array $summary,
        private readonly array $files,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('mail.monthly.subject', ['institution' => $this->institution, 'month' => $this->month]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.monthly-report', with: [
            'url' => route('admin.reports.index', [
                'from' => $this->month.'-01',
                'to' => CarbonImmutable::parse($this->month.'-01')->endOfMonth()->toDateString(),
            ]),
        ]);
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return array_values(array_map(
            fn (string $name) => Attachment::fromData(fn () => $this->files[$name], $name)->withMime('text/csv'),
            array_keys($this->files),
        ));
    }
}
