<?php

namespace App\Enums;

/**
 * Modèles WhatsApp à faire approuver par Meta (catégorie « utility », langue fr).
 * Le texte ci-dessous est celui à soumettre tel quel dans le gestionnaire WhatsApp ;
 * il sert aussi de texte pour le SMS de repli.
 */
enum WhatsAppTemplate: string
{
    case OrderConfirmed = 'course_confirmee';
    case OrderPickedUp = 'colis_recupere';
    case OutForDelivery = 'colis_en_route';
    case OrderDelivered = 'colis_livre';
    case DeliveryIncident = 'incident_livraison';
    case PayoutPaid = 'reversement_effectue';
    case ActivityReport = 'rapport_activite';
    case Shipped = 'colis_expedie';
    case ShippedRecipient = 'colis_expedie_client';
    case ReturnSlip = 'bon_de_retour';
    case ReturnHanded = 'retour_remis';
    case ManagerReport = 'point_semaine_gerant';
    case OrderConfirmedForDate = 'course_validee_date';

    public const LANGUAGE = 'fr';

    public function body(): string
    {
        return match ($this) {
            self::OrderConfirmed => 'Bonjour {{1}}, votre course {{2}} pour {{3}} est validée. Frais de livraison : {{4}}. Notre livreur passe bientôt récupérer le colis.',
            self::OrderConfirmedForDate => 'Bonjour {{1}}, votre course {{2}} pour {{3}} est validée pour une livraison le {{4}} ({{5}}). Frais de livraison : {{6}}. Notre livreur passe bientôt récupérer le colis.',
            self::OrderPickedUp => 'Bonjour {{1}}, le colis {{2}} pour {{3}} a été récupéré par notre livreur. Il sera livré prochainement.',
            self::OutForDelivery => 'Bonjour {{1}}, votre colis de {{2}} est en route. Montant à payer au livreur : {{3}}. Code de livraison à lui donner : {{4}}. Suivi du colis : {{5}} . Merci de votre confiance.',
            self::OrderDelivered => 'Bonjour {{1}}, le colis {{2}} pour {{3}} a été livré. Montant encaissé : {{4}}. Merci de votre confiance.',
            self::DeliveryIncident => 'Bonjour {{1}}, incident sur le colis {{2}} pour {{3}} : {{4}}. Choisissez la suite depuis votre espace : {{5}} . Merci.',
            self::PayoutPaid => 'Bonjour {{1}}, votre reversement {{2}} de {{3}} a été effectué par {{4}}. Le détail est dans votre espace marchand. Merci.',
            self::ActivityReport => 'Bonjour {{1}}, voici votre point {{2}} : {{3}} courses, {{4}} livrées ou expédiées, {{5}} non livrées ou reportées, {{6}} retournées. Encaissé : {{7}}. Frais de livraison : {{8}}. Frais d\'expédition et autres frais : {{9}}. Net : {{10}}. Merci de votre confiance.',
            self::Shipped => 'Bonjour {{1}}, le colis {{2}} pour {{3}} a été expédié par {{4}} (ticket {{5}}). Frais d\'expédition : {{6}}, déduits de votre point. Merci.',
            self::ShippedRecipient => 'Bonjour {{1}}, votre colis de {{2}} a été expédié par {{3}} (ticket {{4}}). Récupérez-le à l\'arrivée auprès du transporteur. Merci de votre confiance.',
            self::ReturnSlip => 'Bonjour {{1}}, notre livreur {{2}} vous rapporte {{3}} colis non livré(s) (bon de retour {{4}}). Détail du bon : {{5}} . Merci.',
            self::ReturnHanded => 'Bonjour {{1}}, {{2}} colis vous ont été rendus (bon de retour {{3}}), reçus par {{4}}. Détail du bon : {{5}} . Merci.',
            self::ManagerReport => 'Bonjour {{1}}, voici le point de la semaine {{2}} pour {{3}} : {{4}} courses créées ({{5}}), {{6}} livrées, taux de livraison {{7}}. Chiffre d\'affaires : {{8}} ({{9}}). E-commerçants à relancer : {{10}}. Toutes les statistiques : {{11}} .',
        };
    }

    /**
     * Titre de la notification push quand le message part sur le téléphone du marchand.
     */
    public function notificationTitle(): string
    {
        return match ($this) {
            self::OrderConfirmed => '📋 Course validée',
            self::OrderConfirmedForDate => '📅 Course validée pour une date',
            self::OrderPickedUp => '📦 Colis récupéré',
            self::OutForDelivery => '🛵 Colis en route',
            self::OrderDelivered => '✅ Colis livré',
            self::DeliveryIncident => '⚠️ Incident de livraison',
            self::PayoutPaid => '💰 Reversement effectué',
            self::ActivityReport => '📊 Votre point d\'activité',
            self::Shipped, self::ShippedRecipient => '🚌 Colis expédié',
            self::ReturnSlip => '↩️ Colis en retour',
            self::ReturnHanded => '↩️ Colis rendus',
            self::ManagerReport => '📊 Point de la semaine',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::OrderConfirmed => 'Au marchand, quand sa course est validée',
            self::OrderConfirmedForDate => 'Au marchand, quand sa course est validée pour un autre jour (date choisie, heure limite passée ou journée fermée)',
            self::OrderPickedUp => 'Au marchand, quand le colis est récupéré',
            self::OutForDelivery => 'Au destinataire, quand le livreur part livrer (avec le code de livraison)',
            self::OrderDelivered => 'Au marchand, quand le colis est livré',
            self::DeliveryIncident => 'Au marchand, en cas d\'échec de livraison, de ramassage ou de report',
            self::PayoutPaid => 'Au marchand, quand son reversement est payé',
            self::ActivityReport => 'Au marchand, point quotidien ou hebdomadaire',
            self::Shipped => 'Au marchand, quand un colis est déposé à la gare (zone d\'expédition)',
            self::ShippedRecipient => 'Au destinataire, quand son colis est déposé à la gare',
            self::ReturnSlip => 'Au marchand, quand un bon de retour de ses colis est confié à un livreur',
            self::ReturnHanded => 'Au marchand, quand ses colis lui ont été rendus (bon de retour signé)',
            self::ManagerReport => 'Aux administrateurs de l\'entreprise, point de la semaine (lundi matin)',
        };
    }

    /**
     * Exemples demandés par Meta lors de la soumission.
     *
     * @return list<string>
     */
    public function example(): array
    {
        return match ($this) {
            self::OrderConfirmed => ['Boutique Chic', 'LV-7K2M-9PQX', 'Awa Koné (Yopougon)', '1 500 F'],
            self::OrderConfirmedForDate => ['Boutique Chic', 'LV-7K2M-9PQX', 'Awa Koné (Yopougon)', 'mardi 21/10', 'livraisons du jour closes depuis 16:00', '1 500 F'],
            self::OrderPickedUp => ['Boutique Chic', 'LV-7K2M-9PQX', 'Awa Koné (Yopougon)'],
            self::OutForDelivery => ['Awa', 'Boutique Chic', '13 500 F', '4821', 'https://exemple.ci/suivi/LV-7K2M-9PQX'],
            self::OrderDelivered => ['Boutique Chic', 'LV-7K2M-9PQX', 'Awa Koné (Yopougon)', '13 500 F'],
            self::DeliveryIncident => ['Boutique Chic', 'LV-7K2M-9PQX', 'Awa Koné (Yopougon)', 'Client injoignable', 'https://exemple.ci/marchand/courses/12'],
            self::PayoutPaid => ['Boutique Chic', 'RV-2610-0004', '154 000 F', 'Wave'],
            self::ActivityReport => ['Boutique Chic', 'du 08/10/2026', '24', '20', '3', '1', '310 000 F', '36 000 F', '6 000 F', '268 000 F'],
            self::Shipped => ['Boutique Chic', 'LV-7K2M-9PQX', 'Awa Koné (Bouaké)', 'UTB Adjamé', 'A-15234', '3 000 F'],
            self::ShippedRecipient => ['Awa', 'Boutique Chic', 'UTB Adjamé', 'A-15234'],
            self::ReturnSlip => ['Boutique Chic', 'Koffi', '4', 'BR-261009-00012', 'https://exemple.ci/bon-de-retour/12'],
            self::ReturnHanded => ['Boutique Chic', '4', 'BR-261009-00012', 'Mariam Koné', 'https://exemple.ci/bon-de-retour/12'],
            self::ManagerReport => ['Awa', 'du 05/10 au 11/10', 'Rapide Express', '312', '+12 %', '287', '94 %', '430 500 F', '+8 %', '3', 'https://exemple.ci/admin/statistiques'],
        };
    }

    public function parameterCount(): int
    {
        return count($this->example());
    }

    /**
     * Texte final, paramètres remplacés.
     *
     * @param  list<string>  $params
     */
    public function render(array $params): string
    {
        return preg_replace_callback('/\{\{(\d+)\}\}/', fn ($m) => $params[(int) $m[1] - 1] ?? '', $this->body());
    }
}
