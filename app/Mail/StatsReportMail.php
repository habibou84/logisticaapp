<?php

namespace App\Mail;

use App\Models\Company;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Point de la semaine ou du mois par e-mail : chiffres clés et fichier Excel complet en pièce jointe.
 */
class StatsReportMail extends Mailable
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $summary
     */
    public function __construct(public Company $company, public string $title, public array $summary, public string $xlsxPath, public string $filename) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->title} · {$this->company->name}");
    }

    public function content(): Content
    {
        return new Content(view: 'mail.stats-report', with: [
            'company' => $this->company,
            'title' => $this->title,
            's' => $this->summary,
            'url' => $this->company->url('/admin/statistiques'),
        ]);
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [Attachment::fromPath($this->xlsxPath)->as($this->filename)->withMime('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')];
    }
}
