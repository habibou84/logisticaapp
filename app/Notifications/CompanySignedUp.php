<?php

namespace App\Notifications;

use App\Models\Company;
use App\Support\PhoneNumber;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Nouvelle entreprise inscrite en ligne : cloche et temps réel de la console.
 */
class CompanySignedUp extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Company $company) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $trial = $this->company->trial_ends_at ? ', essai jusqu\'au '.$this->company->trial_ends_at->format('d/m/Y') : '';

        return [
            'kind' => 'company_signup',
            'title' => "Nouvelle entreprise : {$this->company->name}",
            'body' => trim(PhoneNumber::display((string) $this->company->phone).' · formule '.($this->company->plan?->name ?? '—').$trial.'.'),
            'company_id' => $this->company->id,
            'href' => '/console/entreprises/'.$this->company->id,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
