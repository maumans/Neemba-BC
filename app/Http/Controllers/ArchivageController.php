<?php

namespace App\Http\Controllers;

use App\Models\PieceJointe;
use App\Services\ClassificationDocumentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

/**
 * Contrôleur Archivage Centralisé
 * 
 * Module d'archivage numérique des documents de caisse :
 * - Navigation arborescente (Année > Service > Code Analytique > Réf BC > Fichier)
 * - Recherche full-text (numéro BC, bénéficiaire, montant, date, site)
 * - Consultation des pièces avec classification IA
 * - Archivage manuel avec rétention 5 ans
 * - Contrôle qualité des scans (DPI)
 * - Prévisualisation des documents
 */
class ArchivageController extends Controller
{
    /**
     * Page principale d'archivage : arborescence + recherche
     */
    public function index(Request $request)
    {
        /* Construire l'arborescence des documents */
        $arborescence = $this->construireArborescence();

        /* Recherche et filtrage avancé (mode tableau) */
        $documents = null;
        $recherche = $request->input('recherche');
        $aDesFiltres = $recherche
            || $request->filled('classification')
            || $request->filled('type_document')
            || $request->filled('site')
            || $request->filled('date_debut')
            || $request->filled('date_fin')
            || $request->filled('archive')
            || $request->filled('qualite');

        if ($aDesFiltres) {
            $query = PieceJointe::with(['bonCaisse.demandeur', 'bonCaisse.ordreMission']);

            /* Recherche textuelle */
            if ($recherche) {
                $query->recherche($recherche);
            }

            /* Classification IA */
            if ($classification = $request->input('classification')) {
                $query->where('classification_ia', $classification);
            }

            /* Type de document (sélectionné par l'utilisateur) */
            if ($typeDocument = $request->input('type_document')) {
                $query->where('type_document', $typeDocument);
            }

            /* Filtrer par site du bon associé */
            if ($site = $request->input('site')) {
                $query->whereHas('bonCaisse', fn($q) => $q->where('site', 'like', "%{$site}%"));
            }

            /* Plage de dates */
            if ($dateDebut = $request->input('date_debut')) {
                $query->whereDate('created_at', '>=', $dateDebut);
            }
            if ($dateFin = $request->input('date_fin')) {
                $query->whereDate('created_at', '<=', $dateFin);
            }

            /* Statut archivage */
            if ($request->input('archive') === 'oui') {
                $query->whereNotNull('date_archivage');
            } elseif ($request->input('archive') === 'non') {
                $query->whereNull('date_archivage');
            }

            /* Qualité scan */
            if ($request->input('qualite') === 'ok') {
                $query->where('qualite_ok', true);
            } elseif ($request->input('qualite') === 'faible') {
                $query->where('qualite_ok', false);
            }

            $documents = $query->orderByDesc('created_at')
                ->paginate(25)
                ->withQueryString();
        }

        /* Liste des sites pour le filtre */
        $sites = \App\Models\Site::actifs()->orderBy('nom')->pluck('nom');

        return Inertia::render('Archivage/Index', [
            'arborescence' => $arborescence,
            'documents' => $documents,
            'filtres' => $request->only([
                'recherche', 'classification', 'type_document',
                'site', 'date_debut', 'date_fin', 'archive', 'qualite',
            ]),
            'classificationsIa' => PieceJointe::CLASSIFICATIONS_IA,
            'typesDocuments' => PieceJointe::TYPES_DOCUMENTS,
            'sites' => $sites,
        ]);
    }

    /**
     * Construire l'arborescence : Année > Service > Code Analytique > Réf BC > Fichiers
     */
    private function construireArborescence(): array
    {
        $pieces = PieceJointe::with(['bonCaisse'])
            ->whereHas('bonCaisse')
            ->orderBy('created_at', 'desc')
            ->get();

        $arbre = [];

        foreach ($pieces as $piece) {
            $bon = $piece->bonCaisse;
            if (!$bon) continue;

            $annee = $piece->created_at->format('Y');
            $service = $bon->service ?: 'Non défini';
            $codeAnalytique = $bon->code_analytique ?: 'Sans code';
            $reference = $bon->numero;

            /* Initialiser les niveaux */
            if (!isset($arbre[$annee])) {
                $arbre[$annee] = ['label' => $annee, 'enfants' => [], 'count' => 0];
            }
            if (!isset($arbre[$annee]['enfants'][$service])) {
                $arbre[$annee]['enfants'][$service] = ['label' => $service, 'enfants' => [], 'count' => 0];
            }
            if (!isset($arbre[$annee]['enfants'][$service]['enfants'][$codeAnalytique])) {
                $arbre[$annee]['enfants'][$service]['enfants'][$codeAnalytique] = ['label' => $codeAnalytique, 'enfants' => [], 'count' => 0];
            }
            if (!isset($arbre[$annee]['enfants'][$service]['enfants'][$codeAnalytique]['enfants'][$reference])) {
                $arbre[$annee]['enfants'][$service]['enfants'][$codeAnalytique]['enfants'][$reference] = [
                    'label' => $reference,
                    'bon_id' => $bon->id,
                    'beneficiaire' => $bon->beneficiaire,
                    'fichiers' => [],
                    'count' => 0,
                ];
            }

            /* Ajouter le fichier */
            $arbre[$annee]['enfants'][$service]['enfants'][$codeAnalytique]['enfants'][$reference]['fichiers'][] = [
                'id' => $piece->id,
                'nom_fichier' => $piece->nom_fichier,
                'chemin_fichier' => $piece->chemin_fichier,
                'taille' => $piece->taille,
                'mime_type' => $piece->mime_type,
                'type_document' => $piece->type_document,
                'classification_ia' => $piece->classification_ia,
                'qualite_ok' => $piece->qualite_ok,
                'dpi_detecte' => $piece->dpi_detecte,
                'date_archivage' => $piece->date_archivage?->format('d/m/Y'),
                'created_at' => $piece->created_at->format('d/m/Y'),
                'version' => $piece->version ?? 1,
            ];

            /* Incrémenter les compteurs */
            $arbre[$annee]['count']++;
            $arbre[$annee]['enfants'][$service]['count']++;
            $arbre[$annee]['enfants'][$service]['enfants'][$codeAnalytique]['count']++;
            $arbre[$annee]['enfants'][$service]['enfants'][$codeAnalytique]['enfants'][$reference]['count']++;
        }

        /* Convertir les clés associatives en tableaux indexés et trier */
        return $this->convertirEnTableau($arbre);
    }

    /**
     * Convertir récursivement les tableaux associatifs en tableaux indexés
     */
    private function convertirEnTableau(array $noeud): array
    {
        $result = [];
        /* Tri par clé (année desc, service asc) */
        krsort($noeud);

        foreach ($noeud as $cle => $valeur) {
            $item = [
                'label' => $valeur['label'],
                'count' => $valeur['count'],
            ];

            if (isset($valeur['enfants'])) {
                ksort($valeur['enfants']);
                $item['enfants'] = $this->convertirEnTableau($valeur['enfants']);
            }

            if (isset($valeur['fichiers'])) {
                $item['fichiers'] = $valeur['fichiers'];
                $item['bon_id'] = $valeur['bon_id'];
                $item['beneficiaire'] = $valeur['beneficiaire'];
            }

            $result[] = $item;
        }

        return $result;
    }

    /**
     * Détail d'un document avec lien traçable vers BC et ordre de mission
     */
    public function show(PieceJointe $piece)
    {
        $piece->load([
            'bonCaisse.demandeur',
            'bonCaisse.ordreMission',
            'bonCaisse.piecesJointes',
            'bonCaisse.validations.validateur',
        ]);

        return Inertia::render('Archivage/Show', [
            'piece' => $piece,
            'classificationsIa' => PieceJointe::CLASSIFICATIONS_IA,
            'typesDocuments' => PieceJointe::TYPES_DOCUMENTS,
        ]);
    }

    /**
     * Archiver un document (rétention 5 ans)
     */
    public function archiver(PieceJointe $piece)
    {
        if ($piece->estArchive()) {
            return back()->with('error', 'Ce document est déjà archivé.');
        }

        $piece->archiver(Auth::id());

        return back()->with('success', 'Document archivé avec succès (rétention : 5 ans).');
    }

    /**
     * Archiver en masse les documents d'un bon de caisse
     */
    public function archiverBon(Request $request)
    {
        $validated = $request->validate([
            'bon_caisse_id' => ['required', 'exists:bons_caisse,id'],
        ]);

        $pieces = PieceJointe::where('bon_caisse_id', $validated['bon_caisse_id'])
            ->whereNull('date_archivage')
            ->get();

        $count = 0;
        foreach ($pieces as $piece) {
            $piece->archiver(Auth::id());
            $count++;
        }

        return back()->with('success', "{$count} document(s) archivé(s) avec succès.");
    }

    /**
     * Reclassifier manuellement un document
     */
    public function reclassifier(Request $request, PieceJointe $piece)
    {
        $validated = $request->validate([
            'classification' => ['required', 'string', 'in:' . implode(',', array_keys(PieceJointe::CLASSIFICATIONS_IA))],
        ]);

        $piece->appliquerClassification($validated['classification'], 100);

        return back()->with('success', 'Classification mise à jour.');
    }

    /**
     * Relancer la classification IA sur un document
     */
    public function relancerClassification(PieceJointe $piece)
    {
        $service = app(ClassificationDocumentService::class);
        $resultat = $service->classifierEtAppliquer($piece);

        return back()->with('success', "Classification IA : {$resultat['type']} (confiance : {$resultat['confiance']}%).");
    }
}
