<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Support\Facades\Log;

/**
 * Service NimbaSMS
 * 
 * Gère l'envoi de SMS et la récupération du solde via l'API Nimba SMS.
 * Documentation : https://developers.nimbasms.com/
 */
class NimbaSmsService
{
    protected Client $client;
    protected string $apiUrl;
    protected string $authToken;
    protected string $senderName;

    public function __construct()
    {
        $this->client = new Client();
        $this->apiUrl = config('services.nimba.api_url', 'https://api.nimbasms.com/v1');
        $this->authToken = config('services.nimba.auth_token');
        $this->senderName = config('services.nimba.sender_name', 'NEEMBA');
    }

    /**
     * Envoyer un SMS via Nimba
     * 
     * @param string $recipientPhoneNumber Numéro du destinataire (format international)
     * @param string $message Contenu du message
     * @return array Résultat de l'envoi
     */
    public function envoyerSms(string $recipientPhoneNumber, string $message): array
    {
        try {
            $url = "{$this->apiUrl}/messages";

            $data = [
                "to" => [$this->formatNumero($recipientPhoneNumber)],
                "sender_name" => $this->senderName,
                "message" => $message
            ];

            $response = $this->client->post($url, [
                'headers' => [
                    'Authorization' => "Basic {$this->authToken}",
                    'Content-Type' => 'application/json',
                ],
                'json' => $data
            ]);

            $responseData = json_decode($response->getBody()->getContents(), true);

            Log::info('SMS envoyé avec succès via Nimba', [
                'destinataire' => $recipientPhoneNumber,
                'status_code' => $response->getStatusCode(),
            ]);

            return [
                'success' => true,
                'status' => 'Succès SMS',
                'status_code' => $response->getStatusCode(),
                'data' => $responseData
            ];
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $responseBody = $response->getBody()->getContents();

            Log::error('Erreur ClientException lors de l\'envoi SMS', [
                'destinataire' => $recipientPhoneNumber,
                'status_code' => $response->getStatusCode(),
                'response' => $responseBody
            ]);

            return [
                'success' => false,
                'error' => 'Erreur lors de l\'envoi du SMS',
                'status_code' => $response->getStatusCode(),
                'message' => json_decode($responseBody, true)
            ];
        } catch (\Exception $e) {
            Log::error('Erreur générale lors de l\'envoi SMS', [
                'destinataire' => $recipientPhoneNumber,
                'message' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Erreur interne',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Récupérer le solde du compte Nimba SMS
     * 
     * @return array Informations sur le compte et le solde
     */
    public function obtenirSolde(): array
    {
        try {
            $url = "{$this->apiUrl}/accounts";

            $response = $this->client->get($url, [
                'headers' => [
                    'Authorization' => "Basic {$this->authToken}",
                    'Content-Type' => 'application/json',
                ]
            ]);

            $responseData = json_decode($response->getBody()->getContents(), true);

            return [
                'success' => true,
                'status' => 'Solde récupéré avec succès',
                'status_code' => $response->getStatusCode(),
                'data' => $responseData
            ];
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $responseBody = $response->getBody()->getContents();

            Log::error('Erreur ClientException lors de la récupération du solde', [
                'status_code' => $response->getStatusCode(),
                'response' => $responseBody
            ]);

            return [
                'success' => false,
                'error' => 'Erreur lors de la récupération du solde',
                'status_code' => $response->getStatusCode(),
                'message' => json_decode($responseBody, true)
            ];
        } catch (\Exception $e) {
            Log::error('Erreur générale lors de la récupération du solde', [
                'message' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Erreur interne',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Envoyer un code OTP par SMS
     * 
     * @param string $telephone Numéro du destinataire
     * @param string $code Code OTP à envoyer
     * @param string $beneficiaire Nom du bénéficiaire
     * @return array Résultat de l'envoi
     */
    public function envoyerCodeOtp(string $telephone, string $code, string $beneficiaire): array
    {
        $message = "NEEMBA - Code de validation pour le paiement de {$beneficiaire} : {$code}. Ce code expire dans 5 minutes.";

        return $this->envoyerSms($telephone, $message);
    }

    /**
     * Envoyer une relance SLA par SMS
     * 
     * @param string $telephone Numéro du validateur
     * @param string $numeroBon Numéro du bon de caisse
     * @param string $montant Montant formaté
     * @param string $demandeur Nom du demandeur
     * @param int $heuresRetard Heures de retard
     * @return array Résultat de l'envoi
     */
    public function envoyerRelanceSla(string $telephone, string $numeroBon, string $montant, string $demandeur, int $heuresRetard): array
    {
        $message = "NEEMBA - RELANCE : Le bon {$numeroBon} ({$montant}) de {$demandeur} attend votre validation depuis {$heuresRetard}h. Merci d'agir rapidement.";

        return $this->envoyerSms($telephone, $message);
    }

    /**
     * Envoyer une notification d'escalade par SMS
     * 
     * @param string $telephone Numéro du validateur N+1
     * @param string $numeroBon Numéro du bon de caisse
     * @param string $montant Montant formaté
     * @param string $roleOrigine Rôle qui n'a pas répondu
     * @return array Résultat de l'envoi
     */
    public function envoyerEscaladeSla(string $telephone, string $numeroBon, string $montant, string $roleOrigine): array
    {
        $message = "NEEMBA - ESCALADE : Le bon {$numeroBon} ({$montant}) n'a pas été traité par {$roleOrigine}. Il requiert votre attention immédiate.";

        return $this->envoyerSms($telephone, $message);
    }

    /**
     * Envoyer une alerte SMS de seuil de caisse bas
     * 
     * @param string $telephone Numéro du caissier
     * @param string $site Nom du site concerné
     * @param string $soldeActuel Solde actuel formaté
     * @param string $seuilMinimum Seuil minimum formaté
     * @return array Résultat de l'envoi
     */
    public function envoyerAlerteSeuil(string $telephone, string $site, string $soldeActuel, string $seuilMinimum): array
    {
        $message = "NEEMBA - ALERTE CAISSE : Le solde du site {$site} ({$soldeActuel}) est passé sous le seuil minimum ({$seuilMinimum}). Un réapprovisionnement est nécessaire.";

        return $this->envoyerSms($telephone, $message);
    }

    function formatNumero($numero)
    {
        $numero = preg_replace('/\D/', '', $numero); // enlève tout sauf chiffres

        if (str_starts_with($numero, '224')) {
            return '+' . $numero;
        }

        if (str_starts_with($numero, '0')) {
            $numero = substr($numero, 1);
        }

        return '+224' . $numero;
    }
}
