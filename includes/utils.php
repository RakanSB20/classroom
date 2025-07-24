<?php
/**
 * Utilidades del Sistema de Gestión de Notas
 * Funciones comunes y helpers
 */

require_once __DIR__ . '/../config.php';

/**
 * Clase de utilidades principales
 */
class Utils {
    
    /**
     * Sanitizar cadena de texto para mostrar en HTML
     */
    public static function sanitizar($texto) {
        return htmlspecialchars($texto, ENT_QUOTES, 'UTF-8');
    }
    
    /**
     * Validar email
     */
    public static function validarEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
    
    /**
     * Validar múltiples emails separados por coma
     */
    public static function validarEmails($emails) {
        $emailArray = array_map('trim', explode(',', $emails));
        $emailsValidos = [];
        $emailsInvalidos = [];
        
        foreach ($emailArray as $email) {
            if (self::validarEmail($email)) {
                $emailsValidos[] = $email;
            } else {
                $emailsInvalidos[] = $email;
            }
        }
        
        return [
            'validos' => array_unique($emailsValidos),
            'invalidos' => $emailsInvalidos
        ];
    }
    
    /**
     * Generar nombre de archivo seguro
     */
    public static function generarNombreArchivo($nombre, $extension = '') {
        $nombre = self::limpiarNombreArchivo($nombre);
        $timestamp = date(EXPORT_FORMATO_FECHA);
        
        if ($extension && !str_starts_with($extension, '.')) {
            $extension = '.' . $extension;
        }
        
        return $nombre . '_' . $timestamp . $extension;
    }
    
    /**
     * Limpiar nombre de archivo removiendo caracteres especiales
     */
    public static function limpiarNombreArchivo($nombre) {
        // Remover acentos
        $nombre = iconv('UTF-8', 'ASCII//TRANSLIT', $nombre);
        // Remover caracteres especiales
        $nombre = preg_replace('/[^A-Za-z0-9_\-]/', '_', $nombre);
        // Remover guiones bajos múltiples
        $nombre = preg_replace('/_+/', '_', $nombre);
        // Remover guiones bajos al inicio y final
        return trim($nombre, '_');
    }
    
    /**
     * Formatear fecha para mostrar
     */
    public static function formatearFecha($fecha, $formato = 'd/m/Y H:i') {
        if (empty($fecha)) return '-';
        
        try {
            $dateTime = new DateTime($fecha);
            return $dateTime->format($formato);
        } catch (Exception $e) {
            return $fecha;
        }
    }
    
    /**
     * Formatear notas según configuración (0-20 con 2 decimales)
     */
    public static function formatearNota($nota) {
        return number_format($nota, PRECISION_DECIMALES);
    }
    
    /**
     * Convertir porcentaje a escala 0-20
     */
    public static function convertirPorcentajeANota($porcentaje) {
        return ($porcentaje / 100) * NOTA_MAXIMA;
    }
    
    /**
     * Convertir nota 0-20 a porcentaje
     */
    public static function convertirNotaAPorcentaje($nota) {
        return ($nota / NOTA_MAXIMA) * 100;
    }
    
    /**
     * Calcular porcentaje
     */
    public static function calcularPorcentaje($parte, $total, $decimales = PRECISION_DECIMALES) {
        if ($total == 0) return 0;
        return round(($parte / $total) * 100, $decimales);
    }
    
    /**
     * Determinar el estado de una nota (escala 0-20)
     */
    public static function obtenerEstadoNota($nota) {
        if ($nota >= NOTA_EXCELENCIA) return 'excelente';
        if ($nota >= NOTA_MINIMA_APROBACION) return 'aprobado';
        return 'desaprobado';
    }
    
    /**
     * Obtener color CSS según el estado de la nota (escala 0-20)
     */
    public static function obtenerColorNota($nota) {
        $estado = self::obtenerEstadoNota($nota);
        switch ($estado) {
            case 'excelente':
                return '#00AA00'; // Verde oscuro (17-20)
            case 'aprobado':
                return '#0066CC'; // Azul (11-16.9)
            default:
                return '#CC0000'; // Rojo (0-10.9)
        }
    }
    
    /**
     * Generar mensaje de alerta HTML
     */
    public static function generarAlerta($mensaje, $tipo = 'info', $dismissible = true) {
        $iconos = [
            'success' => 'fa-check-circle',
            'info' => 'fa-info-circle',
            'warning' => 'fa-exclamation-triangle',
            'danger' => 'fa-times-circle'
        ];
        
        $icono = $iconos[$tipo] ?? $iconos['info'];
        $dismissibleClass = $dismissible ? ' alert-dismissible fade show' : '';
        $dismissibleButton = $dismissible ? '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>' : '';
        
        return "<div class='alert alert-{$tipo}{$dismissibleClass}' role='alert'>
                    <i class='fas {$icono} me-2'></i>
                    {$mensaje}
                    {$dismissibleButton}
                </div>";
    }
    
    /**
     * Convertir peso de categoría a porcentaje legible
     */
    public static function formatearPeso($peso) {
        return number_format($peso, 1) . '%';
    }
    
    /**
     * Verificar si el usuario tiene permisos de profesor en Classroom
     */
    public static function esProfesor($service, $courseId, $userId) {
        try {
            $teachers = $service->courses_teachers->listCoursesTeachers($courseId);
            foreach ($teachers->getTeachers() as $teacher) {
                if ($teacher->getUserId() === $userId) {
                    return true;
                }
            }
            return false;
        } catch (Exception $e) {
            escribirLog("Error verificando permisos de profesor: " . $e->getMessage(), 'ERROR');
            return false;
        }
    }
    
    /**
     * Obtener información del usuario actual desde Google
     */
    public static function obtenerUsuarioActual($service) {
        try {
            $userProfile = $service->userProfiles->get('me');
            return [
                'id' => $userProfile->getId(),
                'nombre' => $userProfile->getName()->getFullName(),
                'email' => $userProfile->getEmailAddress(),
                'foto' => $userProfile->getPhotoUrl()
            ];
        } catch (Exception $e) {
            escribirLog("Error obteniendo usuario actual: " . $e->getMessage(), 'ERROR');
            return null;
        }
    }
    
    /**
     * Validar que el peso total de categorías sea 100%
     */
    public static function validarPesosCategorias($categorias) {
        $pesoTotal = array_sum(array_column($categorias, 'peso'));
        $diferencia = abs(100 - $pesoTotal);
        
        return [
            'valido' => $diferencia <= 1, // Permitir margen de error de 1%
            'peso_total' => $pesoTotal,
            'diferencia' => 100 - $pesoTotal,
            'mensaje' => $diferencia <= 1 ? 
                'Los pesos están correctamente distribuidos' : 
                "Los pesos suman {$pesoTotal}%. Debe ser 100%."
        ];
    }
    
    /**
     * Generar estadísticas rápidas de un array de notas (escala 0-20)
     */
    public static function calcularEstadisticasNotas($notas) {
        if (empty($notas)) {
            return [
                'total' => 0,
                'promedio' => 0,
                'minima' => 0,
                'maxima' => 0,
                'aprobados' => 0,
                'desaprobados' => 0,
                'excelentes' => 0,
                'porcentaje_aprobacion' => 0,
                'porcentaje_excelencia' => 0
            ];
        }
        
        $total = count($notas);
        $suma = array_sum($notas);
        $promedio = $suma / $total;
        
        $aprobados = count(array_filter($notas, function($nota) {
            return $nota >= NOTA_MINIMA_APROBACION;
        }));
        
        $excelentes = count(array_filter($notas, function($nota) {
            return $nota >= NOTA_EXCELENCIA;
        }));
        
        return [
            'total' => $total,
            'promedio' => round($promedio, PRECISION_DECIMALES),
            'minima' => min($notas),
            'maxima' => max($notas),
            'aprobados' => $aprobados,
            'desaprobados' => $total - $aprobados,
            'excelentes' => $excelentes,
            'porcentaje_aprobacion' => self::calcularPorcentaje($aprobados, $total),
            'porcentaje_excelencia' => self::calcularPorcentaje($excelentes, $total)
        ];
    }
    
    /**
     * Formatear tiempo transcurrido (ej: "hace 2 horas")
     */
    public static function tiempoTranscurrido($fecha) {
        if (empty($fecha)) return '-';
        
        try {
            $ahora = new DateTime();
            $fechaDT = new DateTime($fecha);
            $diferencia = $ahora->diff($fechaDT);
            
            if ($diferencia->y > 0) return $diferencia->y . ' año' . ($diferencia->y > 1 ? 's' : '');
            if ($diferencia->m > 0) return $diferencia->m . ' mes' . ($diferencia->m > 1 ? 'es' : '');
            if ($diferencia->d > 0) return $diferencia->d . ' día' . ($diferencia->d > 1 ? 's' : '');
            if ($diferencia->h > 0) return $diferencia->h . ' hora' . ($diferencia->h > 1 ? 's' : '');
            if ($diferencia->i > 0) return $diferencia->i . ' minuto' . ($diferencia->i > 1 ? 's' : '');
            
            return 'hace un momento';
        } catch (Exception $e) {
            return $fecha;
        }
    }
    
    /**
     * Verificar si una cadena contiene solo números
     */
    public static function esNumerico($valor) {
        return is_numeric($valor) && $valor >= 0;
    }
    
    /**
     * Truncar texto a cierta longitud
     */
    public static function truncarTexto($texto, $longitud = 50, $sufijo = '...') {
        if (strlen($texto) <= $longitud) return $texto;
        return substr($texto, 0, $longitud) . $sufijo;
    }
    
    /**
     * Generar array de opciones para select HTML
     */
    public static function generarOpcionesSelect($items, $valueField, $textField, $selectedValue = null) {
        $opciones = '<option value="">-- Seleccionar --</option>';
        
        foreach ($items as $item) {
            $value = is_array($item) ? $item[$valueField] : $item->$valueField;
            $text = is_array($item) ? $item[$textField] : $item->$textField;
            $selected = ($value == $selectedValue) ? ' selected' : '';
            
            $opciones .= "<option value='" . self::sanitizar($value) . "'{$selected}>" . 
                        self::sanitizar($text) . "</option>";
        }
        
        return $opciones;
    }
    
    /**
     * Verificar si el sistema está en modo desarrollo
     */
    public static function esDesarrollo() {
        return defined('DESARROLLO') && DESARROLLO;
    }
    
    /**
     * Log de debug (solo en modo desarrollo)
     */
    public static function debug($mensaje, $datos = null) {
        if (self::esDesarrollo()) {
            $mensaje = "DEBUG: {$mensaje}";
            if ($datos !== null) {
                $mensaje .= " | Datos: " . json_encode($datos, JSON_PRETTY_PRINT);
            }
            escribirLog($mensaje, 'DEBUG');
        }
    }
    
    /**
     * Generar token CSRF simple
     */
    public static function generarTokenCSRF() {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
    
    /**
     * Verificar token CSRF
     */
    public static function verificarTokenCSRF($token) {
        return isset($_SESSION['csrf_token']) && 
               hash_equals($_SESSION['csrf_token'], $token);
    }
    
    /**
     * Generar campo hidden con token CSRF
     */
    public static function campoCSRF() {
        $token = self::generarTokenCSRF();
        return "<input type='hidden' name='csrf_token' value='{$token}'>";
    }
}

/**
 * Clase para manejo de respuestas JSON
 */
class JsonResponse {
    
    public static function success($mensaje = '', $datos = null) {
        $response = ['success' => true];
        
        if (!empty($mensaje)) {
            $response['message'] = $mensaje;
        }
        
        if ($datos !== null) {
            $response['data'] = $datos;
        }
        
        return json_encode($response);
    }
    
    public static function error($mensaje = 'Error interno', $codigo = null) {
        $response = [
            'success' => false,
            'message' => $mensaje
        ];
        
        if ($codigo !== null) {
            $response['error_code'] = $codigo;
        }
        
        return json_encode($response);
    }
    
    public static function enviar($respuesta) {
        header('Content-Type: application/json');
        echo $respuesta;
        exit;
    }
}

/**
 * Clase para manejo de archivos de exportación
 */
class ExportHelper {
    
    /**
     * Establecer headers para descarga de archivo
     */
    public static function establecerHeadersDescarga($nombreArchivo, $tipoMime) {
        header("Content-Type: {$tipoMime}");
        header("Content-Disposition: attachment; filename=\"{$nombreArchivo}\"");
        header('Cache-Control: must-revalidate');
        header('Expires: 0');
    }
    
    /**
     * Generar nombre de archivo para exportación
     */
    public static function generarNombreExport($curso, $seccion, $extension) {
        $nombre = Utils::limpiarNombreArchivo("Notas_{$seccion}_{$curso}");
        return Utils::generarNombreArchivo($nombre, $extension);
    }
}
?>