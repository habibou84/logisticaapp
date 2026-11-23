<?php

namespace App\Services\Messaging;

use App\Enums\NotificationEvent;
use App\Enums\WhatsAppTemplate;
use App\Models\Company;
use App\Models\MetaTemplate;
use App\Models\WhatsAppAccount;
use App\Services\Push\WebPush;
use App\Support\Money;

/**
 * WhatsApp › Messages automatiques : chaque message qui part tout seul, une ligne, rangé par destinataire.
 * L'interrupteur d'une ligne lit et écrit le réglage qui décide réellement de l'envoi :
 * - « event » : étape des clients (companies.merchant_whatsapp_events) ;
 * - « column » : réglage existant de l'entreprise (prévenir le destinataire, colis à reporter le soir, argent à
 *   remettre, clôture, point de la semaine) ;
 * - « setting » : interrupteur par modèle (companies.message_settings, voir Company::messageOn).
 * Une ligne « fixed » n'a pas d'interrupteur : elle dit pourquoi (sécurité, choix du client, réglé ailleurs).
 */
class AutomaticMessages
{
    public const AUDIENCES = ['recipient' => 'Destinataires', 'merchant' => 'Clients', 'courier' => 'Livreurs', 'team' => 'Équipe'];

    /**
     * @return list<array<string, mixed>>
     */
    public function catalog(): array
    {
        $t = fn (WhatsAppTemplate ...$templates) => $templates;

        return [
            // Destinataires
            ['id' => 'out_for_delivery', 'audience' => 'recipient', 'title' => 'Colis en route, colis expédié', 'when' => 'Quand le livreur part livrer (avec le code de livraison), ou quand le colis est déposé à la gare',
                'templates' => $t(WhatsAppTemplate::OutForDelivery, WhatsAppTemplate::ParcelOnTheWay, WhatsAppTemplate::ShippedRecipient), 'column' => 'notify_recipients'],

            // Clients : étapes des courses
            ['id' => 'order_received', 'audience' => 'merchant', 'title' => 'Course reçue et validée', 'when' => 'À l\'enregistrement, quand la validation est automatique',
                'templates' => $t(WhatsAppTemplate::OrderConfirmed), 'event' => NotificationEvent::OrderReceived],
            ['id' => 'order_confirmed', 'audience' => 'merchant', 'title' => 'Course validée par l\'agence', 'when' => 'Quand l\'équipe valide la course à la main',
                'templates' => $t(WhatsAppTemplate::OrderConfirmed, WhatsAppTemplate::OrderConfirmedForDate), 'event' => NotificationEvent::OrderConfirmed],
            ['id' => 'picked_up', 'audience' => 'merchant', 'title' => 'Colis récupéré', 'when' => 'Quand le livreur a récupéré le colis',
                'templates' => $t(WhatsAppTemplate::OrderPickedUp), 'event' => NotificationEvent::OrderPickedUp],
            ['id' => 'delivered', 'audience' => 'merchant', 'title' => 'Colis livré, remis ou expédié', 'when' => 'Quand le colis est livré, remis à son destinataire, ou déposé à la gare',
                'templates' => $t(WhatsAppTemplate::OrderDelivered, WhatsAppTemplate::OrderHandedOver, WhatsAppTemplate::Shipped), 'event' => NotificationEvent::OrderDelivered],
            ['id' => 'incident', 'audience' => 'merchant', 'title' => 'Incident (échec, report, retard)', 'when' => 'Échec de livraison, ramassage impossible, report décidé par l\'agence, course retardée',
                'templates' => $t(WhatsAppTemplate::DeliveryIncident, WhatsAppTemplate::CourseDelayed), 'event' => NotificationEvent::OrderIncident],
            ['id' => 'return_slip', 'audience' => 'merchant', 'title' => 'Retour de colis', 'when' => 'Quand un livreur lui rapporte ses colis non livrés, puis quand ils lui sont rendus',
                'templates' => $t(WhatsAppTemplate::ReturnSlip, WhatsAppTemplate::ReturnHanded), 'event' => NotificationEvent::ReturnSlip],
            ['id' => 'payout', 'audience' => 'merchant', 'title' => 'Reversement effectué', 'when' => 'Quand son reversement est payé (e-commerce)',
                'templates' => $t(WhatsAppTemplate::PayoutPaid), 'event' => NotificationEvent::PayoutPaid],
            ['id' => 'invoice_issued', 'audience' => 'merchant', 'title' => 'Facture du mois', 'when' => 'Quand sa facture est émise (distribution, mise à disposition)',
                'templates' => $t(WhatsAppTemplate::InvoiceIssued), 'event' => NotificationEvent::InvoiceIssued],
            ['id' => 'invoice_reminder', 'audience' => 'merchant', 'title' => 'Rappel de facture', 'when' => 'Trois jours avant l\'échéance, puis chaque semaine de retard',
                'templates' => $t(WhatsAppTemplate::InvoiceReminder, WhatsAppTemplate::InvoiceOverdue), 'event' => NotificationEvent::InvoiceReminder],
            ['id' => 'forfait_usage', 'audience' => 'merchant', 'title' => 'Forfait bientôt épuisé', 'when' => 'À 80 %, puis à 100 % du forfait du mois',
                'templates' => $t(WhatsAppTemplate::ForfaitUsage), 'event' => NotificationEvent::ForfaitUsage],
            ['id' => 'reschedule_evening', 'audience' => 'merchant', 'title' => 'Colis à reporter', 'when' => 'Le soir, pour les colis pas ramassés : choisir une nouvelle date',
                'templates' => $t(WhatsAppTemplate::RescheduleRequest), 'column' => 'reschedule_evening', 'channel' => 'WhatsApp',
                'link' => fn (Company $c) => ['label' => 'Heure : '.substr((string) ($c->reschedule_evening_time ?? '19:00'), 0, 5), 'to' => '/admin/reglages/courses']],
            ['id' => 'reschedule_reminder', 'audience' => 'merchant', 'title' => 'Rappel des colis à reporter', 'when' => 'Le lendemain matin, sans réponse du client',
                'templates' => $t(WhatsAppTemplate::RescheduleReminder), 'setting' => true, 'channel' => 'WhatsApp',
                'note' => fn (Company $c) => $c->reschedule_no_answer === 'auto_next_day' ? 'Sans effet : sans réponse, les colis sont reportés tout seuls (Réglages › Courses).' : null],
            ['id' => 'reschedule_done', 'audience' => 'merchant', 'title' => 'Nouvelle date de livraison', 'when' => 'Quand l\'agence fixe la nouvelle date, ou les reporte toute seule',
                'templates' => $t(WhatsAppTemplate::RescheduleDone), 'setting' => true, 'channel' => 'WhatsApp'],
            ['id' => 'rate_course', 'audience' => 'merchant', 'title' => 'Noter la course', 'when' => 'Après la livraison : noter le livreur',
                'templates' => $t(WhatsAppTemplate::RateCourse), 'fixed' => 'Notation', 'link' => fn () => ['label' => 'Réglages › Notation', 'to' => '/admin/reglages/notation']],
            ['id' => 'activity_report', 'audience' => 'merchant', 'title' => 'Point d\'activité', 'when' => 'Chaque jour ou chaque semaine : choisi par le client dans son application',
                'templates' => $t(WhatsAppTemplate::ActivityReport), 'fixed' => 'Son choix'],
            ['id' => 'payment_method', 'audience' => 'merchant', 'title' => 'Moyen de paiement modifié', 'when' => 'Ajouté, modifié ou retiré sur son compte : sécurité, ne se coupe pas',
                'templates' => $t(WhatsAppTemplate::PaymentMethodChanged), 'fixed' => 'Toujours'],

            // Livreurs
            ['id' => 'cash_receipt', 'audience' => 'courier', 'title' => 'Versement reçu', 'when' => 'Le reçu de son versement, envoyé depuis la caisse',
                'templates' => $t(WhatsAppTemplate::CashReceipt), 'fixed' => 'À la demande'],
            ['id' => 'cash_reminder', 'audience' => 'courier', 'title' => 'Argent à remettre', 'when' => 'Quand il garde l\'argent encaissé trop longtemps',
                'templates' => $t(WhatsAppTemplate::CashReminder), 'column' => 'cash_alert_courier',
                'link' => fn (Company $c) => ['label' => $this->cashThreshold($c), 'to' => '/admin/reglages/alertes']],

            // Équipe
            ['id' => 'cash_closing', 'audience' => 'team', 'title' => 'Clôture de caisse', 'when' => 'Chaque soir, à la clôture : entrées, sorties, espèces comptées',
                'templates' => $t(WhatsAppTemplate::CashClosing), 'column' => 'cash_close_summary'],
            ['id' => 'weekly_report', 'audience' => 'team', 'title' => 'Point de la semaine', 'when' => 'Le lundi matin : courses, taux de livraison, clients à relancer',
                'templates' => $t(WhatsAppTemplate::ManagerReport), 'column' => 'weekly_report'],
            ['id' => 'stats_alert', 'audience' => 'team', 'title' => 'Alerte des statistiques', 'when' => 'Quand un chiffre sort de ses limites',
                'templates' => $t(WhatsAppTemplate::StatsAlert), 'fixed' => 'Statistiques', 'link' => fn () => ['label' => 'Statistiques', 'to' => '/admin/statistiques']],
        ];
    }

    /**
     * Les lignes, avec leur état pour cette entreprise.
     *
     * @return list<array<string, mixed>>
     */
    public function describe(Company $company): array
    {
        $account = WhatsAppAccount::forCompanyId($company->id);
        $statuses = $account
            ? MetaTemplate::where('whatsapp_account_id', $account->id)->where('language', WhatsAppTemplate::LANGUAGE)->pluck('status', 'name')
            : collect();
        $pushFirst = $company->merchant_push_first && WebPush::enabled();

        return array_map(function (array $m) use ($company, $statuses, $pushFirst) {
            /** @var WhatsAppTemplate $main */
            $main = $m['templates'][0];
            $channel = $m['channel'] ?? match ($m['audience']) {
                'merchant' => $pushFirst ? 'Notification, sinon WhatsApp' : 'WhatsApp',
                'team' => 'WhatsApp aux administrateurs',
                default => 'WhatsApp',
            };

            return [
                'id' => $m['id'],
                'audience' => $m['audience'],
                'title' => $m['title'],
                'when' => $m['when'],
                'channel' => $channel,
                'fixed' => $m['fixed'] ?? null,
                'on' => isset($m['fixed']) ? null : $this->isOn($company, $m),
                'link' => isset($m['link']) ? $m['link']($company) : null,
                'note' => isset($m['note']) ? $m['note']($company) : null,
                // Ce que la personne reçoit, avec des exemples à la place des {{1}}, {{2}}…
                'text' => $main->render($main->example()),
                'templates' => array_map(fn (WhatsAppTemplate $t) => $t->value, $m['templates']),
                'meta_status' => $statuses[$main->value] ?? 'unknown',
            ];
        }, $this->catalog());
    }

    public function set(Company $company, string $id, bool $on): void
    {
        $m = collect($this->catalog())->firstWhere('id', $id);
        abort_if($m === null || isset($m['fixed']), 404, 'Message inconnu.');

        if (isset($m['event'])) {
            $company->update(['merchant_whatsapp_events' => [...($company->merchant_whatsapp_events ?? []), $m['event']->value => $on]]);
        } elseif (isset($m['column'])) {
            $company->update([$m['column'] => $on]);
        } else {
            $settings = $company->message_settings ?? [];
            foreach ($m['templates'] as $template) {
                $settings[$template->value] = $on;
            }
            $company->update(['message_settings' => $settings]);
        }
    }

    /**
     * @param  array<string, mixed>  $m
     */
    private function isOn(Company $company, array $m): bool
    {
        return match (true) {
            isset($m['event']) => $company->merchantWantsByDefault($m['event']),
            isset($m['column']) => (bool) $company->{$m['column']},
            default => $company->messageOn($m['templates'][0]),
        };
    }

    private function cashThreshold(Company $company): string
    {
        $parts = array_filter([
            $company->cash_alert_hours ? "{$company->cash_alert_hours} h" : null,
            $company->cash_alert_amount ? Money::format($company->cash_alert_amount) : null,
        ]);

        return $parts ? 'Seuil : '.implode(' ou ', $parts) : 'Aucun seuil : à régler';
    }
}
