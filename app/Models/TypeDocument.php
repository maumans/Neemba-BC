<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Modèle TypeDocument - Table de paramétrage
 * 
 * Représente un type de document justificatif (facture, devis, bon de commande...).
 * Administrable par le DAF/Directeur Pays.
 */
class TypeDocument extends Model
{
    use HasFactory;

    protected $table = 'types_document';

    protected $fillable = ['nom', 'actif', 'duree_conservation_mois'];

    protected $casts = [
        'actif' => 'boolean',
    ];

    public function scopeActifs($query)
    {
        return $query->where('actif', true);
    }
}
