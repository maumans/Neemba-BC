<?php

use App\Http\Controllers\ArchivageController;
use App\Http\Controllers\AssistantBonController;
use App\Http\Controllers\BonCaisseController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DelegationController;
use App\Http\Controllers\MouvementCaisseController;
use App\Http\Controllers\OcrAnalyseController;
use App\Http\Controllers\OcrController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RapportCaisseController;
use App\Http\Controllers\TauxChangeController;
use App\Http\Controllers\UtilisateurController;
use App\Http\Controllers\ParametrageController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OdmApiController;
use App\Http\Controllers\OrdreMissionController;
use App\Http\Controllers\ValidationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Routes Web - Application NEEMBA Gestion de Caisse
|--------------------------------------------------------------------------
|
| Page d'accueil : redirige vers le dashboard si connecté, sinon vers login
|
*/

Route::get('/', function () {
    return redirect()->route('login');
});

/*
|--------------------------------------------------------------------------
| Routes authentifiées
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'verified'])->group(function () {

    /* --- Dashboard --- */
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    /* --- Profil utilisateur (Breeze) --- */
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    /* --- Bons de Caisse --- */

    /*
     * ⚠ ORDRE IMPORTANT : les routes statiques (sans paramètre) doivent être déclarées
     * AVANT Route::resource() afin d'éviter que {bonCaisse} capte des segments statiques.
     */

    /* Tableau de bord BP en retard de régularisation (DAF, DP, Admin) */
    Route::get('/bons-caisse/bp-en-retard', [BonCaisseController::class, 'bpEnRetard'])
        ->middleware('role:daf,directeur_pays,administrateur')
        ->name('bons-caisse.bp-en-retard');

    /* Pages : liste, assistant (création, reprise d'un brouillon, correction après rejet), fiche.
     * L'enregistrement passe par l'API de l'assistant ; un bon ne se supprime pas, il s'annule (RG-BC-31). */
    Route::resource('bons-caisse', BonCaisseController::class)
        ->parameters(['bons-caisse' => 'bonCaisse'])
        ->only(['index', 'create', 'show', 'edit']);

    /* --- API JSON de l'assistant « Nouveau bon de caisse » (SFD M03, §5.7) --- */
    Route::prefix('api/v1')->name('api.')->group(function () {
        Route::get('/referentiels/beneficiaires', [AssistantBonController::class, 'beneficiaires'])->name('referentiels.beneficiaires');
        Route::get('/referentiels/caisse-payeuse', [AssistantBonController::class, 'caissePayeuse'])->name('referentiels.caisse-payeuse');
        Route::post('/bons', [AssistantBonController::class, 'creer'])->name('bons.creer');
        Route::patch('/bons/{bonCaisse}', [AssistantBonController::class, 'enregistrer'])->name('bons.enregistrer');
        Route::get('/bons/{bonCaisse}/controles', [AssistantBonController::class, 'controles'])->name('bons.controles');
        Route::post('/bons/{bonCaisse}/soumettre', [AssistantBonController::class, 'soumettre'])->name('bons.soumettre');
        Route::post('/bons/{bonCaisse}/annuler', [AssistantBonController::class, 'annuler'])->name('bons.annuler');
        Route::post('/bons/{bonCaisse}/pieces', [AssistantBonController::class, 'ajouterPiece'])->name('bons.pieces.ajouter');
        Route::patch('/bons/{bonCaisse}/pieces/{piece}', [AssistantBonController::class, 'typerPiece'])->name('bons.pieces.typer');
        Route::delete('/bons/{bonCaisse}/pieces/{piece}', [AssistantBonController::class, 'supprimerPiece'])->name('bons.pieces.supprimer');
        Route::post('/bons/{bonCaisse}/pieces/{piece}/remplacer', [AssistantBonController::class, 'remplacerPiece'])->name('bons.pieces.remplacer');
        Route::patch('/bons/{bonCaisse}/pieces/{piece}/doublon', [AssistantBonController::class, 'confirmerDoublon'])->name('bons.pieces.doublon');
        Route::get('/bons/{bonCaisse}/pieces/{piece}/lecture', [AssistantBonController::class, 'lecture'])->name('bons.pieces.lecture');
        Route::post('/bons/{bonCaisse}/pieces/{piece}/lecture', [AssistantBonController::class, 'validerLecture'])->name('bons.pieces.lecture.valider');

        /* M12 « Ordres de mission » : formulaire (brouillon, calcul renvoyé par le serveur), soumission */
        Route::get('/odm/employes', [OdmApiController::class, 'employes'])->name('odm.employes');
        Route::post('/odm', [OdmApiController::class, 'creer'])->name('odm.creer');
        Route::put('/odm/{odm}', [OdmApiController::class, 'enregistrer'])->name('odm.enregistrer');
        Route::post('/odm/{odm}/soumettre', [OdmApiController::class, 'soumettre'])->name('odm.soumettre');
        Route::post('/odm/{odm}/derogation', [OdmApiController::class, 'demanderDerogation'])->name('odm.derogation');
        Route::post('/odm/{odm}/annuler', [OdmApiController::class, 'annuler'])->name('odm.annuler');
    });

    /* --- Ordres de mission (M12) : liste, formulaire, fiche, dérogation du DAF --- */
    Route::get('/ordres-mission', [OrdreMissionController::class, 'index'])->name('odm.index');
    Route::get('/ordres-mission/create', [OrdreMissionController::class, 'create'])->name('odm.create');
    Route::get('/ordres-mission/{odm}', [OrdreMissionController::class, 'show'])->name('odm.show');
    Route::get('/ordres-mission/{odm}/edit', [OrdreMissionController::class, 'edit'])->name('odm.edit');
    Route::post('/ordres-mission/{odm}/derogation', [OrdreMissionController::class, 'deciderDerogation'])->name('odm.derogation');
    Route::post('/ordres-mission/{odm}/viser', [OrdreMissionController::class, 'viser'])->name('odm.viser');
    Route::post('/ordres-mission/{odm}/rejeter', [OrdreMissionController::class, 'rejeter'])->name('odm.rejeter');
    Route::post('/ordres-mission/{odm}/generer-bons', [OrdreMissionController::class, 'genererBons'])->name('odm.generer-bons');
    Route::post('/ordres-mission/{odm}/prolonger', [OrdreMissionController::class, 'prolonger'])->name('odm.prolonger');
    Route::post('/ordres-mission/{odm}/cloturer', [OrdreMissionController::class, 'cloturer'])->name('odm.cloturer');
    Route::post('/ordres-mission/{odm}/annuler-daf', [OrdreMissionController::class, 'annulerDaf'])->name('odm.annuler-daf');
    Route::post('/ordres-mission/{odm}/participants/{participant}/regulariser', [OrdreMissionController::class, 'regulariser'])->name('odm.regulariser');

    /* Actions spécifiques sur les bons de caisse (toutes paramétrées → pas de conflit) */
    Route::post('/bons-caisse/{bonCaisse}/otp/generer', [BonCaisseController::class, 'genererOtp'])
        ->middleware('role:caissier')
        ->name('bons-caisse.otp.generer');
    Route::post('/bons-caisse/{bonCaisse}/otp/verifier', [BonCaisseController::class, 'verifierOtp'])
        ->middleware('role:caissier')
        ->name('bons-caisse.otp.verifier');
    Route::post('/bons-caisse/{bonCaisse}/payer', [BonCaisseController::class, 'payer'])
        ->middleware('role:caissier')
        ->name('bons-caisse.payer');
    Route::post('/bons-caisse/{bonCaisse}/regulariser', [BonCaisseController::class, 'regulariser'])
        ->name('bons-caisse.regulariser');
    Route::post('/bons-caisse/{bonCaisse}/pre-regulariser', [BonCaisseController::class, 'preRegulariser'])
        ->name('bons-caisse.pre-regulariser');
    Route::post('/bons-caisse/{bonCaisse}/archiver', [BonCaisseController::class, 'archiver'])
        ->middleware('role:daf,directeur_pays,caissier')
        ->name('bons-caisse.archiver');
    Route::get('/bons-caisse/{bonCaisse}/export-pdf', [BonCaisseController::class, 'exportPdf'])
        ->name('bons-caisse.export-pdf');

    /* --- Validations (réservées aux validateurs) --- */
    Route::middleware('role:responsable_service,controle_gestion,daf,directeur_pays')->group(function () {
        Route::get('/validations', [ValidationController::class, 'index'])->name('validations.index');
        Route::get('/validations/{bonCaisse}', [ValidationController::class, 'show'])->name('validations.show');
        Route::post('/validations/{bonCaisse}/approuver', [ValidationController::class, 'approuver'])
            ->name('validations.approuver');
        Route::post('/validations/{bonCaisse}/rejeter', [ValidationController::class, 'rejeter'])
            ->name('validations.rejeter');
        Route::post('/validations/{bonCaisse}/demander-complement', [ValidationController::class, 'demanderComplement'])
            ->name('validations.demander-complement');
    });

    /* --- Rapports de Caisse (caissier, DAF, DP, administrateur) --- */
    Route::middleware('role:caissier,daf,directeur_pays,administrateur')->group(function () {
        Route::get('/rapports', [RapportCaisseController::class, 'tableauTempsReel'])->name('rapports.index');
        Route::get('/rapports/create', [RapportCaisseController::class, 'create'])->name('rapports.create');
        Route::post('/rapports', [RapportCaisseController::class, 'store'])->name('rapports.store');
        Route::get('/rapports/{rapport}', [RapportCaisseController::class, 'show'])->name('rapports.show');
        Route::post('/rapports/envoyer-email', [RapportCaisseController::class, 'envoyerRapportEmail'])
            ->name('rapports.envoyer-email');
        Route::post('/rapports/{rapport}/viser-daf', [RapportCaisseController::class, 'viserDaf'])
            ->name('rapports.viser-daf');
        Route::get('/rapports/{rapport}/export-excel', [RapportCaisseController::class, 'exportExcel'])
            ->name('rapports.export-excel');
        Route::get('/rapports/{rapport}/export-pdf', [RapportCaisseController::class, 'exportPdf'])
            ->name('rapports.export-pdf');
        Route::get('/rapports-periode/export-excel', [RapportCaisseController::class, 'exportPeriodeExcel'])
            ->name('rapports.periode.export-excel');
        Route::get('/rapports-periode/export-pdf', [RapportCaisseController::class, 'exportPeriodePdf'])
            ->name('rapports.periode.export-pdf');
        Route::get('/rapports-temps-reel/export-excel', [RapportCaisseController::class, 'exportTempsReelExcel'])
            ->name('rapports.temps-reel.export-excel');
        Route::get('/rapports-temps-reel/export-pdf', [RapportCaisseController::class, 'exportTempsReelPdf'])
            ->name('rapports.temps-reel.export-pdf');
    });

    /* --- Archivage Centralisé (DAF, Contrôle de Gestion, Administrateur) --- */
    Route::middleware('role:daf,controle_gestion,administrateur')->group(function () {
        Route::get('/archivage', [ArchivageController::class, 'index'])->name('archivage.index');
        Route::get('/archivage/{piece}', [ArchivageController::class, 'show'])->name('archivage.show');
        Route::post('/archivage/{piece}/archiver', [ArchivageController::class, 'archiver'])->name('archivage.archiver');
        Route::post('/archivage/archiver-bon', [ArchivageController::class, 'archiverBon'])->name('archivage.archiver-bon');
        Route::post('/archivage/{piece}/reclassifier', [ArchivageController::class, 'reclassifier'])->name('archivage.reclassifier');
        Route::post('/archivage/{piece}/relancer-classification', [ArchivageController::class, 'relancerClassification'])->name('archivage.relancer-classification');
    });

    /* --- Délégations de Pouvoirs (tous les utilisateurs peuvent en recevoir) --- */
    Route::middleware('role:demandeur,caissier,responsable_service,controle_gestion,daf,directeur_pays,administrateur')->group(function () {
        Route::get('/delegations', [DelegationController::class, 'index'])->name('delegations.index');
        Route::get('/delegations/create', [DelegationController::class, 'create'])->name('delegations.create');
        Route::post('/delegations', [DelegationController::class, 'store'])->name('delegations.store');
        Route::post('/delegations/{delegation}/accepter', [DelegationController::class, 'accepter'])->name('delegations.accepter');
        Route::post('/delegations/{delegation}/refuser', [DelegationController::class, 'refuser'])->name('delegations.refuser');
        Route::post('/delegations/{delegation}/terminer', [DelegationController::class, 'terminer'])->name('delegations.terminer');
    });

    /* --- Mouvements de Caisse (caissier, DAF, DP) --- */
    Route::middleware('role:caissier,daf,directeur_pays,administrateur')->group(function () {
        Route::get('/mouvements-caisse', [MouvementCaisseController::class, 'index'])->name('mouvements-caisse.index');
        Route::get('/mouvements-caisse/create', [MouvementCaisseController::class, 'create'])->name('mouvements-caisse.create');
        Route::post('/mouvements-caisse', [MouvementCaisseController::class, 'store'])->name('mouvements-caisse.store');
        Route::post('/mouvements-caisse/{mouvement}/valider', [MouvementCaisseController::class, 'valider'])
            ->middleware('role:daf,directeur_pays')
            ->name('mouvements-caisse.valider');
        Route::post('/mouvements-caisse/{mouvement}/rejeter', [MouvementCaisseController::class, 'rejeter'])
            ->middleware('role:daf,directeur_pays')
            ->name('mouvements-caisse.rejeter');
    });

    /* --- Trésorerie : taux de change FCFA → GNF du jour (M12-0, spec v2.2 §6.6) --- */
    Route::middleware('role:tresorerie,daf,daf_adjoint,chef_comptable,directeur_pays,caissier,administrateur')->group(function () {
        Route::get('/tresorerie/taux-du-jour', [TauxChangeController::class, 'index'])->name('tresorerie.taux.index');
        Route::post('/tresorerie/taux-du-jour', [TauxChangeController::class, 'store'])
            ->middleware('role:tresorerie,daf,daf_adjoint')
            ->name('tresorerie.taux.store');
    });

    /* --- Gestion des Utilisateurs (Administrateur uniquement) --- */
    Route::middleware('role:administrateur')->group(function () {

        /* Double validation des modifications critiques */
        Route::get('/admin/modifications-en-attente', [ParametrageController::class, 'modificationsEnAttente'])
            ->name('admin.modifications-en-attente.index');
        Route::post('/admin/modifications-en-attente/{modification}/approuver', [ParametrageController::class, 'approuverModification'])
            ->name('admin.modifications-en-attente.approuver');
        Route::post('/admin/modifications-en-attente/{modification}/refuser', [ParametrageController::class, 'refuserModification'])
            ->name('admin.modifications-en-attente.refuser');

        Route::resource('utilisateurs', UtilisateurController::class)
            ->except(['show', 'destroy']);
        Route::post('/utilisateurs/{utilisateur}/toggle-actif', [UtilisateurController::class, 'toggleActif'])
            ->name('utilisateurs.toggle-actif');
    });

    /* --- Paramétrage (tables de référence — Administrateur uniquement) --- */
    Route::middleware('role:administrateur')->group(function () {
        Route::get('/parametrage', [ParametrageController::class, 'index'])->name('parametrage.index');

        /* Sites */
        Route::post('/parametrage/sites', [ParametrageController::class, 'storeSite'])->name('parametrage.sites.store');
        Route::put('/parametrage/sites/{site}', [ParametrageController::class, 'updateSite'])->name('parametrage.sites.update');
        Route::post('/parametrage/sites/{site}/toggle', [ParametrageController::class, 'toggleSite'])->name('parametrage.sites.toggle');

        /* Caisses (lot 2) */
        Route::post('/parametrage/caisses', [ParametrageController::class, 'storeCaisse'])->name('parametrage.caisses.store');
        Route::put('/parametrage/caisses/{caisse}', [ParametrageController::class, 'updateCaisse'])->name('parametrage.caisses.update');
        Route::post('/parametrage/caisses/{caisse}/toggle', [ParametrageController::class, 'toggleCaisse'])->name('parametrage.caisses.toggle');

        /* Services */
        Route::post('/parametrage/services', [ParametrageController::class, 'storeService'])->name('parametrage.services.store');
        Route::put('/parametrage/services/{service}', [ParametrageController::class, 'updateService'])->name('parametrage.services.update');
        Route::post('/parametrage/services/{service}/toggle', [ParametrageController::class, 'toggleService'])->name('parametrage.services.toggle');

        /* Codes analytiques */
        Route::post('/parametrage/codes-analytiques', [ParametrageController::class, 'storeCodeAnalytique'])->name('parametrage.codes-analytiques.store');
        Route::put('/parametrage/codes-analytiques/{codeAnalytique}', [ParametrageController::class, 'updateCodeAnalytique'])->name('parametrage.codes-analytiques.update');
        Route::post('/parametrage/codes-analytiques/{codeAnalytique}/toggle', [ParametrageController::class, 'toggleCodeAnalytique'])->name('parametrage.codes-analytiques.toggle');

        /* Types de document */
        Route::post('/parametrage/types-document', [ParametrageController::class, 'storeTypeDocument'])->name('parametrage.types-document.store');
        Route::put('/parametrage/types-document/{typeDocument}', [ParametrageController::class, 'updateTypeDocument'])->name('parametrage.types-document.update');
        Route::post('/parametrage/types-document/{typeDocument}/toggle', [ParametrageController::class, 'toggleTypeDocument'])->name('parametrage.types-document.toggle');

        /* Motifs d'urgence */
        Route::post('/parametrage/motifs-urgence', [ParametrageController::class, 'storeMotifUrgence'])->name('parametrage.motifs-urgence.store');
        Route::put('/parametrage/motifs-urgence/{motifUrgence}', [ParametrageController::class, 'updateMotifUrgence'])->name('parametrage.motifs-urgence.update');
        Route::post('/parametrage/motifs-urgence/{motifUrgence}/toggle', [ParametrageController::class, 'toggleMotifUrgence'])->name('parametrage.motifs-urgence.toggle');

        /* Paramètres système */
        Route::put('/parametrage/parametres/{parametre}', [ParametrageController::class, 'updateParametre'])->name('parametrage.parametres.update');
    });

    /* API JSON — listes actives pour les selects searchable */
    Route::get('/api/listes', [ParametrageController::class, 'apiListes'])->name('api.listes');

    /* API JSON — Statut OCR des pièces jointes d'un bon */
    Route::get('/api/bons-caisse/{bonCaisse}/ocr', [OcrController::class, 'statut'])->name('api.ocr.statut');

    /* API JSON — Analyse OCR instantanée d'un fichier (pré-remplissage formulaire) */
    Route::post('/api/ocr/analyser', [OcrAnalyseController::class, 'analyser'])->name('api.ocr.analyser');

    /* --- Notifications (API JSON) --- */
    Route::prefix('api/notifications')->name('notifications.')->group(function () {
        Route::get('/', [NotificationController::class, 'index'])->name('index');
        Route::get('/non-lues', [NotificationController::class, 'compterNonLues'])->name('non-lues');
        Route::post('/{notification}/lue', [NotificationController::class, 'marquerLue'])->name('lue');
        Route::post('/tout-lire', [NotificationController::class, 'marquerToutesLues'])->name('tout-lire');
    });
});

require __DIR__.'/auth.php';
