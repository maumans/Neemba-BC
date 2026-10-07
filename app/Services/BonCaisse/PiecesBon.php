<?php

namespace App\Services\BonCaisse;

use App\Exceptions\ErreurMetier;
use App\Jobs\ProcessPieceJointeOcrJob;
use App\Models\BonCaisse;
use App\Models\PieceJointe;
use App\Models\User;
use App\Services\LectureTicket\LectureTickets;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Pièces justificatives d'un bon (US-BC-08, E-03.6).
 *
 * - Chaque fichier est enregistré dès son dépôt, avec sa qualité (RG-BC-16) et son empreinte SHA-256.
 * - Une pièce identique déjà jointe à un autre bon non annulé est signalée (RG-BC-19) : le demandeur doit confirmer
 *   qu'elle concerne une autre dépense et le justifier.
 * - Un ticket carburant lance sa lecture (RG-BC-20).
 * - Avant soumission, une pièce se supprime ; une pièce déjà soumise se remplace par une nouvelle version.
 */
class PiecesBon
{
    /** RG-BC-17 : 20 fichiers et 50 Mo par bon */
    public const NOMBRE_MAX = 20;
    public const POIDS_MAX = 50 * 1024 * 1024;

    public static function ajouter(BonCaisse $bon, UploadedFile $fichier, ?string $type, User $auteur): PieceJointe
    {
        self::verifierLimites($bon, $fichier);

        $piece = self::enregistrer($bon, $fichier, ['type_document' => $type]);
        $bon->enregistrerAjoutPieceJointe($piece->nom_fichier, $auteur->id);

        return $piece;
    }

    /** Nouvelle version d'une pièce déjà soumise : l'ancienne reste dans l'historique du bon */
    public static function remplacer(BonCaisse $bon, PieceJointe $ancienne, UploadedFile $fichier, User $auteur): PieceJointe
    {
        if ($ancienne->remplacee_par_id !== null) {
            throw new ErreurMetier('PIECE_DEJA_REMPLACEE', 'MSG-APP-002', [], null, 'pieces', 409);
        }
        self::verifierLimites($bon, $fichier, $ancienne);

        return DB::transaction(function () use ($bon, $ancienne, $fichier, $auteur) {
            $piece = self::enregistrer($bon, $fichier, [
                'type_document' => $ancienne->type_document,
                'version' => $ancienne->version + 1,
            ]);
            $ancienne->update(['remplacee_par_id' => $piece->id]);
            $bon->enregistrerAjoutPieceJointe("{$piece->nom_fichier} (version {$piece->version} de {$ancienne->nom_fichier})", $auteur->id);

            return $piece;
        });
    }

    /** Une pièce jamais soumise se supprime ; après soumission, elle se remplace (MSG-APP-004) */
    public static function supprimable(BonCaisse $bon, PieceJointe $piece): bool
    {
        if ($bon->statut === 'BROUILLON') {
            return true;
        }

        /* Bon rejeté : seules les pièces ajoutées depuis la dernière soumission n'ont jamais été soumises */
        return $bon->statut === 'REJETE' && $bon->date_soumission !== null && $piece->created_at?->gt($bon->date_soumission);
    }

    public static function supprimer(BonCaisse $bon, PieceJointe $piece): void
    {
        if (!self::supprimable($bon, $piece)) {
            throw new ErreurMetier('PIECE_NON_SUPPRIMABLE', 'MSG-APP-004', [], null, 'pieces', 409);
        }

        Storage::disk('public')->delete($piece->chemin_fichier);
        $piece->delete();
    }

    /** Type de la pièce (RG-BC-18) ; un ticket carburant lance sa lecture (RG-BC-20) */
    public static function typer(PieceJointe $piece, string $type): PieceJointe
    {
        $piece->update(['type_document' => $type]);
        if ($type === 'recu_carburant') {
            LectureTickets::demarrer($piece);
        }

        return $piece;
    }

    /** RG-BC-19 : le demandeur confirme que la pièce concerne une autre dépense et l'explique */
    public static function confirmerDoublon(PieceJointe $piece, string $justification): PieceJointe
    {
        $piece->update(['doublon_confirme' => true, 'justification_doublon' => trim($justification)]);

        return $piece;
    }

    /* ------------------------------------------------------------------ */

    private static function verifierLimites(BonCaisse $bon, UploadedFile $fichier, ?PieceJointe $remplacee = null): void
    {
        $pieces = $bon->piecesActives()->get()->reject(fn (PieceJointe $p) => $p->is($remplacee));
        if ($pieces->count() >= self::NOMBRE_MAX || $pieces->sum('taille') + $fichier->getSize() > self::POIDS_MAX) {
            throw new ErreurMetier('LIMITE_PIECES', 'MSG-APP-003', [], 'RG-BC-17', 'fichier');
        }
    }

    private static function enregistrer(BonCaisse $bon, UploadedFile $fichier, array $attributs): PieceJointe
    {
        $chemin = $fichier->store('pieces_jointes/' . $bon->id, 'public');
        $mime = $fichier->getMimeType();
        $qualite = QualitePiece::evaluer(Storage::disk('public')->path($chemin), $mime);

        $piece = PieceJointe::create($attributs + [
            'bon_caisse_id' => $bon->id,
            'nom_fichier' => $fichier->getClientOriginalName(),
            'chemin_fichier' => $chemin,
            'taille' => $fichier->getSize(),
            'mime_type' => $mime,
            'qualite' => $qualite['qualite'],
            'qualite_ok' => $qualite['qualite'] !== QualitePiece::ILLISIBLE,
            'dpi_detecte' => $qualite['dpi'],
            'checksum' => hash_file('sha256', $fichier->getRealPath()),
        ]);

        self::reperer($piece);
        ProcessPieceJointeOcrJob::dispatch($piece->id);
        if ($piece->type_document === 'recu_carburant') {
            LectureTickets::demarrer($piece);
        }

        return $piece;
    }

    /** RG-BC-19 : même empreinte qu'une pièce d'un autre bon non annulé */
    private static function reperer(PieceJointe $piece): void
    {
        $original = PieceJointe::query()
            ->where('checksum', $piece->checksum)
            ->where('bon_caisse_id', '!=', $piece->bon_caisse_id)
            ->whereHas('bonCaisse', fn ($bons) => $bons->where('statut', '!=', 'ANNULE'))
            ->orderBy('id')
            ->first();

        if ($original) {
            $piece->update(['doublon_de_id' => $original->id]);
        }
    }
}
