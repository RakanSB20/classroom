<?php
require_once 'vendor/autoload.php';
session_start();

$client = new Google_Client();
$client->setAuthConfig('credentials.json');

// Scopes completos necesarios
$client->addScope([
    Google_Service_Classroom::CLASSROOM_COURSES,        // NO _READONLY
    Google_Service_Classroom::CLASSROOM_ROSTERS,        // NO _READONLY
    Google_Service_Classroom::CLASSROOM_COURSEWORK_STUDENTS, // NO _READONLY
    Google_Service_Classroom::CLASSROOM_COURSEWORK_ME,       // NO _READONLY
    Google_Service_Classroom::CLASSROOM_PROFILE_EMAILS,
    Google_Service_Classroom::CLASSROOM_PROFILE_PHOTOS
]);

$client->setRedirectUri('http://localhost/classroom/google-callback.php');
$client->setAccessType('offline'); // Necesario para obtener refresh_token
$client->setPrompt('consent'); // Forzar consentimiento para refresh_token
$client->setIncludeGrantedScopes(true); // Mantener scopes otorgados anteriormente

// Si viene con código de autorización (de OAuth)
if (isset($_GET['code'])) {
    $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);

    if (isset($token['error'])) {
        die('❌ Error al obtener token: ' . htmlspecialchars($token['error']));
    }

    $_SESSION['access_token'] = $token;

    // Redirigir a la página principal
    header('Location: index.php');
    exit;
}

// Forzar nuevo login si no hay token válido
if (!isset($_SESSION['access_token'])) {
    $authUrl = $client->createAuthUrl();
    header('Location: ' . $authUrl);
    exit;
}

// Ya tiene token
$client->setAccessToken($_SESSION['access_token']);
header('Location: index.php');
exit;
