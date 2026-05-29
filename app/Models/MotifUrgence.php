<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MotifUrgence extends Model
{
    use HasFactory;

    protected $table = 'motifs_urgence';

    protected $fillable = [
        'libelle',
        'actif',
    ];

    protected function casts(): array
    {
        return [
            'actif' => 'boolean',
        ];
    }
}
