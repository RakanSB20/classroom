<?php
require_once 'vendor/autoload.php';
session_start();

$client = new Google_Client();
$client->setAuthConfig('credentials.json');
$client->addScope([
    Google_Service_Classroom::CLASSROOM_COURSES,
    Google_Service_Classroom::CLASSROOM_ROSTERS
]);
$client->setRedirectUri('http://localhost/classroom/google-callback.php');
$client->setAccessType('offline');
$client->setPrompt('select_account consent');

if (!isset($_SESSION['access_token'])) {
    // No está autenticado, redirige a login
    $authUrl = $client->createAuthUrl();
    header('Location: ' . $authUrl);
    exit;
}

$client->setAccessToken($_SESSION['access_token']);

// Refrescar token si expiró
if ($client->isAccessTokenExpired()) {
    if ($client->getRefreshToken()) {
        $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
        $_SESSION['access_token'] = $client->getAccessToken();
    } else {
        // No se puede refrescar, forzar login de nuevo
        unset($_SESSION['access_token']);
        header('Location: ' . $client->createAuthUrl());
        exit;
    }
}

$mensaje = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $service = new Google_Service_Classroom($client);

    $course = new Google_Service_Classroom_Course([
        'name' => $_POST['nombre'],
        'section' => $_POST['seccion'] ?? '',
        'room' => $_POST['aula'] ?? '',
        'ownerId' => 'me',
    ]);

    try {
        $course = $service->courses->create($course);
        $mensaje = '✅ Curso creado correctamente: ' . htmlspecialchars($course->getName());
    } catch (Exception $e) {
        $mensaje = '❌ Error al crear curso: ' . htmlspecialchars($e->getMessage());
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <title>Crear Curso - Google Classroom</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
</head>
<body class="bg-light">
<div class="container py-5">
    <h1 class="text-center text-primary mb-4">📘 Crear Nuevo Curso</h1>

    <?php if ($mensaje): ?>
        <div class="alert <?= str_starts_with($mensaje, '✅') ? 'alert-success' : 'alert-danger' ?>">
            <?= $mensaje ?>
        </div>
    <?php endif; ?>

    <form method="POST" class="card p-4 shadow-sm">
        <div class="mb-3">
            <label class="form-label">Nombre del Curso</label>
            <input type="text" name="nombre" class="form-control" required />
        </div>
        <div class="mb-3">
            <label class="form-label">Sección</label>
            <input type="text" name="seccion" class="form-control" />
        </div>
        <div class="mb-3">
            <label class="form-label">Aula</label>
            <input type="text" name="aula" class="form-control" />
        </div>
        <button type="submit" class="btn btn-primary w-100">Crear Curso </button>
    </form>

    <div class="text-center mt-4">
        <a href="index.php" class="btn btn-outline-secondary">⬅ Volver a Inicio</a>
    </div>
</div>
</body>
</html>
