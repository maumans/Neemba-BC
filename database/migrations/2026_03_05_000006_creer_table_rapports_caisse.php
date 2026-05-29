<?php

/**
 * Migration : Création de la table rapports_caisse
 * 
 * Rapport journalier de caisse.
 * Chaque jour ouvré, un rapport est généré (ou peut être créé manuellement)
 * pour tracer les mouvements de caisse.
 * 
 * Le solde de clôture d'un jour = solde d'ouverture du jour suivant
 * Formule : solde_cloture = solde_ouverture + total_entrees - total_sorties
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rapports_caisse', function (Blueprint $table) {
            $table->id();

            /* Date du rapport (un seul rapport par jour et par site) */
            $table->date('date_rapport');

            /* Site concerné par ce rapport */
            $table->string('site');

            /* Solde au début de la journée en GNF */
            $table->decimal('solde_ouverture', 15, 2)->default(0);

            /* Total des entrées (approvisionnements) en GNF */
            $table->decimal('total_entrees', 15, 2)->default(0);

            /* Total des sorties (paiements de bons) en GNF */
            $table->decimal('total_sorties', 15, 2)->default(0);

            /* Solde en fin de journée en GNF */
            $table->decimal('solde_cloture', 15, 2)->default(0);

            /* Observations du caissier */
            $table->text('observations')->nullable();

            /* Caissier qui a validé le rapport */
            $table->foreignId('caissier_id')
                ->nullable()
                ->constrained('users')
                ->onDelete('restrict');

            /* Indique si le rapport est clôturé */
            $table->boolean('cloture')->default(false);

            $table->timestamps();

            /* Un seul rapport par jour et par site */
            $table->unique(['date_rapport', 'site']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rapports_caisse');
    }
};
