<?php
/**
 * Archivo de configuración para el Sistema de Gestión de Notas
 * Integrado con Google Classroom
 */

// Configuración de la base de datos
define('DB_HOST', 'localhost');
define('DB_USERNAME', 'root');
define('DB_PASSWORD', '');
define('DB_NAME', 'classroom_notas');
define('DB_CHARSET', 'utf8mb4');

// Configuración de Google Classroom API
define('GOOGLE_CLIENT_ID', 'tu-client-id.googleusercontent.com');
define('GOOGLE_CLIENT_SECRET', 'tu-client-secret');
define('GOOGLE_REDIRECT_URI', 'http://localhost/classroom/google-callback.php');

// Configuración del sistema
define('SISTEMA_NOMBRE', 'Sistema de Gestión de Notas Académicas');
define('SISTEMA_VERSION', '2.0.0');
define('TIMEZONE', 'America/Lima'); // Ajustar según tu zona horaria

// Configuración de notas (Escala 0-20)
define('NOTA_MINIMA_APROBACION', 11.0);  // 11.0 sobre 20
define('NOTA_EXCELENCIA', 17.0);         // 17.0 sobre 20
define('PRECISION_DECIMALES', 2);        // 2 decimales para notas
define('NOTA_MAXIMA', 20.0);             // Nota máxima del sistema

// Configuración de archivos de exportación
define('EXPORT_MAX_ESTUDIANTES', 1000);
define('EXPORT_FORMATO_FECHA', 'Y-m-d_H-i-s');

// Configuración de seguridad
define('SESSION_TIMEOUT', 3600); // 1 hora en segundos
define('MAX_INTENTOS_LOGIN', 5);

// Configuración de logs
define('LOG_ERRORES', true);
define('LOG_ARCHIVO', __DIR__ . '/logs/sistema.log');

// URLs del sistema
define('BASE_URL', 'http://localhost/classroom/');
define('ASSETS_URL', BASE_URL . 'assets/');

// Configurar zona horaria
date_default_timezone_set(TIMEZONE);

// Configuración de errores para desarrollo/producción
if (defined('DESARROLLO') && DESARROLLO) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    ini_set('log_errors', 1);
    ini_set('error_log', LOG_ARCHIVO);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    ini_set('error_log', LOG_ARCHIVO);
}

/**
 * Función para escribir logs del sistema
 */
function escribirLog($mensaje, $nivel = 'INFO') {
    if (LOG_ERRORES) {
        $timestamp = date('Y-m-d H:i:s');
        $log = "[{$timestamp}] [{$nivel}] {$mensaje}" . PHP_EOL;
        
        $logDir = dirname(LOG_ARCHIVO);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        file_put_contents(LOG_ARCHIVO, $log, FILE_APPEND | LOCK_EX);
    }
}

/**
 * Función para validar configuración del sistema
 */
function validarConfiguracion() {
    $errores = [];
    
    // Verificar extensiones de PHP requeridas
    $extensionesRequeridas = ['pdo', 'pdo_mysql', 'curl', 'json', 'mbstring'];
    foreach ($extensionesRequeridas as $extension) {
        if (!extension_loaded($extension)) {
            $errores[] = "Extensión de PHP requerida no encontrada: {$extension}";
        }
    }
    
    // Verificar permisos de directorios
    $directorios = [
        dirname(LOG_ARCHIVO),
        __DIR__ . '/temp',
        __DIR__ . '/uploads'
    ];
    
    foreach ($directorios as $directorio) {
        if (!is_dir($directorio)) {
            mkdir($directorio, 0755, true);
        }
        if (!is_writable($directorio)) {
            $errores[] = "Directorio sin permisos de escritura: {$directorio}";
        }
    }
    
    // Verificar archivo de credenciales de Google
    if (!file_exists(__DIR__ . '/credentials.json')) {
        $errores[] = "Archivo credentials.json no encontrado. Descárgalo desde Google Cloud Console.";
    }
    
    return $errores;
}

/**
 * Función para obtener configuración de Google Client
 */
function obtenerConfiguracionGoogle() {
    return [
        'client_id' => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'redirect_uri' => GOOGLE_REDIRECT_URI,
        'scopes' => [
            Google_Service_Classroom::CLASSROOM_COURSES,
            Google_Service_Classroom::CLASSROOM_ROSTERS,
            Google_Service_Classroom::CLASSROOM_COURSEWORK_STUDENTS,
            Google_Service_Classroom::CLASSROOM_COURSEWORK_ME,
            Google_Service_Classroom::CLASSROOM_PROFILE_EMAILS,
            Google_Service_Classroom::CLASSROOM_PROFILE_PHOTOS,
            Google_Service_Classroom::CLASSROOM_STUDENT_SUBMISSIONS_STUDENTS_READONLY
        ]
    ];
}

/**
 * Función para formatear notas según configuración
 */
function formatearNota($nota) {
    return number_format($nota, PRECISION_DECIMALES);
}

/**
 * Función para determinar el estado de una nota
 */
function obtenerEstadoNota($nota) {
    if ($nota >= NOTA_EXCELENCIA) return 'excelente';
    if ($nota >= NOTA_MINIMA_APROBACION) return 'aprobado';
    return 'desaprobado';
}

/**
 * Función para obtener color CSS según el estado de la nota
 */
function obtenerColorNota($nota) {
    $estado = obtenerEstadoNota($nota);
    switch ($estado) {
        case 'excelente':
            return '#00AA00'; // Verde oscuro
        case 'aprobado':
            return '#0066CC'; // Azul
        default:
            return '#CC0000'; // Rojo
    }
}

/**
 * Verificar configuración al incluir el archivo
 */
$erroresConfig = validarConfiguracion();
if (!empty($erroresConfig)) {
    escribirLog('Errores de configuración encontrados: ' . implode(', ', $erroresConfig), 'ERROR');
    
    if (defined('DESARROLLO') && DESARROLLO) {
        echo '<div style="background: #ffebee; border: 1px solid #f44336; padding: 15px; margin: 10px; border-radius: 4px;">';
        echo '<h3 style="color: #d32f2f; margin-top: 0;">⚠️ Errores de Configuración</h3>';
        echo '<ul style="color: #d32f2f;">';
        foreach ($erroresConfig as $error) {
            echo "<li>{$error}</li>";
        }
        echo '</ul>';
        echo '</div>';
    }
}

// Autoload de clases personalizadas
spl_autoload_register(function ($clase) {
    $archivos = [
        __DIR__ . '/classes/' . $clase . '.php',
        __DIR__ . '/includes/' . $clase . '.php'
    ];
    
    foreach ($archivos as $archivo) {
        if (file_exists($archivo)) {
            require_once $archivo;
            break;
        }
    }
});
?>