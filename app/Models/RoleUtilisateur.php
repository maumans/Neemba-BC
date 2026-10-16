<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rôle attribué à un utilisateur (un utilisateur peut en avoir plusieurs).
 * Le rôle principal reste aussi dans users.role.
 */
class RoleUtilisateur extends Model
{
    protected $table = 'roles_utilisateurs';

    /* Spec v2.4, RG-M01-08 : un rôle vaut dans une entité (vide : toutes) */
    protected $fillable = ['user_id', 'role', 'entite_id'];

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
