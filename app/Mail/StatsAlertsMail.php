<?php

namespace App\Mail;

use App\Models\Company;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Alertes des statistiques par e-mail : toutes les alertes du matin dans un seul message, chacune avec son lien.
 */
class StatsAlertsMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @param list<array{event: string, key: string, title: string, body: string, href: string}> $alerts */
    public function __construct(public Company $company, public array $alerts) {}

    public function envelope(): Envelope
    {
        $count = count($this->alerts);

        return new Envelope(subject: ($count === 1 ? $this->alerts[0]['title'] : "{$count} alertes à regarder")." · {$this->company->name}");
    }

    public function content(): Content
    {
        return new Content(view: 'mail.stats-alerts', with: [
            'company' => $this->company,
            'alerts' => array_map(fn (array $a) => [...$a, 'url' => $this->company->url($a['href'])], $this->alerts),
        ]);
    }
}
