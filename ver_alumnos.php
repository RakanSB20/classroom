<?php
require_once 'vendor/autoload.php';
session_start();

$client = new Google_Client();
$client->setAuthConfig('credentials.json');
$client->addScope([
    Google_Service_Classroom::CLASSROOM_COURSES,
    Google_Service_Classroom::CLASSROOM_ROSTERS
]);
$client->setRedirectUri('http://localhost/classroom/ver_alumnos.php');
$client->setAccessType('offline');

// Redirigir si no hay token ni código
if (!isset($_SESSION['access_token']) && !isset($_GET['code'])) {
    $authUrl = $client->createAuthUrl();
    header('Location: ' . $authUrl);
    exit;
}

// Obtener token con código
if (isset($_GET['code'])) {
    $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);
    if (isset($token['error'])) {
        die('Error al obtener token: ' . htmlspecialchars($token['error']));
    }
    $_SESSION['access_token'] = $token;
    $client->setAccessToken($token);
    header('Location: ver_alumnos.php');
    exit;
}

// Si ya hay token en sesión
if (isset($_SESSION['access_token'])) {
    $client->setAccessToken($_SESSION['access_token']);

    // Refrescar si expiró
    if ($client->isAccessTokenExpired()) {
        if ($client->getRefreshToken()) {
            $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
            $_SESSION['access_token'] = $client->getAccessToken();
        } else {
            unset($_SESSION['access_token']);
            header('Location: ' . $client->createAuthUrl());
            exit;
        }
    }
}

$service = new Google_Service_Classroom($client);
$mensaje = '';
$courses = [];
$alumnos = [];
$cursoId = $_GET['curso'] ?? null;
$cursoSeleccionado = null;

try {
    $response = $service->courses->listCourses(['pageSize' => 50]);
    $courses = $response->getCourses();
} catch (Exception $e) {
    $mensaje = '❌ Error al obtener cursos: ' . htmlspecialchars($e->getMessage());
}

if ($cursoId) {
    try {
        $cursoSeleccionado = $service->courses->get($cursoId);
        $response = $service->courses_students->listCoursesStudents($cursoId);
        $alumnos = $response->getStudents();
        if (!$alumnos) {
            $mensaje = '⚠️ Este curso no tiene alumnos inscritos.';
        }
    } catch (Exception $e) {
        $mensaje = '❌ Error al obtener alumnos: ' . htmlspecialchars($e->getMessage());
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Ver Alumnos - Google Classroom</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
    <h1 class="text-center text-info mb-4">👥 Alumnos Inscritos</h1>

    <?php if ($mensaje): ?>
        <div class="alert 
            <?= str_contains($mensaje, '❌') ? 'alert-danger' : 
                 (str_contains($mensaje, '⚠️') ? 'alert-warning' : 'alert-success') ?>">
            <?= $mensaje ?>
        </div>
    <?php endif; ?>

    <?php if ($cursoSeleccionado): ?>
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-primary text-white">
                Curso: <?= htmlspecialchars($cursoSeleccionado->getName()) ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($alumnos)): ?>
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white">
                Lista de Alumnos (<?= count($alumnos) ?>)
            </div>
            <ul class="list-group list-group-flush">
                <?php foreach ($alumnos as $alumno): ?>
                    <li class="list-group-item">
                        <strong><?= htmlspecialchars($alumno->getProfile()->getName()->getFullName()) ?></strong>
                        <br>
                        <?= htmlspecialchars($alumno->getProfile()->getEmailAddress()) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php elseif (!$cursoId): ?>
        <div class="alert alert-warning">
            ⚠️ No se ha seleccionado ningún curso.
        </div>
    <?php endif; ?>

    <div class="text-center mt-4">
        <a href="index.php" class="btn btn-outline-secondary">⬅ Volver a Inicio</a>
    </div>
</div>
</body>
</html>
