<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('otp_validations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bon_caisse_id')->constrained('bons_caisse')->onDelete('cascade');
            $table->string('code', 6); // Code OTP à 6 chiffres
            $table->string('telephone'); // Numéro du destinataire
            $table->timestamp('expires_at'); // Date d'expiration du code
            $table->timestamp('verified_at')->nullable(); // Date de vérification (null si pas encore vérifié)
            $table->boolean('is_used')->default(false); // Indique si le code a été utilisé pour un paiement
            $table->timestamps();

            $table->index(['bon_caisse_id', 'verified_at']);
            $table->index('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('otp_validations');
    }
};
