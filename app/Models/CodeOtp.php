<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Code à usage unique de la double authentification (spec v2.4, RG-M01-07) : connexion (email) ou visa d'un bon ou d'un
 * ODM urgent (SMS). Le code n'est jamais stocké en clair. Distinct de l'OTP de paiement (`OtpValidation`, RG-M06-02).
 */
class CodeOtp extends Model
{
    protected $table = 'codes_otp';

    public const OBJETS = ['connexion', 'visa_bon', 'visa_odm'];

    protected $fillable = ['user_id', 'objet', 'cible_type', 'cible_id', 'canal', 'code_hash', 'tentatives', 'expire_le', 'consomme_le'];

    protected function casts(): array
    {
        return ['expire_le' => 'datetime', 'consomme_le' => 'datetime'];
    }

    public function cible(): MorphTo
    {
        return $this->morphTo();
    }

    public static function empreinte(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}
