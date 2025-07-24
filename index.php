<?php
require_once 'vendor/autoload.php';
session_start();

$client = new Google_Client();
$client->setAuthConfig('credentials.json');
$client->addScope([
    Google_Service_Classroom::CLASSROOM_COURSES,
    Google_Service_Classroom::CLASSROOM_ROSTERS,
    Google_Service_Classroom::CLASSROOM_COURSEWORK_ME,
    Google_Service_Classroom::CLASSROOM_COURSEWORK_STUDENTS,
    Google_Service_Classroom::CLASSROOM_COURSEWORK_STUDENTS_READONLY,
    Google_Service_Classroom::CLASSROOM_STUDENT_SUBMISSIONS_STUDENTS_READONLY
]);

$client->setRedirectUri('http://localhost/classroom/index.php');
$client->setAccessType('offline');
$client->setPrompt('select_account consent');

// Si no tenemos token, redirigir a login
if (!isset($_SESSION['access_token'])) {
    $authUrl = $client->createAuthUrl();
    header('Location: ' . $authUrl);
    exit;
}

$client->setAccessToken($_SESSION['access_token']);

// Si el token expiró, intentar refrescarlo
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

$service = new Google_Service_Classroom($client);
$cursos = [];
$mensaje = '';

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
    <title>Google Classroom - Mis Cursos</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .course-card {
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .course-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }
        .btn-action {
            border-radius: 20px;
            padding: 8px 16px;
            font-size: 0.9em;
            transition: all 0.3s ease;
        }
        .btn-action:hover {
            transform: translateY(-2px);
        }
        .main-actions {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
        }
        .action-btn {
            background: white;
            color: #667eea;
            border: none;
            border-radius: 12px;
            padding: 12px 20px;
            margin: 5px;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
        }
        .action-btn:hover {
            background:rgb(17, 17, 17);
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
            color: #5a67d8;
        }
    </style>
</head>
<body class="bg-light">
<div class="container py-5">
    <h1 class="text-center text-primary mb-4">
        <i class="fas fa-graduation-cap me-2"></i>
        📚 Mis Cursos - Google Classroom
    </h1>

    <?php if ($mensaje): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($mensaje) ?></div>
    <?php endif; ?>

    <!-- Acciones principales -->
    <div class="main-actions text-center text-white mb-5">
        <h4 class="mb-4">
            <i class="fas fa-tools me-2"></i>
            Panel de Gestión
        </h4>
        <div class="row justify-content-center">
            <div class="col-auto">
                <a href="crear_curso.php" class="action-btn">
                    <i class="fas fa-plus-circle me-2"></i>
                    ➕ Crear Curso
                </a>
            </div>
            <div class="col-auto">
                <a href="inscribir_alumno.php" class="action-btn">
                    <i class="fas fa-user-plus me-2"></i>
                    👨‍🏫 Inscribir Alumnos
                </a>
            </div>
            <div class="col-auto">
                <a href="gestionar_profesores.php" class="action-btn">
                    <i class="fas fa-chalkboard-teacher me-2"></i>
                    👩‍🏫 Gestionar Profesores
                </a>
            </div>
            <div class="col-auto">
                <a href="logout.php" class="action-btn text-danger">
                    <i class="fas fa-sign-out-alt me-2"></i>
                    🔒 Cerrar sesión
                </a>
            </div>
        </div>
    </div>

    <?php if ($cursos): ?>
        <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-4 mb-4">
            <?php foreach ($cursos as $curso): ?>
                <div class="col">
                    <div class="card h-100 shadow-sm course-card">
                        <div class="card-header bg-gradient text-dark" style="background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);">
                            <h6 class="mb-0">
                                <i class="fas fa-book me-2"></i>
                                <?= htmlspecialchars($curso->getName()) ?>
                            </h6>
                        </div>
                        <div class="card-body">
                            <p class="card-text mb-2">
                                <i class="fas fa-layer-group me-2 text-muted"></i>
                                <strong>Sección:</strong> <?= htmlspecialchars($curso->getSection() ?: 'Sin sección') ?>
                            </p>
                            <p class="card-text mb-3">
                                <i class="fas fa-door-open me-2 text-muted"></i>
                                <strong>Aula:</strong> <?= htmlspecialchars($curso->getRoom() ?: 'Sin aula') ?>
                            </p>
                            
                            <div class="d-grid gap-2">
                                <a href="ver_alumnos.php?curso=<?= $curso->getId() ?>" 
                                   class="btn btn-info btn-action">
                                    <i class="fas fa-users me-1"></i>Ver Alumnos
                                </a>
                                <a href="notas_vista.php?courseId=<?= $curso->getId() ?>" 
                                   class="btn btn-warning btn-action">
                                    <i class="fas fa-chart-line me-1"></i>Gestionar Notas
                                </a>
                                <a href="gestionar_profesores.php?courseId=<?= $curso->getId() ?>" 
                                   class="btn btn-success btn-action">
                                    <i class="fas fa-chalkboard-teacher me-1"></i>Profesores
                                </a>
                            </div>
                        </div>
                        <div class="card-footer bg-light text-center">
                            <small class="text-muted">
                                <i class="fas fa-id-badge me-1"></i>
                                ID: <?= htmlspecialchars(substr($curso->getId(), -8)) ?>
                            </small>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        
        <div class="alert alert-info">
            <i class="fas fa-info-circle me-2"></i>
            <strong>Total de cursos:</strong> <?= count($cursos) ?>
        </div>
    <?php else: ?>
        <div class="alert alert-warning text-center">
            <i class="fas fa-exclamation-triangle me-2"></i>
            ⚠️ No hay cursos disponibles.
            <br><br>
            <a href="crear_curso.php" class="btn btn-primary">
                <i class="fas fa-plus me-1"></i>Crear tu primer curso
            </a>
        </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>