<!DOCTYPE html>
<html lang="fr">
<head><meta charset="utf-8"><title>Code de connexion</title></head>
<body style="font-family: Arial, sans-serif; color: #0f172a; background: #f8fafc; padding: 24px;">
    <div style="max-width: 480px; margin: 0 auto; background: #ffffff; border-top: 4px solid #fdc911; padding: 24px;">
        <p>Bonjour {{ $utilisateur->prenom ?? $utilisateur->name }},</p>
        <p>Voici votre code de connexion à la plateforme NEEMBA :</p>
        <p style="font-size: 28px; font-weight: bold; letter-spacing: 6px; text-align: center; color: #1e3a8a;">Code : {{ $code }}</p>
        <p>Il est valable {{ $minutes }} minutes. Ne le communiquez à personne : Neemba ne vous le demandera jamais.</p>
        <p style="font-size: 12px; color: #6b7280;">Si vous n'êtes pas à l'origine de cette connexion, changez votre mot de passe et prévenez l'administrateur.</p>
    </div>
</body>
</html>
