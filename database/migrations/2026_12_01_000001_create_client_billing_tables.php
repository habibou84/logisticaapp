<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Formules de facturation des clients en dehors des courses ordinaires : distribution (au forfait ou à
 * l'unité) et mise à disposition de livreurs (MAD), tarifs de l'intérieur, factures mensuelles.
 */
return new class extends Migration
{
    /** Les dix communes d'Abidjan couvertes par les forfaits */
    private const FORFAIT_COMMUNES = ['Abobo', 'Adjamé', 'Attécoubé', 'Cocody', 'Koumassi', 'Marcory', 'Plateau', 'Port-Bouët', 'Treichville', 'Yopougon'];

    public function up(): void
    {
        Schema::table('zones', function (Blueprint $table) {
            // Commune couverte par les forfaits (distribution, MAD)
            $table->boolean('in_forfait')->default(false)->after('is_shipping');
        });
        DB::table('zones')->whereNull('parent_id')->where('city', 'Abidjan')->whereIn('name', self::FORFAIT_COMMUNES)->update(['in_forfait' => true]);

        Schema::create('billing_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            // distribution_contract | distribution_unit | mad_contract
            $table->string('formula', 30);
            // Forfait du mois, plis/colis (ou courses) inclus, prix de chacun au-delà
            $table->unsignedInteger('monthly_fee')->default(0);
            $table->unsignedInteger('included_quantity')->default(0);
            $table->unsignedInteger('overage_price')->default(0);
            // Délai de livraison du contrat : 24h | 48h | 72h
            $table->string('delivery_delay', 3)->default('24h');
            // Chèque livré (hors forfait) et kilogramme au-delà de 5 kg sur Abidjan
            $table->unsignedInteger('cheque_price')->default(0);
            $table->unsignedInteger('extra_kg_price')->default(0);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->string('notes', 1000)->nullable();
            $table->timestamps();
            $table->index(['company_id', 'merchant_id']);
        });

        // Livreurs mis à disposition du client (MAD)
        Schema::create('billing_plan_courier', function (Blueprint $table) {
            $table->foreignId('billing_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('courier_id')->constrained()->cascadeOnDelete();
            $table->primary(['billing_plan_id', 'courier_id']);
        });

        // Distribution à l'unité sur Abidjan : prix par délai et par type d'envoi
        Schema::create('distribution_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('delivery_delay', 3);
            // pli | colis | cheque
            $table->string('shipment_type', 10);
            $table->unsignedInteger('price')->default(0);
            $table->timestamps();
            $table->unique(['company_id', 'delivery_delay', 'shipment_type']);
        });

        // Villes de l'intérieur : pli (0-500 g), colis (0-5 kg) et kilogramme en plus, par délai
        Schema::create('interior_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('city', 100);
            $table->string('delivery_delay', 3);
            $table->unsignedInteger('pli_price')->default(0);
            $table->unsignedInteger('colis_price')->default(0);
            $table->unsignedInteger('extra_kg_price')->default(0);
            $table->timestamps();
            $table->unique(['company_id', 'city', 'delivery_delay']);
        });

        Schema::table('orders', function (Blueprint $table) {
            // pli | colis | cheque (clients sous formule)
            $table->string('shipment_type', 10)->nullable()->after('package_size');
            $table->string('delivery_delay', 3)->nullable()->after('shipment_type');
            $table->foreignId('billing_plan_id')->nullable()->after('pricing_details')->constrained()->nullOnDelete();
            // Comment la course est facturée : forfait, unit (Abidjan à l'unité), interior, cheque, outside (hors forfait)
            $table->string('billing_basis', 10)->nullable()->after('billing_plan_id');
            // Montant facturé au mois pour cette course (hors forfait), ou excédent de poids d'une course au forfait
            $table->unsignedInteger('billing_amount')->nullable()->after('billing_basis');
            $table->index(['billing_plan_id', 'created_at']);
        });

        Schema::create('client_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            // Numéro donné à l'émission (FAC-AAAAMM-0001)
            $table->string('number', 30)->nullable()->unique();
            // Premier jour du mois facturé
            $table->date('period');
            // draft | issued | paid | void
            $table->string('status', 10)->default('draft');
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('amount_paid')->default(0);
            $table->date('issued_at')->nullable();
            $table->date('due_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('notes', 1000)->nullable();
            $table->timestamps();
            $table->unique(['merchant_id', 'period']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('client_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_invoice_id')->constrained()->cascadeOnDelete();
            // forfait | included | overage | extra_kg | cheque | interior | unit | outside | adjustment
            $table->string('kind', 12);
            $table->string('label');
            $table->integer('quantity')->default(1);
            $table->integer('unit_price')->default(0);
            $table->integer('amount')->default(0);
            // Ligne ajoutée à la main (remise, frais) : gardée quand la facture est recalculée
            $table->boolean('manual')->default(false);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('client_invoice_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_invoice_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('amount');
            // cash | mobile_money | bank_transfer | cheque | other
            $table->string('method', 20);
            $table->string('reference', 100)->nullable();
            $table->date('paid_on');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('notes', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_invoice_payments');
        Schema::dropIfExists('client_invoice_lines');
        Schema::dropIfExists('client_invoices');
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['billing_plan_id', 'created_at']);
            $table->dropConstrainedForeignId('billing_plan_id');
            $table->dropColumn(['shipment_type', 'delivery_delay', 'billing_basis', 'billing_amount']);
        });
        Schema::dropIfExists('interior_rates');
        Schema::dropIfExists('distribution_rates');
        Schema::dropIfExists('billing_plan_courier');
        Schema::dropIfExists('billing_plans');
        Schema::table('zones', fn (Blueprint $table) => $table->dropColumn('in_forfait'));
    }
};
