<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Modèle Service - Table de paramétrage
 * 
 * Représente un service de l'entreprise (ex: Direction Générale, Finance...).
 * Administrable par le DAF/Directeur Pays.
 */
class Service extends Model
{
    use HasFactory;

    protected $fillable = ['nom', 'code', 'actif'];

    protected $casts = [
        'actif' => 'boolean',
    ];

    public function scopeActifs($query)
    {
        return $query->where('actif', true);
    }

    public function codesAnalytiques()
    {
        return $this->hasMany(CodeAnalytique::class);
    }
}
