<?php

declare(strict_types=1);

use App\Controller\AdminController;
use App\Controller\AuthController;
use App\Controller\HealthController;
use App\Controller\HomeController;
use App\Controller\MemberController;
use App\Middleware\RequireRole;
use App\Service\Auth\AuthService;
use Slim\App;

return function (App $app): void {
    $responseFactory = $app->getResponseFactory();
    $auth = $app->getContainer()->get(AuthService::class);
    $memberOnly = new RequireRole(AuthService::ROLE_MEMBER, $responseFactory, $auth);
    $adminOnly = new RequireRole(AuthService::ROLE_ADMIN, $responseFactory, $auth);

    $app->get('/', [HomeController::class, 'index']);
    $app->get('/sante', [HealthController::class, 'check']);
    $app->post('/signalement', [\App\Controller\BugReportController::class, 'submit']);

    $app->get('/connexion', [AuthController::class, 'showLogin']);
    $app->post('/connexion', [AuthController::class, 'submitLogin']);
    $app->get('/connexion/verifier', [AuthController::class, 'showVerify']);
    $app->post('/connexion/verifier', [AuthController::class, 'verify']);
    $app->post('/connexion/code', [AuthController::class, 'verifyCode']);
    $app->post('/connexion/profil', [AuthController::class, 'chooseProfile']);
    $app->get('/deconnexion', [AuthController::class, 'logout']);
    $app->post('/voir-comme/quitter', [AuthController::class, 'stopImpersonating'])->add($memberOnly);

    $app->get('/inscription', [\App\Controller\ProspectController::class, 'showStart']);
    $app->post('/inscription', [\App\Controller\ProspectController::class, 'submitStart']);
    $app->get('/inscription/{token:[a-f0-9]{64}}/informations', [\App\Controller\ProspectController::class, 'showInformations']);
    $app->post('/inscription/{token:[a-f0-9]{64}}/informations', [\App\Controller\ProspectController::class, 'submitInformations']);
    $app->get('/inscription/{token:[a-f0-9]{64}}/formule-saison', [\App\Controller\ProspectController::class, 'showSummerPackChoice']);
    $app->post('/inscription/{token:[a-f0-9]{64}}/formule-saison', [\App\Controller\ProspectController::class, 'submitSummerPackChoice']);
    $app->get('/inscription/{token:[a-f0-9]{64}}/representant-legal', [\App\Controller\ProspectController::class, 'showGuardian']);
    $app->post('/inscription/{token:[a-f0-9]{64}}/representant-legal', [\App\Controller\ProspectController::class, 'submitGuardian']);
    $app->get('/inscription/{token:[a-f0-9]{64}}/formule', [\App\Controller\ProspectController::class, 'showFormule']);
    $app->post('/inscription/{token:[a-f0-9]{64}}/formule', [\App\Controller\ProspectController::class, 'submitFormule']);
    $app->get('/inscription/{token:[a-f0-9]{64}}/conjoint', [\App\Controller\ProspectController::class, 'showConjoint']);
    $app->post('/inscription/{token:[a-f0-9]{64}}/conjoint', [\App\Controller\ProspectController::class, 'submitConjoint']);
    $app->get('/inscription/{token:[a-f0-9]{64}}/documents', [\App\Controller\ProspectController::class, 'showDocuments']);
    $app->post('/inscription/{token:[a-f0-9]{64}}/documents', [\App\Controller\ProspectController::class, 'submitDocuments']);
    $app->get('/inscription/{token:[a-f0-9]{64}}/sante', [\App\Controller\ProspectController::class, 'showSante']);
    $app->post('/inscription/{token:[a-f0-9]{64}}/sante', [\App\Controller\ProspectController::class, 'submitSante']);
    $app->get('/inscription/{token:[a-f0-9]{64}}/licence', [\App\Controller\ProspectController::class, 'showLicence']);
    $app->post('/inscription/{token:[a-f0-9]{64}}/licence', [\App\Controller\ProspectController::class, 'submitLicence']);
    $app->get('/inscription/{token:[a-f0-9]{64}}/recapitulatif', [\App\Controller\ProspectController::class, 'showRecap']);
    $app->post('/inscription/{token:[a-f0-9]{64}}/recapitulatif', [\App\Controller\ProspectController::class, 'submitRecap']);
    $app->get('/inscription/{token:[a-f0-9]{64}}/confirmation', [\App\Controller\ProspectController::class, 'showConfirmation']);

    $app->get('/paiement/{token:[a-f0-9]{64}}', [\App\Controller\PaymentController::class, 'showCart']);
    $app->post('/paiement/{token:[a-f0-9]{64}}/options', [\App\Controller\PaymentController::class, 'updateOptions']);
    $app->post('/paiement/{token:[a-f0-9]{64}}/checkout', [\App\Controller\PaymentController::class, 'startCheckout']);
    $app->get('/paiement/retour/{reference}', [\App\Controller\PaymentController::class, 'paymentReturn']);
    // SumUp has no separate webhook-URL setting: it delivers the CHECKOUT_STATUS_CHANGED
    // event via POST to the checkout's own return_url, the same URL the browser is
    // redirected to (GET) after payment. Both must be registered on this one path.
    $app->post('/paiement/retour/{reference}', [\App\Controller\PaymentController::class, 'webhook']);
    $app->post('/paiement/retour/{reference}/virement-effectue', [\App\Controller\PaymentController::class, 'claimBankTransfer']);
    $app->get('/paiement/dev/{reference}', [\App\Controller\PaymentController::class, 'devCheckout']);
    $app->post('/paiement/dev/{reference}', [\App\Controller\PaymentController::class, 'devPay']);

    $app->get('/espace', [MemberController::class, 'dashboard'])->add($memberOnly);
    $app->get('/espace/renouvellement', [\App\Controller\RenewalController::class, 'show'])->add($memberOnly);
    $app->post('/espace/renouvellement', [\App\Controller\RenewalController::class, 'submit'])->add($memberOnly);
    $app->get('/espace/renouvellement/sante', [\App\Controller\RenewalController::class, 'showSante'])->add($memberOnly);
    $app->post('/espace/renouvellement/sante', [\App\Controller\RenewalController::class, 'submitSante'])->add($memberOnly);
    $app->get('/espace/renouvellement/representant-legal', [\App\Controller\RenewalController::class, 'showGuardian'])->add($memberOnly);
    $app->post('/espace/renouvellement/representant-legal', [\App\Controller\RenewalController::class, 'submitGuardian'])->add($memberOnly);
    $app->get('/espace/renouvellement/licence', [\App\Controller\RenewalController::class, 'showLicenceChoice'])->add($memberOnly);
    $app->post('/espace/renouvellement/licence', [\App\Controller\RenewalController::class, 'submitLicenceChoice'])->add($memberOnly);
    $app->get('/espace/renouvellement/paiement', [\App\Controller\RenewalController::class, 'showCart'])->add($memberOnly);
    $app->post('/espace/renouvellement/options', [\App\Controller\RenewalController::class, 'updateOptions'])->add($memberOnly);
    $app->post('/espace/renouvellement/checkout', [\App\Controller\RenewalController::class, 'startCheckout'])->add($memberOnly);
    $app->get('/espace/credits', [\App\Controller\CreditsController::class, 'show'])->add($memberOnly);
    $app->post('/espace/credits/checkout', [\App\Controller\CreditsController::class, 'startCheckout'])->add($memberOnly);
    $app->get('/espace/cours-collectifs', [\App\Controller\LessonSignupController::class, 'show'])->add($memberOnly);
    $app->post('/espace/cours-collectifs/checkout', [\App\Controller\LessonSignupController::class, 'startCheckout'])->add($memberOnly);
    $app->get('/espace/factures', [MemberController::class, 'invoices'])->add($memberOnly);
    $app->get('/espace/factures/{id:\d+}/telecharger', [MemberController::class, 'downloadInvoice'])->add($memberOnly);

    $app->get('/admin', [AdminController::class, 'dashboard'])->add($adminOnly);
    $app->post('/admin/signalement/activer', [AdminController::class, 'enableBugReportMode'])->add($adminOnly);
    $app->post('/admin/signalement/desactiver', [AdminController::class, 'disableBugReportMode'])->add($adminOnly);
    $app->get('/admin/reglages/virement', [AdminController::class, 'showBankDetails'])->add($adminOnly);
    $app->post('/admin/reglages/virement', [AdminController::class, 'saveBankDetails'])->add($adminOnly);
    $app->get('/admin/reglages/signature-email', [AdminController::class, 'showEmailSignature'])->add($adminOnly);
    $app->post('/admin/reglages/signature-email', [AdminController::class, 'saveEmailSignature'])->add($adminOnly);
    $app->get('/admin/reglages/reglement-interieur', [AdminController::class, 'showReglementInterieur'])->add($adminOnly);
    $app->post('/admin/reglages/reglement-interieur', [AdminController::class, 'saveReglementInterieur'])->add($adminOnly);
    $app->get('/admin/reglages/chaussures', [AdminController::class, 'showShoesPolicy'])->add($adminOnly);
    $app->post('/admin/reglages/chaussures', [AdminController::class, 'saveShoesPolicyImage'])->add($adminOnly);
    $app->post('/admin/reglages/chaussures/supprimer', [AdminController::class, 'deleteShoesPolicyImage'])->add($adminOnly);
    $app->get('/admin/demandes', [\App\Controller\AdminApplicationController::class, 'index'])->add($adminOnly);
    $app->get('/admin/demandes/abandonnees', [\App\Controller\AdminApplicationController::class, 'abandoned'])->add($adminOnly);
    $app->post('/admin/demandes/abandonnees/relancer', [\App\Controller\AdminApplicationController::class, 'bulkRemind'])->add($adminOnly);
    $app->post('/admin/demandes/abandonnees/effacer', [\App\Controller\AdminApplicationController::class, 'bulkClear'])->add($adminOnly);
    $app->get('/admin/demandes/{id:\d+}', [\App\Controller\AdminApplicationController::class, 'show'])->add($adminOnly);
    $app->post('/admin/demandes/{id:\d+}/decision', [\App\Controller\AdminApplicationController::class, 'decide'])->add($adminOnly);
    $app->post('/admin/demandes/{id:\d+}/relance', [\App\Controller\AdminApplicationController::class, 'sendReminder'])->add($adminOnly);
    $app->post('/admin/demandes/{id:\d+}/effacer', [\App\Controller\AdminApplicationController::class, 'clear'])->add($adminOnly);
    $app->post('/admin/demandes/{id:\d+}/midi-override', [\App\Controller\AdminApplicationController::class, 'grantMidiOverride'])->add($adminOnly);
    $app->get('/admin/demandes/{id:\d+}/document/{file}', [\App\Controller\AdminApplicationController::class, 'document'])->add($adminOnly);
    $app->get('/admin/changements', [\App\Controller\AdminRenewalController::class, 'changeRequests'])->add($adminOnly);
    $app->get('/admin/changements/archivees', [\App\Controller\AdminRenewalController::class, 'archivedChangeRequests'])->add($adminOnly);
    $app->post('/admin/changements/{id:\d+}/decision', [\App\Controller\AdminRenewalController::class, 'decideChangeRequest'])->add($adminOnly);
    $app->get('/admin/campagne', [\App\Controller\AdminRenewalController::class, 'campaign'])->add($adminOnly);
    $app->post('/admin/campagne/envoyer', [\App\Controller\AdminRenewalController::class, 'campaignSend'])->add($adminOnly);
    $app->get('/admin/membres', [\App\Controller\AdminOpsController::class, 'members'])->add($adminOnly);
    $app->post('/admin/membres/{id:\d+}/voir-comme', [AuthController::class, 'impersonate'])->add($adminOnly);
    $app->get('/admin/cours', [\App\Controller\AdminOpsController::class, 'lessons'])->add($adminOnly);
    $app->get('/admin/licences', [\App\Controller\AdminOpsController::class, 'licences'])->add($adminOnly);
    $app->post('/admin/licences/{id:\d+}', [\App\Controller\AdminOpsController::class, 'clearLicenceFlag'])->add($adminOnly);
    $app->get('/admin/semelles', [\App\Controller\AdminOpsController::class, 'shoes'])->add($adminOnly);
    $app->post('/admin/semelles/{id:\d+}', [\App\Controller\AdminOpsController::class, 'approveShoes'])->add($adminOnly);
    $app->get('/admin/commandes', [\App\Controller\AdminOpsController::class, 'ordersHistory'])->add($adminOnly);
    $app->get('/admin/commandes/archivees', [\App\Controller\AdminOpsController::class, 'archivedOrders'])->add($adminOnly);
    $app->get('/admin/commandes/{id:\d+}', [\App\Controller\AdminOpsController::class, 'orderDetail'])->add($adminOnly);
    $app->get('/admin/commandes/{id:\d+}/facture', [\App\Controller\AdminOpsController::class, 'invoiceDocument'])->add($adminOnly);
    $app->post('/admin/commandes/{id:\d+}/facture/generer', [\App\Controller\AdminOpsController::class, 'generateInvoice'])->add($adminOnly);
    $app->get('/admin/commandes/{id:\d+}/attestation', [\App\Controller\AdminOpsController::class, 'attestationDocument'])->add($adminOnly);
    $app->post('/admin/commandes/{id:\d+}/annuler', [\App\Controller\AdminOpsController::class, 'cancelOrder'])->add($adminOnly);
    $app->post('/admin/commandes/{id:\d+}/rembourser', [\App\Controller\AdminOpsController::class, 'refundOrder'])->add($adminOnly);
    $app->post('/admin/commandes/{id:\d+}/traiter', [\App\Controller\AdminOpsController::class, 'processOrder'])->add($adminOnly);
    $app->get('/admin/virements', [\App\Controller\AdminOpsController::class, 'pendingBankTransfers'])->add($adminOnly);
    $app->post('/admin/virements/{id:\d+}/decision', [\App\Controller\AdminOpsController::class, 'decideBankTransfer'])->add($adminOnly);
    $app->get('/admin/reduction-etudiant', [\App\Controller\AdminOpsController::class, 'pendingStudentDiscounts'])->add($adminOnly);
    $app->post('/admin/reduction-etudiant/{id:\d+}/decision', [\App\Controller\AdminOpsController::class, 'decideStudentDiscount'])->add($adminOnly);
    $app->get('/admin/reduction-etudiant/{id:\d+}/certificat', [\App\Controller\AdminOpsController::class, 'studentCertificateDocument'])->add($adminOnly);

    $app->get('/admin/journal-audit', [\App\Controller\AdminAuditController::class, 'index'])->add($adminOnly);

    $app->get('/admin/codes-promo', [\App\Controller\AdminPromoCodeController::class, 'index'])->add($adminOnly);
    $app->get('/admin/codes-promo/archivees', [\App\Controller\AdminPromoCodeController::class, 'archivedIndex'])->add($adminOnly);
    $app->get('/admin/codes-promo/approbations', [\App\Controller\AdminPromoCodeController::class, 'pendingOrders'])->add($adminOnly);
    $app->post('/admin/codes-promo/approbations/{id:\d+}/decision', [\App\Controller\AdminPromoCodeController::class, 'decidePendingOrder'])->add($adminOnly);
    $app->post('/admin/codes-promo/nouveau', [\App\Controller\AdminPromoCodeController::class, 'create'])->add($adminOnly);
    $app->get('/admin/codes-promo/{id:\d+}/modifier', [\App\Controller\AdminPromoCodeController::class, 'editForm'])->add($adminOnly);
    $app->post('/admin/codes-promo/{id:\d+}/modifier', [\App\Controller\AdminPromoCodeController::class, 'update'])->add($adminOnly);
    $app->post('/admin/codes-promo/{id:\d+}/supprimer', [\App\Controller\AdminPromoCodeController::class, 'delete'])->add($adminOnly);
    $app->post('/admin/codes-promo/{id:\d+}/archiver', [\App\Controller\AdminPromoCodeController::class, 'archive'])->add($adminOnly);
    $app->post('/admin/codes-promo/{id:\d+}/activer', [\App\Controller\AdminPromoCodeController::class, 'activate'])->add($adminOnly);
    $app->post('/admin/codes-promo/{id:\d+}/desactiver', [\App\Controller\AdminPromoCodeController::class, 'deactivate'])->add($adminOnly);

    $app->get('/admin/tarifs', [\App\Controller\AdminPricingController::class, 'index'])->add($adminOnly);
    $app->post('/admin/tarifs/nouvelle', [\App\Controller\AdminPricingController::class, 'create'])->add($adminOnly);
    $app->get('/admin/tarifs/{season:\d{4}-\d{4}}', [\App\Controller\AdminPricingController::class, 'edit'])->add($adminOnly);
    $app->post('/admin/tarifs/{season:\d{4}-\d{4}}/enregistrer', [\App\Controller\AdminPricingController::class, 'save'])->add($adminOnly);
    $app->post('/admin/tarifs/{season:\d{4}-\d{4}}/publier', [\App\Controller\AdminPricingController::class, 'publish'])->add($adminOnly);
    $app->post('/admin/tarifs/{season:\d{4}-\d{4}}/depublier', [\App\Controller\AdminPricingController::class, 'unpublish'])->add($adminOnly);
    $app->get('/admin/tarifs/{season:\d{4}-\d{4}}/export.csv', [\App\Controller\AdminPricingController::class, 'exportCsv'])->add($adminOnly);
    $app->post('/admin/tarifs/{season:\d{4}-\d{4}}/importer', [\App\Controller\AdminPricingController::class, 'importCsv'])->add($adminOnly);
};
