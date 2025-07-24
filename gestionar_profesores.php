<?php
require_once 'vendor/autoload.php';
session_start();

$client = new Google_Client();
$client->setAuthConfig('credentials.json');
$client->addScope([
    Google_Service_Classroom::CLASSROOM_COURSES,
    Google_Service_Classroom::CLASSROOM_ROSTERS
]);
$client->setRedirectUri('http://localhost/classroom/gestionar_profesores.php');
$client->setAccessType('offline');

// Redirigir a autenticación si no hay token ni código
if (!isset($_SESSION['access_token']) && !isset($_GET['code'])) {
    $authUrl = $client->createAuthUrl();
    header('Location: ' . $authUrl);
    exit;
}

// Si se recibe código, obtener token
if (isset($_GET['code'])) {
    $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);
    if (isset($token['error'])) {
        die('Error al obtener token: ' . htmlspecialchars($token['error']));
    }
    $_SESSION['access_token'] = $token;
    $client->setAccessToken($token);
    header('Location: gestionar_profesores.php');
    exit;
}

// Si ya hay token en sesión
if (isset($_SESSION['access_token'])) {
    $client->setAccessToken($_SESSION['access_token']);

    // Refrescar token si expiró
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

$mensaje = '';
$courses = [];
$profesores = [];
$courseId = $_GET['courseId'] ?? '';

// Validar formato de correo
function esCorreoValido($correo) {
    return filter_var($correo, FILTER_VALIDATE_EMAIL);
}

try {
    $service = new Google_Service_Classroom($client);
    $response = $service->courses->listCourses(['pageSize' => 50]);
    $courses = $response->getCourses();
    
    // Si hay un curso seleccionado, obtener sus profesores
    if ($courseId) {
        try {
            $teachersResponse = $service->courses_teachers->listCoursesTeachers($courseId);
            $profesores = $teachersResponse->getTeachers() ?: [];
        } catch (Exception $e) {
            $mensaje .= "⚠️ Error al obtener profesores: " . htmlspecialchars($e->getMessage()) . "<br>";
        }
    }
} catch (Exception $e) {
    $mensaje = '❌ Error al obtener cursos: ' . htmlspecialchars($e->getMessage());
}

// Procesar formulario para invitar profesor
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $courseId = $_POST['curso'];
    
    if ($action === 'invitar') {
        $rawEmails = array_map('trim', explode(',', $_POST['correo']));
        $emails = array_unique($rawEmails);

        foreach ($emails as $email) {
            if (!esCorreoValido($email)) {
                $mensaje .= "⚠️ Correo inválido: $email<br>";
                continue;
            }

            try {
                $teacher = new Google_Service_Classroom_Teacher([
                    'userId' => $email
                ]);
                $service->courses_teachers->create($courseId, $teacher);
                $mensaje .= "✅ $email invitado como profesor correctamente.<br>";
            } catch (Google_Service_Exception $e) {
                $error = $e->getErrors()[0]['reason'] ?? '';
                if ($error === 'forbidden') {
                    try {
                        $invitation = new Google_Service_Classroom_Invitation([
                            'courseId' => $courseId,
                            'role' => 'TEACHER',
                            'userId' => $email
                        ]);
                        $service->invitations->create($invitation);
                        $mensaje .= "📧 Invitación enviada a $email como profesor.<br>";
                    } catch (Exception $invErr) {
                        $mensaje .= "❌ Error al enviar invitación a $email: " . htmlspecialchars($invErr->getMessage()) . "<br>";
                    }
                } elseif ($error === 'alreadyExists') {
                    $mensaje .= "⚠️ $email ya es profesor de este curso.<br>";
                } else {
                    $mensaje .= "❌ Error con $email: " . htmlspecialchars($e->getMessage()) . "<br>";
                }
            } catch (Exception $e) {
                $mensaje .= "❌ Error general con $email: " . htmlspecialchars($e->getMessage()) . "<br>";
            }
        }
        
        // Recargar profesores después de la invitación
        if ($courseId) {
            try {
                $teachersResponse = $service->courses_teachers->listCoursesTeachers($courseId);
                $profesores = $teachersResponse->getTeachers() ?: [];
            } catch (Exception $e) {
                // Error silencioso al recargar
            }
        }
    }
    
    if ($action === 'quitar') {
        $teacherId = $_POST['teacher_id'];
        try {
            $service->courses_teachers->delete($courseId, $teacherId);
            $mensaje .= "✅ Profesor removido correctamente del curso.<br>";
            
            // Recargar profesores después de quitar
            try {
                $teachersResponse = $service->courses_teachers->listCoursesTeachers($courseId);
                $profesores = $teachersResponse->getTeachers() ?: [];
            } catch (Exception $e) {
                // Error silencioso al recargar
            }
        } catch (Exception $e) {
            $mensaje .= "❌ Error al quitar profesor: " . htmlspecialchars($e->getMessage()) . "<br>";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestionar Profesores - Google Classroom</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
    <h1 class="text-center text-success mb-4">
        <i class="fas fa-chalkboard-teacher me-2"></i>
        Gestionar Profesores
    </h1>

    <?php if ($mensaje): ?>
        <div class="alert 
            <?= str_contains($mensaje, '❌') ? 'alert-danger' : 
                 (str_contains($mensaje, '⚠️') ? 'alert-warning' : 'alert-success') ?>">
            <?= $mensaje ?>
        </div>
    <?php endif; ?>

    <!-- Seleccionar curso -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">
                <i class="fas fa-book me-2"></i>
                Seleccionar Curso
            </h5>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-10">
                    <select name="courseId" class="form-select" required>
                        <option value="">-- Selecciona un curso --</option>
                        <?php foreach ($courses as $course): ?>
                            <option value="<?= htmlspecialchars($course->getId()) ?>" 
                                    <?= $courseId === $course->getId() ? 'selected' : '' ?>>
                                <?= htmlspecialchars($course->getName()) ?>
                                <?= $course->getSection() ? ' - ' . htmlspecialchars($course->getSection()) : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search me-1"></i>Ver
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($courseId): ?>
        <!-- Profesores actuales -->
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0">
                    <i class="fas fa-users me-2"></i>
                    Profesores del Curso
                </h5>
            </div>
            <div class="card-body">
                <?php if ($profesores): ?>
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Nombre</th>
                                    <th>Correo</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($profesores as $profesor): ?>
                                    <tr>
                                        <td>
                                            <i class="fas fa-user-tie me-2 text-primary"></i>
                                            <?= htmlspecialchars($profesor->getProfile()->getName()->getFullName()) ?>
                                        </td>
                                        <td><?= htmlspecialchars($profesor->getProfile()->getEmailAddress()) ?></td>
                                        <td>
                                            <form method="POST" style="display: inline;" 
                                                  onsubmit="return confirm('¿Estás seguro de quitar este profesor del curso?')">
                                                <input type="hidden" name="action" value="quitar">
                                                <input type="hidden" name="curso" value="<?= $courseId ?>">
                                                <input type="hidden" name="teacher_id" value="<?= $profesor->getUserId() ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                                    <i class="fas fa-user-minus me-1"></i>Quitar
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        No hay profesores adicionales en este curso.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Invitar nuevo profesor -->
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0">
                    <i class="fas fa-user-plus me-2"></i>
                    Invitar Nuevo Profesor
                </h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="invitar">
                    <input type="hidden" name="curso" value="<?= $courseId ?>">
                    
                    <div class="mb-3">
                        <label class="form-label">
                            <i class="fas fa-envelope me-1"></i>
                            Correo(s) del Profesor (separados por coma)
                        </label>
                        <textarea name="correo" class="form-control" rows="3" 
                                  placeholder="ej: profesor1@gmail.com, profesor2@gmail.com" required></textarea>
                        <div class="form-text">
                            Puedes invitar múltiples profesores separando sus correos con comas.
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-paper-plane me-2"></i>
                        Enviar Invitación(es)
                    </button>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <div class="text-center mt-4">
        <a href="index.php" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-1"></i>
            Volver a Inicio
        </a>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>