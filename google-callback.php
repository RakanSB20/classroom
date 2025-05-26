<?php
require_once 'vendor/autoload.php';
session_start();

$client = new Google_Client();
$client->setAuthConfig('credentials.json');
$client->addScope([
    Google_Service_Classroom::CLASSROOM_COURSES,
    Google_Service_Classroom::CLASSROOM_ROSTERS,
    Google_Service_Classroom::CLASSROOM_COURSEWORK_ME,
    Google_Service_Classroom::CLASSROOM_PROFILE_EMAILS
]);
$client->setRedirectUri('http://localhost/classroom/google-callback.php');
$client->setAccessType('offline');
$client->setPrompt('consent');

// Si viene con código de autorización (de OAuth)
if (isset($_GET['code'])) {
    // Obtener token usando el código recibido
    $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);

    if (isset($token['error'])) {
        die('Error al obtener token: ' . htmlspecialchars($token['error']));
    }

    // Guardar el token completo, importante para tener el refresh_token
    $_SESSION['access_token'] = $token;

    // Redirigir a la página principal
    header('Location: index.php');
    exit;
}

// Si no hay token en sesión, enviar a login
if (!isset($_SESSION['access_token'])) {
    $authUrl = $client->createAuthUrl();
    header('Location: ' . $authUrl);
    exit;
}

// Si ya tiene token, cargarlo y redirigir (podrías validar expiración aquí)
$client->setAccessToken($_SESSION['access_token']);
header('Location: index.php');
exit;
