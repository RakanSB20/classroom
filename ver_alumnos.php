<?php
require_once 'vendor/autoload.php';
session_start();

if (!isset($_SESSION['access_token'])) {
    header('Location: index.php');
    exit;
}

$client = new Google_Client();
$client->setAuthConfig('credentials.json');
$client->addScope([
    Google_Service_Classroom::CLASSROOM_ROSTERS,
    Google_Service_Classroom::CLASSROOM_COURSES
]);
$client->setAccessToken($_SESSION['access_token']);

$service = new Google_Service_Classroom($client);

$cursoId = $_GET['curso'] ?? '';
$alumnos = [];
$nombreCurso = '';
$mensaje = '';

if ($cursoId) {
    try {
        $curso = $service->courses->get($cursoId);
        $nombreCurso = $curso->getName();

        $response = $service->courses_students->listCoursesStudents($cursoId);
        $alumnos = $response->getStudents();
    } catch (Exception $e) {
        $mensaje = '❌ Error al obtener alumnos: ' . $e->getMessage();
    }
} else {
    $mensaje = '❌ No se proporcionó ID de curso.';
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Ver Alumnos</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
</head>
<body class="bg-light">
<div class="container py-4">
    <h2 class="mb-4 text-primary">Alumnos del curso: <?= htmlspecialchars($nombreCurso) ?></h2>

    <?php if ($mensaje): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($mensaje) ?></div>
    <?php endif; ?>

    <?php if ($alumnos): ?>
        <table class="table table-bordered">
            <thead class="table-secondary">
                <tr>
                    <th>Nombre</th>
                    <th>Correo</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($alumnos as $alumno): ?>
                    <tr>
                        <td><?= htmlspecialchars($alumno->getProfile()->getName()->getFullName()) ?></td>
                        <td><?= htmlspecialchars($alumno->getProfile()->getEmailAddress()) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <a href="index.php" class="btn btn-secondary mt-3">← Volver</a>
</div>
</body>
</html>
