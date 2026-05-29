<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Service d'Analyse de Document - Extraction intelligente d'informations
 * 
 * Analyse le texte extrait par l'OCR pour identifier les informations
 * pertinentes d'une pièce justificative :
 * - Fournisseur
 * - Date du document
 * - Montant total
 * - Devise
 * - Numéro de facture / référence
 * - Description de la dépense
 * 
 * Utilise d'abord une IA (OpenAI) si configurée, sinon un fallback
 * par expressions régulières.
 * 
 * Principe : OCR = assistance, jamais obligation.
 */
class DocumentAnalyseService
{
    /**
     * Analyser le texte extrait et retourner les données structurées
     * 
     * @param string $texte Texte brut extrait du document
     * @return array Données structurées (champs trouvés uniquement)
     */
    public function analyser(string $texte): array
    {
        if (empty(trim($texte))) {
            return [];
        }

        /* Tentative d'analyse via IA si la clé API est configurée */
        $apiKey = config('services.openai.api_key');
        if ($apiKey) {
            $resultatIA = $this->analyserAvecIA($texte, $apiKey);
            if (!empty($resultatIA)) {
                return $resultatIA;
            }
        }

        /* Fallback : analyse par expressions régulières */
        return $this->analyserParRegex($texte);
    }

    /**
     * Analyse du texte via l'API OpenAI
     */
    protected function analyserAvecIA(string $texte, string $apiKey): array
    {
        try {
            $prompt = <<<PROMPT
Tu es un assistant spécialisé dans l'analyse de documents financiers (factures, reçus, devis).
Analyse le texte suivant extrait d'un document et retourne un JSON avec les informations trouvées.

Retourne UNIQUEMENT les champs que tu arrives à identifier avec confiance :
- "fournisseur" : nom du fournisseur ou prestataire
- "date_document" : date du document au format YYYY-MM-DD
- "montant" : montant total en nombre (sans devise)
- "devise" : devise (par défaut "GNF" si contexte guinéen)
- "reference_document" : numéro de facture ou référence
- "description" : brève description de la dépense

Si c'est un reçu/ticket de carburant ou station-service, extrais aussi :
- "station" : nom de la station-service
- "litrage" : volume en litres (nombre uniquement)
- "prix_unitaire" : prix par litre (nombre uniquement)
- "immatriculation" : plaque d'immatriculation du véhicule si présente

Si tu ne trouves pas un champ, NE l'inclus PAS dans le JSON.
Retourne UNIQUEMENT le JSON, sans commentaires ni explications.

Texte du document :
---
{$texte}
---
PROMPT;

            $response = Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'Content-Type' => 'application/json',
            ])->timeout(30)->post('https://api.openai.com/v1/chat/completions', [
                'model' => config('services.openai.model', 'gpt-4o-mini'),
                'messages' => [
                    ['role' => 'system', 'content' => 'Tu es un assistant d\'analyse de documents financiers. Réponds uniquement en JSON valide.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0.1,
                'max_tokens' => 500,
            ]);

            if ($response->successful()) {
                $contenu = $response->json('choices.0.message.content', '');

                /* Nettoyer le contenu (enlever les backticks markdown si présents) */
                $contenu = preg_replace('/^```json?\s*/i', '', $contenu);
                $contenu = preg_replace('/\s*```$/', '', $contenu);

                $data = json_decode(trim($contenu), true);

                if (is_array($data)) {
                    return $this->normaliserResultat($data);
                }
            }

            Log::warning('DocumentAnalyseService : réponse IA invalide', [
                'status' => $response->status(),
            ]);

        } catch (\Throwable $e) {
            Log::warning("DocumentAnalyseService : erreur IA — {$e->getMessage()}");
        }

        return [];
    }

    /**
     * Analyse par expressions régulières (fallback sans IA)
     */
    protected function analyserParRegex(string $texte): array
    {
        $resultat = [];

        /* Extraction du montant */
        $montant = $this->extraireMontant($texte);
        if ($montant !== null) {
            $resultat['montant'] = $montant;
        }

        /* Extraction de la devise */
        if (preg_match('/\b(GNF|USD|EUR|XOF|FCFA)\b/i', $texte, $match)) {
            $resultat['devise'] = strtoupper($match[1]);
        } elseif (!empty($resultat['montant'])) {
            $resultat['devise'] = 'GNF';
        }

        /* Extraction de la date */
        $date = $this->extraireDate($texte);
        if ($date) {
            $resultat['date_document'] = $date;
        }

        /* Extraction du numéro de facture / référence */
        $reference = $this->extraireReference($texte);
        if ($reference) {
            $resultat['reference_document'] = $reference;
        }

        /* Extraction du fournisseur (heuristique basique) */
        $fournisseur = $this->extraireFournisseur($texte);
        if ($fournisseur) {
            $resultat['fournisseur'] = $fournisseur;
        }

        /* Extraction des informations spécifiques carburant */
        $infosCarburant = $this->extraireInfosCarburant($texte);
        $resultat = array_merge($resultat, $infosCarburant);

        return $resultat;
    }

    /**
     * Extraire un montant depuis le texte
     */
    protected function extraireMontant(string $texte): ?float
    {
        /* Patterns de montant courants */
        $patterns = [
            '/(?:total|montant|somme|net\s*[àa]\s*payer|ttc)\s*[:=]?\s*([\d\s.,]+)/i',
            '/([\d\s.,]+)\s*(?:GNF|FG|USD|EUR|XOF|FCFA)/i',
            '/(?:prix|cout|coût)\s*[:=]?\s*([\d\s.,]+)/i',
        ];

        $montantMax = 0;

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $texte, $matches)) {
                foreach ($matches[1] as $match) {
                    $valeur = str_replace([' ', '.'], '', $match);
                    $valeur = str_replace(',', '.', $valeur);
                    $valeur = (float) $valeur;
                    if ($valeur > $montantMax && $valeur <= 20000000) {
                        $montantMax = $valeur;
                    }
                }
            }
        }

        return $montantMax > 0 ? $montantMax : null;
    }

    /**
     * Extraire une date depuis le texte
     */
    protected function extraireDate(string $texte): ?string
    {
        /* Format DD/MM/YYYY ou DD-MM-YYYY */
        if (preg_match('/(\d{2})[\/\-](\d{2})[\/\-](\d{4})/', $texte, $match)) {
            $jour = $match[1];
            $mois = $match[2];
            $annee = $match[3];
            if (checkdate((int) $mois, (int) $jour, (int) $annee)) {
                return "{$annee}-{$mois}-{$jour}";
            }
        }

        /* Format YYYY-MM-DD */
        if (preg_match('/(\d{4})-(\d{2})-(\d{2})/', $texte, $match)) {
            if (checkdate((int) $match[2], (int) $match[3], (int) $match[1])) {
                return $match[0];
            }
        }

        /* Format textuel français : 10 février 2026 */
        $moisFr = [
            'janvier' => '01', 'février' => '02', 'fevrier' => '02', 'mars' => '03',
            'avril' => '04', 'mai' => '05', 'juin' => '06', 'juillet' => '07',
            'août' => '08', 'aout' => '08', 'septembre' => '09', 'octobre' => '10',
            'novembre' => '11', 'décembre' => '12', 'decembre' => '12',
        ];
        $moisPattern = implode('|', array_keys($moisFr));
        if (preg_match("/(\d{1,2})\s+({$moisPattern})\s+(\d{4})/i", $texte, $match)) {
            $jour = str_pad($match[1], 2, '0', STR_PAD_LEFT);
            $mois = $moisFr[strtolower($match[2])] ?? null;
            $annee = $match[3];
            if ($mois) {
                return "{$annee}-{$mois}-{$jour}";
            }
        }

        return null;
    }

    /**
     * Extraire un numéro de référence / facture
     */
    protected function extraireReference(string $texte): ?string
    {
        $patterns = [
            '/(?:facture|fact|fac|ref|référence|reference|n°|numero|numéro)\s*[:.\-#]?\s*([A-Z0-9\-\/]{3,20})/i',
            '/\b(FAC[\-\/]?\d{4}[\-\/]?\d{2,6})\b/i',
            '/\b(INV[\-\/]?\d{4}[\-\/]?\d{2,6})\b/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $texte, $match)) {
                return trim($match[1]);
            }
        }

        return null;
    }

    /**
     * Extraire le nom du fournisseur (heuristique)
     * Cherche en début de document ou après des mots-clés
     */
    protected function extraireFournisseur(string $texte): ?string
    {
        /* Chercher après des mots-clés */
        $patterns = [
            '/(?:fournisseur|vendeur|émetteur|emetteur|de\s*la\s*part\s*de|société|societe)\s*[:.]?\s*(.{3,60})/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $texte, $match)) {
                $nom = trim($match[1]);
                /* Nettoyer : prendre la première ligne */
                $nom = strtok($nom, "\n");
                $nom = preg_replace('/[^a-zA-ZÀ-ÿ\s\-&.]/', '', $nom);
                $nom = trim($nom);
                if (strlen($nom) >= 3 && strlen($nom) <= 100) {
                    return $nom;
                }
            }
        }

        /* Heuristique : prendre la première ligne significative du document */
        $lignes = array_filter(explode("\n", $texte), fn($l) => strlen(trim($l)) > 3);
        $premiereLigne = trim(reset($lignes) ?: '');
        if (strlen($premiereLigne) >= 3 && strlen($premiereLigne) <= 60) {
            /* Vérifier que ce n'est pas un numéro ou une date */
            if (!preg_match('/^\d/', $premiereLigne)) {
                return $premiereLigne;
            }
        }

        return null;
    }

    /**
     * Extraire les informations spécifiques carburant depuis le texte
     */
    protected function extraireInfosCarburant(string $texte): array
    {
        $resultat = [];

        /* Station-service (première ligne ou mot-clé) */
        $stationPatterns = [
            '/(?:station|station[\-\s]service)\s*[:.]?\s*(.{3,60})/i',
            '/(?:TOTAL|SHELL|STAR\s*OIL|ORYX|VIVO\s*ENERGY|ENGEN)[\s\w]*/i',
        ];
        foreach ($stationPatterns as $pattern) {
            if (preg_match($pattern, $texte, $match)) {
                $resultat['station'] = trim($match[0]);
                break;
            }
        }

        /* Litrage */
        if (preg_match('/(?:volume|quantit[eé]|litres?|qte|qt[eé])\s*[:=]?\s*([\d\s.,]+)\s*(?:l(?:itres?)?)?/i', $texte, $match)) {
            $val = str_replace([' ', ','], ['', '.'], $match[1]);
            if ((float) $val > 0) {
                $resultat['litrage'] = (float) $val;
            }
        }

        /* Prix unitaire */
        if (preg_match('/(?:prix\s*(?:unitaire|\/l|par\s*litre)|pu|p\.u)\s*[:=]?\s*([\d\s.,]+)/i', $texte, $match)) {
            $val = str_replace([' ', ','], ['', '.'], $match[1]);
            if ((float) $val > 0) {
                $resultat['prix_unitaire'] = (float) $val;
            }
        }

        /* Immatriculation */
        if (preg_match('/(?:immatriculation|plaque|v[eé]hicule|mat)\s*[:=.]?\s*([A-Z0-9\-\s]{4,15})/i', $texte, $match)) {
            $resultat['immatriculation'] = trim($match[1]);
        }

        return $resultat;
    }

    /**
     * Normaliser le résultat pour ne garder que les champs valides
     */
    protected function normaliserResultat(array $data): array
    {
        $champsAutorises = [
            'fournisseur', 'date_document', 'montant', 'devise', 'reference_document', 'description',
            'station', 'litrage', 'prix_unitaire', 'immatriculation',
        ];
        $resultat = [];

        foreach ($champsAutorises as $champ) {
            if (isset($data[$champ]) && $data[$champ] !== null && $data[$champ] !== '') {
                $resultat[$champ] = $data[$champ];
            }
        }

        /* S'assurer que les champs numériques sont des nombres */
        foreach (['montant', 'litrage', 'prix_unitaire'] as $champ) {
            if (isset($resultat[$champ])) {
                $resultat[$champ] = (float) $resultat[$champ];
            }
        }

        return $resultat;
    }
}
