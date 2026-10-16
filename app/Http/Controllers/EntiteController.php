<?php

namespace App\Http\Controllers;

use App\Models\Entite;
use App\Support\EntiteCourante;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Spec v2.4, RG-M01-02, RG-M01-08 : choix de l'entité de travail (ou de la vue consolidée) et données d'impression de
 * chaque entité. La mise en service d'une entité (Neemba Mining) relève d'une proposition distincte : elle ne se fait pas
 * depuis l'écran.
 */
class EntiteController extends Controller
{
    /** POST /entite-courante — entité de travail, ou « consolide » pour les rôles habilités */
    public function choisir(Request $request)
    {
        $choix = (string) $request->validate(['entite' => ['required', 'string', 'max:20']])['entite'];
        if (!EntiteCourante::choisir(Auth::user(), $choix)) {
            return back()->with('error', 'Vous n\'avez pas de droits dans cette entité.');
        }

        return redirect()->route('dashboard')->with('success', $choix === EntiteCourante::CONSOLIDE
            ? 'Vue consolidée : toutes les entités.'
            : 'Entité de travail : ' . Entite::find((int) $choix)?->nom . '.');
    }

    /** PUT /parametrage/entites/{entite} — raison sociale, adresse et logo imprimés sur les bons et les ODM */
    public function update(Request $request, Entite $entite)
    {
        $entite->update($request->validate([
            'nom' => ['required', 'string', 'max:120'],
            'raison_sociale' => ['nullable', 'string', 'max:150'],
            'adresse' => ['nullable', 'string', 'max:500'],
            'logo' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9_\-\/.]+\.(png|jpg|jpeg)$/'],
        ]));
        Entite::oublierCache();

        return back()->with('success', 'Entité mise à jour.');
    }
}
