<?php

namespace App\Jobs;

use App\Models\Company;
use App\Services\WhatsApp\Conversation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Traite un message WhatsApp reçu, hors de la requête du webhook (Meta attend une
 * réponse rapide ; l'analyse par IA peut prendre quelques secondes).
 */
class ProcessInboundWhatsApp implements ShouldQueue
{
    use Queueable;

    // Un message ne doit jamais être traité deux fois (réponses en double)
    public int $tries = 1;

    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public int $companyId,
        public string $from,
        public string $type,
        public ?string $text,
        public ?string $buttonId,
        public array $meta = [],
    ) {
        $this->onQueue('messages');
    }

    public function handle(Conversation $conversation): void
    {
        $company = Company::find($this->companyId);

        if ($company?->isActive()) {
            $conversation->handle($company, $this->from, $this->type, $this->text, $this->buttonId, $this->meta);
        }
    }
}
