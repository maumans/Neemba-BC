<?php

namespace App\Services;

use App\Models\PieceJointe;
use Illuminate\Support\Facades\Log;

/**
 * Service de Classification IA des Documents
 * 
 * Classifie automatiquement les pièces jointes par type :
 * bon_caisse, facture, proforma, ordre_mission, recu_carburant, rapport_journalier, recu, autre
 * 
 * Stratégie :
 * 1. Classification par type_document déjà renseigné (confiance 100%)
 * 2. Classification par analyse du texte OCR (mots-clés, patterns)
 * 3. Classification par nom de fichier (fallback)
 */
class ClassificationDocumentService
{
    /**
     * Règles de classification par mots-clés dans le texte OCR
     * Chaque type a un ensemble de mots-clés pondérés
     */
    const REGLES_CLASSIFICATION = [
        'facture' => [
            'mots' => ['facture', 'invoice', 'n° facture', 'numéro facture', 'facture n°', 'total ttc', 'total ht', 'tva', 'sous-total'],
            'poids' => 3,
        ],
        'proforma' => [
            'mots' => ['proforma', 'pro forma', 'devis', 'estimation', 'cotation', 'offre de prix'],
            'poids' => 3,
        ],
        'ordre_mission' => [
            'mots' => ['ordre de mission', 'mission', 'déplacement', 'itinéraire', 'frais de mission', 'per diem', 'indemnité'],
            'poids' => 3,
        ],
        'recu_carburant' => [
            'mots' => ['carburant', 'gasoil', 'essence', 'diesel', 'station', 'litre', 'litrage', 'pompe', 'plein', 'immatriculation'],
            'poids' => 2,
        ],
        'rapport_journalier' => [
            'mots' => ['rapport journalier', 'rapport de caisse', 'solde ouverture', 'solde clôture', 'récapitulatif journalier'],
            'poids' => 3,
        ],
        'bon_caisse' => [
            'mots' => ['bon de caisse', 'bon caisse', 'BC-', 'décaissement', 'bénéficiaire', 'mode de paiement'],
            'poids' => 2,
        ],
        'recu' => [
            'mots' => ['reçu', 'reçu de paiement', 'quittance', 'acquitté', 'paiement reçu', 'accusé de réception'],
            'poids' => 2,
        ],
    ];

    /**
     * Classifier automatiquement une pièce jointe
     */
    public function classifier(PieceJointe $piece): array
    {
        /* Priorité 1 : type_document déjà renseigné par l'utilisateur */
        if ($piece->type_document && $piece->type_document !== 'autre') {
            $typeMap = $this->mapperTypeDocumentVersClassification($piece->type_document);
            return [
                'type' => $typeMap,
                'confiance' => 100,
                'source' => 'utilisateur',
            ];
        }

        /* Priorité 2 : analyse du texte OCR */
        if ($piece->ocr_texte_brut) {
            $resultat = $this->analyserTexte($piece->ocr_texte_brut);
            if ($resultat['confiance'] >= 50) {
                return $resultat;
            }
        }

        /* Priorité 3 : analyse du nom de fichier */
        $resultatNom = $this->analyserNomFichier($piece->nom_fichier);
        if ($resultatNom['confiance'] >= 40) {
            return $resultatNom;
        }

        /* Fallback */
        return [
            'type' => 'autre',
            'confiance' => 20,
            'source' => 'defaut',
        ];
    }

    /**
     * Classifier et appliquer le résultat sur la pièce jointe
     */
    public function classifierEtAppliquer(PieceJointe $piece): array
    {
        $resultat = $this->classifier($piece);

        $piece->appliquerClassification($resultat['type'], $resultat['confiance']);

        Log::info("Classification IA : pièce #{$piece->id} → {$resultat['type']} (confiance: {$resultat['confiance']}%, source: {$resultat['source']})");

        return $resultat;
    }

    /**
     * Analyser le texte OCR pour déterminer le type de document
     */
    protected function analyserTexte(string $texte): array
    {
        $texteNorm = mb_strtolower($texte);
        $scores = [];

        foreach (self::REGLES_CLASSIFICATION as $type => $regle) {
            $score = 0;
            foreach ($regle['mots'] as $mot) {
                if (str_contains($texteNorm, mb_strtolower($mot))) {
                    $score += $regle['poids'];
                }
            }
            $scores[$type] = $score;
        }

        /* Trouver le meilleur score */
        arsort($scores);
        $meilleurType = array_key_first($scores);
        $meilleurScore = $scores[$meilleurType];

        if ($meilleurScore === 0) {
            return ['type' => 'autre', 'confiance' => 30, 'source' => 'texte_ocr'];
        }

        /* Convertir le score en confiance (0-100) */
        $confiance = min(95, $meilleurScore * 15);

        return [
            'type' => $meilleurType,
            'confiance' => $confiance,
            'source' => 'texte_ocr',
        ];
    }

    /**
     * Analyser le nom de fichier pour classification
     */
    protected function analyserNomFichier(string $nom): array
    {
        $nomNorm = mb_strtolower($nom);

        $patterns = [
            'facture' => ['facture', 'invoice', 'fact_', 'fact-'],
            'proforma' => ['proforma', 'devis', 'cotation'],
            'ordre_mission' => ['ordre_mission', 'mission', 'om_', 'om-'],
            'recu_carburant' => ['carburant', 'fuel', 'gasoil', 'essence', 'station'],
            'rapport_journalier' => ['rapport', 'rapport_caisse', 'journal'],
            'bon_caisse' => ['bon_caisse', 'bc_', 'bc-'],
            'recu' => ['recu', 'receipt', 'quittance'],
        ];

        foreach ($patterns as $type => $mots) {
            foreach ($mots as $mot) {
                if (str_contains($nomNorm, $mot)) {
                    return [
                        'type' => $type,
                        'confiance' => 60,
                        'source' => 'nom_fichier',
                    ];
                }
            }
        }

        return ['type' => 'autre', 'confiance' => 10, 'source' => 'nom_fichier'];
    }

    /**
     * Mapper les types de documents utilisateur vers les classifications IA
     */
    protected function mapperTypeDocumentVersClassification(string $typeDocument): string
    {
        $map = [
            'facture' => 'facture',
            'recu' => 'recu',
            'devis' => 'proforma',
            'ordre_mission' => 'ordre_mission',
            'proforma' => 'proforma',
            'email' => 'autre',
            'recu_carburant' => 'recu_carburant',
            'bon_commande' => 'autre',
            'rapport_journalier' => 'rapport_journalier',
            'autre' => 'autre',
        ];

        return $map[$typeDocument] ?? 'autre';
    }
}
