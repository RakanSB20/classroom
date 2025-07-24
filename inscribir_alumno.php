<?php
require_once 'vendor/autoload.php';
session_start();

$client = new Google_Client();
$client->setAuthConfig('credentials.json');
$client->addScope([
    Google_Service_Classroom::CLASSROOM_COURSES,
    Google_Service_Classroom::CLASSROOM_ROSTERS
]);
$client->setRedirectUri('http://localhost/classroom/inscribir_alumno.php');
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
    header('Location: inscribir_alumno.php');
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

// Validar formato de correo
function esCorreoValido($correo) {
    return filter_var($correo, FILTER_VALIDATE_EMAIL);
}

try {
    $service = new Google_Service_Classroom($client);
    $response = $service->courses->listCourses(['pageSize' => 50]);
    $courses = $response->getCourses();
} catch (Exception $e) {
    $mensaje = '❌ Error al obtener cursos: ' . htmlspecialchars($e->getMessage());
}

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $courseId = $_POST['curso'];
    $rawEmails = array_map('trim', explode(',', $_POST['correo']));
    $emails = array_unique($rawEmails);

    foreach ($emails as $email) {
        if (!esCorreoValido($email)) {
            $mensaje .= "⚠️ Correo inválido: $email<br>";
            continue;
        }

        try {
            $student = new Google_Service_Classroom_Student([
                'userId' => $email
            ]);
            $service->courses_students->create($courseId, $student);
            $mensaje .= "✅ $email inscrito correctamente.<br>";
        } catch (Google_Service_Exception $e) {
            $error = $e->getErrors()[0]['reason'] ?? '';
            if ($error === 'forbidden') {
                try {
                    $invitation = new Google_Service_Classroom_Invitation([
                        'courseId' => $courseId,
                        'role' => 'STUDENT',
                        'userId' => $email
                    ]);
                    $service->invitations->create($invitation);
                    $mensaje .= " Invitación enviada a $email.<br>";
                } catch (Exception $invErr) {
                    $mensaje .= "❌ Error al enviar invitación a $email: " . htmlspecialchars($invErr->getMessage()) . "<br>";
                }
            } elseif ($error === 'alreadyExists') {
                $mensaje .= "⚠️ $email ya está inscrito o invitado.<br>";
            } else {
                $mensaje .= "❌ Error con $email: " . htmlspecialchars($e->getMessage()) . "<br>";
            }
        } catch (Exception $e) {
            $mensaje .= "❌ Error general con $email: " . htmlspecialchars($e->getMessage()) . "<br>";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Inscribir Alumnos - Google Classroom</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
    <h1 class="text-center text-success mb-4">👨‍🎓 Inscribir Alumnos</h1>

    <?php if ($mensaje): ?>
        <div class="alert 
            <?= str_contains($mensaje, '❌') ? 'alert-danger' : 
                 (str_contains($mensaje, '⚠️') ? 'alert-warning' : 'alert-success') ?>">
            <?= $mensaje ?>
        </div>
    <?php endif; ?>

    <form method="POST" class="card p-4 shadow-sm">
        <div class="mb-3">
            <label class="form-label">Selecciona un Curso</label>
            <select name="curso" class="form-select" required>
                <option value="">-- Selecciona --</option>
                <?php foreach ($courses as $course): ?>
                    <option value="<?= htmlspecialchars($course->getId()) ?>">
                        <?= htmlspecialchars($course->getName()) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label">Correo(s) del Alumno (separados por coma)</label>
            <textarea name="correo" class="form-control" rows="3" placeholder="ej: alumno1@gmail.com, alumno2@gmail.com" required></textarea>
        </div>
        <button type="submit" class="btn btn-success w-100">Inscribir Alumno(s) </button>
    </form>

    <div class="text-center mt-4">
        <a href="index.php" class="btn btn-outline-secondary">⬅ Volver a Inicio</a>
    </div>
</div>
</body>
</html>