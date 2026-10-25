<?php

namespace App\Enums;

/**
 * Permissions de l'application, rangées par domaine (écran Équipe › Rôles). Une valeur ne change jamais : elle est
 * enregistrée sur les rôles et dans le réglage fin des comptes (ajoutées, retirées).
 */
enum Permission: string
{
    // Courses
    case OrdersView = 'orders.view';
    case OrdersCreate = 'orders.create';
    case OrdersDispatch = 'orders.dispatch';
    case OrdersUpdateStatus = 'orders.update_status';
    case OrdersCancel = 'orders.cancel';

    // Livreurs
    case CouriersManage = 'couriers.manage';
    case PlanningManage = 'planning.manage';
    case FleetManage = 'fleet.manage';
    case FieldEventsManage = 'field_events.manage';
    case RatingsManage = 'ratings.manage';
    case PayManage = 'pay.manage';

    // Clients
    case MerchantsView = 'merchants.view';
    case MerchantsManage = 'merchants.manage';
    // Formules des clients et tarifs de la distribution et de l'intérieur
    case RatesManage = 'rates.manage';
    case DepartmentsManage = 'departments.manage';
    case DestinationsManage = 'destinations.manage';
    case StockManage = 'stock.manage';
    // Clés API et webhooks
    case IntegrationsManage = 'integrations.manage';

    // Argent
    case FinanceView = 'finance.view';
    case FinanceManage = 'finance.manage';
    // Factures du mois des clients (distribution, MAD) : consulter ; calculer, émettre, encaisser ; annuler
    case InvoicesView = 'invoices.view';
    case InvoicesManage = 'invoices.manage';
    case InvoicesVoid = 'invoices.void';
    // Chiffre d'affaires par activité
    case RevenueView = 'revenue.view';

    // Réglages et équipe
    case SettingsManage = 'settings.manage';
    case ZonesManage = 'zones.manage';
    case PricingManage = 'pricing.manage';
    case MessagingManage = 'messaging.manage';
    case UsersView = 'users.view';
    case UsersManage = 'users.manage';

    public function label(): string
    {
        return match ($this) {
            self::OrdersView => 'Voir les courses',
            self::OrdersCreate => 'Créer des courses',
            self::OrdersDispatch => 'Dispatch (assigner les livreurs)',
            self::OrdersUpdateStatus => 'Changer l\'état des courses',
            self::OrdersCancel => 'Annuler une course',
            self::CouriersManage => 'Gérer les livreurs',
            self::PlanningManage => 'Planning et automatismes',
            self::FleetManage => 'Véhicules',
            self::FieldEventsManage => 'Remontées et événements terrain',
            self::RatingsManage => 'Notation des livreurs',
            self::PayManage => 'Paie des livreurs',
            self::MerchantsView => 'Voir les clients',
            self::MerchantsManage => 'Gérer les clients',
            self::RatesManage => 'Contrats, formules et tarifs (distribution, intérieur)',
            self::DepartmentsManage => 'Départements des clients',
            self::DestinationsManage => 'Gérer l\'annuaire des destinations',
            self::StockManage => 'Gérer le stock',
            self::IntegrationsManage => 'Clés d\'API et webhooks',
            self::FinanceView => 'Voir la caisse et les reversements',
            self::FinanceManage => 'Encaisser et reverser',
            self::InvoicesView => 'Voir les factures clients',
            self::InvoicesManage => 'Calculer, émettre et encaisser les factures',
            self::InvoicesVoid => 'Annuler une facture émise',
            self::RevenueView => 'Voir le chiffre d\'affaires',
            self::SettingsManage => 'Réglages généraux',
            self::ZonesManage => 'Zones et dépôts',
            self::PricingManage => 'Grille des tarifs',
            self::MessagingManage => 'Messages WhatsApp',
            self::UsersView => 'Voir l\'équipe',
            self::UsersManage => 'Gérer l\'équipe et les rôles',
        };
    }

    /** Ce que la permission ouvre, en une phrase (écran des rôles) */
    public function description(): string
    {
        return match ($this) {
            self::OrdersView => 'Liste et fiche des courses, statistiques.',
            self::OrdersCreate => 'Nouvelle course, import Excel, message collé.',
            self::OrdersDispatch => 'Assigner et retirer les livreurs, carte, disponibilités, journée de livraison, retours, journal des messages.',
            self::OrdersUpdateStatus => 'Faire avancer une course (réception au dépôt, préparation).',
            self::OrdersCancel => 'Annuler une course de l\'équipe (le client annule toujours la sienne avant ramassage).',
            self::CouriersManage => 'Fiche du livreur : zones, véhicule, règle d\'acceptation des missions.',
            self::PlanningManage => 'Règles du planning, absences, pause et réglage des automatismes.',
            self::FleetManage => 'Parc, papiers, entretien, carnet, échéances et réglages des véhicules.',
            self::FieldEventsManage => 'Pannes, accidents, notes des livreurs : suivi et réglages.',
            self::RatingsManage => 'Neutraliser ou rétablir une note, réglages de la notation.',
            self::PayManage => 'Plans de paie, simulateur, plan de chaque livreur.',
            self::MerchantsView => 'Liste et fiche des clients.',
            self::MerchantsManage => 'Créer et modifier les clients, leurs comptes et leurs demandes d\'ouverture.',
            self::RatesManage => 'Contrats, forfaits, lots, suppléments, tarifs de la distribution et de l\'intérieur.',
            self::DepartmentsManage => 'Départements des clients, comptes rattachés, quotas et réglages.',
            self::DestinationsManage => 'Ajouter, corriger, regrouper, importer les destinations ; réglages de l\'annuaire.',
            self::StockManage => 'Entrepôt : produits, mouvements, préparation.',
            self::IntegrationsManage => 'Clés de l\'API publique et webhooks des clients.',
            self::FinanceView => 'Caisse, encaissements des livreurs, reversements.',
            self::FinanceManage => 'Encaisser, avancer, ajuster, reverser, payer les livreurs.',
            self::InvoicesView => 'Factures du mois des clients sous contrat.',
            self::InvoicesManage => 'Calculer, compléter, émettre et encaisser les factures.',
            self::InvoicesVoid => 'Annuler une facture déjà émise.',
            self::RevenueView => 'Chiffre d\'affaires par activité.',
            self::SettingsManage => 'Entreprise et marque, courses, alertes, clients, mise en route, reprise de l\'ancienne application.',
            self::ZonesManage => 'Communes, quartiers, zones au forfait, dépôts.',
            self::PricingManage => 'Grilles de prix, suppléments, frais de retour.',
            self::MessagingManage => 'Connexion WhatsApp, modèles de messages, essais.',
            self::UsersView => 'Liste de l\'équipe.',
            self::UsersManage => 'Créer les comptes, leur donner un rôle, gérer les rôles sur mesure.',
        };
    }

    public function domain(): string
    {
        return match ($this) {
            self::OrdersView, self::OrdersCreate, self::OrdersDispatch, self::OrdersUpdateStatus, self::OrdersCancel => 'orders',
            self::CouriersManage, self::PlanningManage, self::FleetManage, self::FieldEventsManage, self::RatingsManage, self::PayManage => 'couriers',
            self::MerchantsView, self::MerchantsManage, self::RatesManage, self::DepartmentsManage, self::DestinationsManage,
            self::StockManage, self::IntegrationsManage => 'merchants',
            self::FinanceView, self::FinanceManage, self::InvoicesView, self::InvoicesManage, self::InvoicesVoid, self::RevenueView => 'money',
            self::SettingsManage, self::ZonesManage, self::PricingManage, self::MessagingManage, self::UsersView, self::UsersManage => 'settings',
        };
    }

    /** @return array<string, string> domaine => libellé, dans l'ordre d'affichage */
    public static function domains(): array
    {
        return [
            'orders' => 'Courses',
            'couriers' => 'Livreurs',
            'merchants' => 'Clients',
            'money' => 'Argent',
            'settings' => 'Réglages et équipe',
        ];
    }

    /**
     * Toutes les permissions de l'entreprise (rôle administrateur).
     *
     * @return list<self>
     */
    public static function companyScoped(): array
    {
        return self::cases();
    }

    /**
     * Permissions nées du découpage d'une ancienne permission large : un compte qui avait l'ancienne en plus (ou en
     * moins) de son rôle reçoit (ou perd) aussi les nouvelles, pour que rien ne change à la mise à jour.
     *
     * @return array<string, list<string>>
     */
    public static function splits(): array
    {
        return [
            self::SettingsManage->value => [self::ZonesManage->value, self::PricingManage->value, self::MessagingManage->value,
                self::PayManage->value, self::RatingsManage->value],
            self::OrdersDispatch->value => [self::OrdersCancel->value, self::PlanningManage->value, self::FleetManage->value, self::FieldEventsManage->value],
            self::MerchantsManage->value => [self::DepartmentsManage->value, self::DestinationsManage->value],
            self::UsersManage->value => [self::CouriersManage->value, self::RatingsManage->value],
            self::InvoicesManage->value => [self::InvoicesVoid->value],
        ];
    }
}
