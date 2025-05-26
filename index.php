<?php
require_once 'vendor/autoload.php';
session_start();

$client = new Google_Client();
$client->setAuthConfig('credentials.json');
$client->addScope([
    Google_Service_Classroom::CLASSROOM_COURSES_READONLY,
    Google_Service_Classroom::CLASSROOM_ROSTERS_READONLY,
    Google_Service_Classroom::CLASSROOM_COURSES,
    Google_Service_Classroom::CLASSROOM_ROSTERS
]);
$client->setRedirectUri('http://localhost/classroom/index.php');
$client->setAccessType('offline');
$client->setPrompt('select_account consent');

// Si no tenemos token, enviamos a login
if (!isset($_SESSION['access_token'])) {
    $authUrl = $client->createAuthUrl();
    header('Location: ' . $authUrl);
    exit;
}

$client->setAccessToken($_SESSION['access_token']);

// Si el token expiró, intentamos refrescarlo
if ($client->isAccessTokenExpired()) {
    if ($client->getRefreshToken()) {
        $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
        $_SESSION['access_token'] = $client->getAccessToken();
    } else {
        // No se puede refrescar token, pedimos login otra vez
        unset($_SESSION['access_token']);
        header('Location: ' . $client->createAuthUrl());
        exit;
    }
}

$service = new Google_Service_Classroom($client);

$mensaje = '';
$cursos = [];

try {
    $response = $service->courses->listCourses();
    $cursos = $response->getCourses();
} catch (Exception $e) {
    $mensaje = '❌ Error al obtener cursos: ' . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <title>Google Classroom - Inicio</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
</head>
<body class="bg-light">
<div class="container py-5">
    <h1 class="text-center text-primary mb-4">🎓 Mis Cursos - Google Classroom</h1>

    <?php if ($mensaje): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($mensaje) ?></div>
    <?php endif; ?>

    <?php if ($cursos): ?>
        <div class="row row-cols-1 row-cols-md-2 g-4 mb-4">
            <?php foreach ($cursos as $curso): ?>
                <div class="col">
                    <div class="card h-100 shadow-sm">
                        <div class="card-body">
                            <h5 class="card-title"><?= htmlspecialchars($curso->getName()) ?></h5>
                            <p class="card-text mb-1">
                                <strong>Sección:</strong> <?= htmlspecialchars($curso->getSection() ?: 'Sin sección') ?>
                            </p>
                            <p class="card-text mb-3">
                                <strong>Aula:</strong> <?= htmlspecialchars($curso->getRoom() ?: 'Sin aula') ?>
                            </p>
                            <a href="ver_alumnos.php?curso=<?= $curso->getId() ?>" class="btn btn-info btn-sm">👀 Ver Alumnos</a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

    <?php else: ?>
        <div class="alert alert-warning">No hay cursos disponibles.</div>
    <?php endif; ?>

    <div class="d-flex gap-3 justify-content-center">
        <a href="crear_curso.php" class="btn btn-success">📘 Crear Curso</a>
        <a href="inscribir_alumno.php" class="btn btn-primary">➕ Inscribir Alumnos</a>
        <a href="logout.php" class="btn btn-danger">🔓 Cerrar sesión</a>
    </div>
</div>
</body>
</html>
