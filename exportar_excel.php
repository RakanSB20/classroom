<?php
require_once 'vendor/autoload.php';
require_once 'database.php';
session_start();
date_default_timezone_set('America/Lima');

if (!isset($_SESSION['access_token'])) {
    header('Location: index.php');
    exit;
}

$client = new Google_Client();
$client->setAuthConfig('credentials.json');
$client->addScope([
    Google_Service_Classroom::CLASSROOM_COURSES_READONLY
]);
$client->setAccessToken($_SESSION['access_token']);

$service = new Google_Service_Classroom($client);
$db = Database::getInstance();

$courseId = $_GET['courseId'] ?? '';

if (!$courseId) {
    die('❌ ID de curso requerido');
}

try {
    // Obtener información del curso
    $curso = $service->courses->get($courseId);
    $nombreCurso = $curso->getName();
    $seccion = $curso->getSection() ?: 'Sin Sección';
    
    // Verificar si hay categorías configuradas
    $categorias = $db->obtenerCategoriasCurso($courseId);
    if (empty($categorias)) {
        die('❌ No hay categorías de evaluación configuradas en Google Classroom para este curso.');
    }
    
    // Preparar tabla completa con todas las tareas y notas
    $omitMin = isset($_GET['omit_min']) && $_GET['omit_min'] == '1';
    // Utilizamos el nuevo método para obtener la tabla completa, con o sin nota omitida
    $tabla = $db->calcularTablaCompleta($courseId, $omitMin ? 1 : 0);
    $categoriasTabla = $tabla['categorias'];
    $estudiantesTabla = $tabla['estudiantes'];
    if (empty($estudiantesTabla)) {
        die('❌ No hay datos de estudiantes o notas para exportar.');
    }

    // Estadísticas del curso
    $stats = $db->obtenerEstadisticasCurso($courseId);

    // Crear el archivo Excel usando PHPSpreadsheet
    if (class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')) {
        crearExcelCompleto($nombreCurso, $seccion, $categoriasTabla, $estudiantesTabla, $stats, $omitMin);
    } else {
        // En caso de que PHPSpreadsheet no esté instalado, crear un CSV de respaldo básico
        crearCSVBasico($nombreCurso, $seccion, $categoriasTabla, $estudiantesTabla, $stats, $omitMin);
    }
    
} catch (Exception $e) {
    // No imprimas nada antes de los headers de descarga
    die('❌ Error al generar archivo: ' . $e->getMessage());
}

/*
 * FUNCIONES AUXILIARES PARA GENERAR EXCEL Y CSV COMPLETOS
 * Estas funciones construyen un reporte de notas con todas las tareas por categoría,
 * calculan los promedios ponderados (opcionalmente omitiendo una nota global),
 * resaltan la nota omitida con un fondo gris, aplican colores según la escala
 * de evaluación y añaden una leyenda descriptiva. También generan una hoja
 * de estadísticas con información resumida del curso.
 */

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/**
 * Genera y descarga un archivo Excel con todas las notas y promedios, de forma dinámica.
 *
 * @param string $nombreCurso   Nombre del curso
 * @param string $seccion       Sección del curso
 * @param array  $categorias    Lista de categorías con sus tareas (formato de calcularTablaCompleta)
 * @param array  $estudiantes   Lista de estudiantes con notas, promedios y tarea omitida (formato de calcularTablaCompleta)
 * @param array  $stats         Estadísticas del curso (totales)
 * @param bool   $omitMin       Indica si se omitió la nota más baja global (para la leyenda)
 */
function crearExcelCompleto($nombreCurso, $seccion, $categorias, $estudiantes, $stats, $omitMin) {
    // Crear nuevo libro
    $spreadsheet = new Spreadsheet();
    $spreadsheet->removeSheetByIndex(0);
    // Hoja de notas
    $sheet = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($spreadsheet, 'Notas');
    $spreadsheet->addSheet($sheet, 0);

    // Configuración general de fuente
    $spreadsheet->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);

    $row = 1;
    // Título
    $sheet->setCellValue('A' . $row, 'REGISTRO DE NOTAS (Escala 0-20)');
    $sheet->mergeCells('A' . $row . ':E' . $row);
    $row++;
    // Curso y sección
    $sheet->setCellValue('A' . $row, $nombreCurso . ' - ' . $seccion);
    $sheet->mergeCells('A' . $row . ':E' . $row);
    $row++;
    // Fecha generación
    $sheet->setCellValue('A' . $row, 'Generado el: ' . date('d/m/Y H:i:s'));
    $sheet->mergeCells('A' . $row . ':E' . $row);
    $row += 2; // espacio en blanco

    // Calcular número de columnas dinámicamente
    // Columnas fijas: N° (A), Nombre (B)
    $colIndex = 3; // C es 3 (1-indexed)
    $categoryCols = [];
    foreach ($categorias as $cat) {
        $taskCount = isset($cat['tareas']) ? count($cat['tareas']) : 0;
        $categoryCols[$cat['id']] = [
            'start' => $colIndex,
            'tasks' => $taskCount,
            'end' => $colIndex + $taskCount // without promedio
        ];
        // Cada categoría ocupa sus tareas + 1 columna de promedio
        $colIndex += $taskCount + 1;
    }
    $finalCol = $colIndex; // promedio final

// Fila de cabecera 1: categorías y pesos
$headerRow1 = $row;

$sheet->setCellValue(
    \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(1) . $headerRow1,
    'N°'
);
$sheet->setCellValue(
    \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(2) . $headerRow1,
    'Nombre'
);

foreach ($categorias as $cat) {
    $cols     = $categoryCols[$cat['id']];
    $startCol = $cols['start'];
    $endCol   = $cols['start'] + $cols['tasks']; // tasks + promedio al final

    // Convertir índices de columna a letras (A, B, C, ...)
    $startLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($startCol);
    $endLetter   = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($endCol);

    // Unir celdas de la cabecera de la categoría
    $sheet->mergeCells($startLetter . $headerRow1 . ':' . $endLetter . $headerRow1);

    // Texto de la cabecera: nombre + peso
    $sheet->setCellValue(
        $startLetter . $headerRow1,
        $cat['nombre'] . "\n(" . $cat['peso'] . '%)'
    );
}

// Promedio final header
$sheet->setCellValue(
    \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($finalCol) . $headerRow1,
    'Prom. Final'
);

// Estilo de cabecera 1
$highestColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($finalCol);
$sheet->getStyle('A' . $headerRow1 . ':' . $highestColumn . $headerRow1)
      ->getFont()->setBold(true);
$sheet->getStyle('A' . $headerRow1 . ':' . $highestColumn . $headerRow1)
      ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('A' . $headerRow1 . ':' . $highestColumn . $headerRow1)
      ->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
$sheet->getRowDimension($headerRow1)->setRowHeight(30);

// Fila de cabecera 2: tareas por categoría + promedio
$headerRow2 = $headerRow1 + 1;

$sheet->setCellValue(
    \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(1) . $headerRow2,
    ''
);
$sheet->setCellValue(
    \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(2) . $headerRow2,
    ''
);

foreach ($categorias as $cat) {
    $cols      = $categoryCols[$cat['id']];
    $taskIndex = 1;

    // Tareas
    if (isset($cat['tareas']) && count($cat['tareas']) > 0) {
        foreach ($cat['tareas'] as $tarea) {
            $sheet->setCellValue(
                \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($cols['start']) . $headerRow2,
                'T' . $taskIndex
            );
            $cols['start']++;
            $taskIndex++;
        }
    }

    // Promedio
    $sheet->setCellValue(
        \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($cols['start']) . $headerRow2,
        'Prom'
    );
}

// Promedio final label row 2
$sheet->setCellValue(
    \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($finalCol) . $headerRow2,
    ''
);

// Estilo cabecera 2
$sheet->getStyle('A' . $headerRow2 . ':' . $highestColumn . $headerRow2)
      ->getFont()->setBold(true);
$sheet->getStyle('A' . $headerRow2 . ':' . $highestColumn . $headerRow2)
      ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getRowDimension($headerRow2)->setRowHeight(20);

// Rellenar datos de estudiantes
$currentRow = $headerRow2 + 1;

foreach ($estudiantes as $idx => $est) {
    // Columna 1: N°
    $sheet->setCellValue(
        \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(1) . $currentRow,
        $idx + 1
    );

    // Columna 2: Nombre
    $sheet->setCellValue(
        \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(2) . $currentRow,
        $est['nombre']
    );

    // Notas y promedios por categoría
    foreach ($categorias as $cat) {
        $cols      = $categoryCols[$cat['id']];
        $start     = $cols['start'];
        $taskCount = $cols['tasks'];
        $taskIndex = 0;
            if (isset($cat['tareas']) && count($cat['tareas']) > 0) {
                foreach ($cat['tareas'] as $tarea) {
                    $nota = isset($est['notas_tareas'][$tarea['id']]) ? $est['notas_tareas'][$tarea['id']] : 0;
                    $cellCol = $start + $taskIndex;
                    $cellAddr = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($cellCol) . $currentRow;
                    // Establecer la nota con dos decimales
                    $sheet->setCellValue($cellAddr, number_format($nota, 2));
                    // Color según nota
                    $fillColor = getColorForGrade($nota);
                    $sheet->getStyle($cellAddr)->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF' . substr($fillColor, 2)));
                    // Si es nota omitida
                    if ($omitMin && $est['tarea_omitida'] && $est['tarea_omitida'] == $tarea['id']) {
                        $sheet->getStyle($cellAddr)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFD9D9D9');
                    }
                    $taskIndex++;
                }
            }
            // Promedio de la categoría
            $promCat = isset($est['promedios_categoria'][$cat['id']]) ? $est['promedios_categoria'][$cat['id']] : 0;
            $promCol = $cols['start'] + $taskCount; // last col for this category
            $promAddr = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($promCol) . $currentRow;
            $sheet->setCellValue($promAddr, number_format($promCat, 2));
            // Color para promedio de categoría
            $fillColor = getColorForGrade($promCat);
            $sheet->getStyle($promAddr)->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF' . substr($fillColor, 2)));
        }
        // Promedio final
        $promFinal = isset($est['promedio_final']) ? $est['promedio_final'] : 0;
        $finalAddr = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($finalCol) . $currentRow;
        $sheet->setCellValue($finalAddr, number_format($promFinal, 2));
        $fillColor = getColorForGrade($promFinal);
        $sheet->getStyle($finalAddr)->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF' . substr($fillColor, 2)));
        $currentRow++;
    }

    // Ajustar ancho de columnas automáticamente
    // Usamos stringFromColumnIndex para obtener la letra de columna, ya que algunas versiones
    // de PhpSpreadsheet no implementan getColumnDimensionByColumn().
    for ($col = 1; $col <= $finalCol; $col++) {
        $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
        $sheet->getColumnDimension($colLetter)->setAutoSize(true);
    }

    // Leyenda debajo de la tabla
    $legendRow = $currentRow + 1;
    $sheet->setCellValue('A' . $legendRow, 'Escala de evaluación:');
    $sheet->setCellValue('B' . $legendRow, '17-20 Excelente');
    $sheet->setCellValue('C' . $legendRow, '11-16 Aprobado');
    $sheet->setCellValue('D' . $legendRow, '0-10 Desaprobado');
    $legendRow++;
    if ($omitMin) {
        $sheet->setCellValue('A' . $legendRow, 'Celda gris: Nota omitida (no se considera en el cálculo)');
    }

    // Hoja de estadísticas
    $statsSheet = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($spreadsheet, 'Estadísticas');
    $spreadsheet->addSheet($statsSheet, 1);
    $statsSheet->setCellValue('A1', 'ESTADÍSTICAS DEL CURSO');
    $statsSheet->getStyle('A1')->getFont()->setBold(true);
    $statsSheet->setCellValue('A3', 'Total Estudiantes');
    $statsSheet->setCellValue('B3', $stats['total_estudiantes']);
    $statsSheet->setCellValue('A4', 'Total Tareas');
    $statsSheet->setCellValue('B4', $stats['total_tareas']);
    $statsSheet->setCellValue('A5', 'Total Categorías');
    $statsSheet->setCellValue('B5', $stats['total_categorias']);
    $statsSheet->setCellValue('A6', 'Total Notas');
    $statsSheet->setCellValue('B6', $stats['total_notas']);
    // Distribución de promedios
    $excelentes = 0; $aprobados = 0; $desaprobados = 0;
    foreach ($estudiantes as $est) {
        $p = isset($est['promedio_final']) ? $est['promedio_final'] : 0;
        if ($p >= 17) $excelentes++;
        elseif ($p >= 11) $aprobados++;
        else $desaprobados++;
    }
    $statsSheet->setCellValue('A8', 'Distribución de promedios (Escala 0-20)');
    $statsSheet->setCellValue('A9', 'Excelente (17-20)');
    $statsSheet->setCellValue('B9', $excelentes);
    $statsSheet->setCellValue('A10', 'Aprobado (11-16)');
    $statsSheet->setCellValue('B10', $aprobados);
    $statsSheet->setCellValue('A11', 'Desaprobado (0-10)');
    $statsSheet->setCellValue('B11', $desaprobados);
    // Ajustar ancho en hoja de estadísticas
    $statsSheet->getColumnDimension('A')->setAutoSize(true);
    $statsSheet->getColumnDimension('B')->setAutoSize(true);

    // Salida
    // Limpiar buffer antes de enviar
    if (ob_get_length()) { ob_end_clean(); }
    $filename = 'Registro_Notas_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $nombreCurso . '_' . $seccion) . '_' . date('Y-m-d') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0, must-revalidate');
    header('Pragma: public');
    header('Expires: 0');
    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    $spreadsheet->disconnectWorksheets();
    unset($writer, $spreadsheet);
    exit;
}

/**
 * Genera un archivo CSV básico con todas las notas y promedios. No aplica estilos ni colores.
 * @param string $nombreCurso
 * @param string $seccion
 * @param array  $categorias
 * @param array  $estudiantes
 * @param array  $stats
 * @param bool   $omitMin
 */
function crearCSVBasico($nombreCurso, $seccion, $categorias, $estudiantes, $stats, $omitMin) {
    $filename = 'Registro_Notas_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $nombreCurso . '_' . $seccion) . '_' . date('Y-m-d') . '.csv';
    // Limpiar buffer y cabeceras
    if (ob_get_length()) { ob_end_clean(); }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Pragma: public');
    header('Expires: 0');

    $output = fopen('php://output', 'w');
    // BOM para UTF-8
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    // Títulos
    fputcsv($output, ['REGISTRO DE NOTAS (Escala 0-20)']);
    fputcsv($output, [$nombreCurso . ' - ' . $seccion]);
    fputcsv($output, ['Generado el: ' . date('d/m/Y H:i:s')]);
    fputcsv($output, []);
    // Cabeceras: N°, Nombre, tareas por categoría, promedio por categoría, Promedio final
    $headers = ['N°', 'Nombre'];
    foreach ($categorias as $cat) {
        // Para cada tarea
        if (isset($cat['tareas']) && count($cat['tareas']) > 0) {
            $idx = 1;
            foreach ($cat['tareas'] as $tarea) {
                $headers[] = $cat['nombre'] . ' T' . $idx;
                $idx++;
            }
        }
        $headers[] = $cat['nombre'] . ' Prom';
    }
    $headers[] = 'Promedio Final';
    fputcsv($output, $headers);
    // Datos
    foreach ($estudiantes as $i => $est) {
        $row = [$i + 1, $est['nombre']];
        foreach ($categorias as $cat) {
            if (isset($cat['tareas']) && count($cat['tareas']) > 0) {
                foreach ($cat['tareas'] as $tarea) {
                    $nota = isset($est['notas_tareas'][$tarea['id']]) ? $est['notas_tareas'][$tarea['id']] : 0;
                    $row[] = number_format($nota, 2);
                }
            }
            $promCat = isset($est['promedios_categoria'][$cat['id']]) ? $est['promedios_categoria'][$cat['id']] : 0;
            $row[] = number_format($promCat, 2);
        }
        $row[] = number_format($est['promedio_final'], 2);
        fputcsv($output, $row);
    }
    // Leyenda al final (texto plano)
    fputcsv($output, []);
    $leyenda = 'Escala de evaluación: 17-20 Excelente | 11-16 Aprobado | 0-10 Desaprobado';
    if ($omitMin) {
        $leyenda .= ' | (Nota omitida indicada en la tabla)';
    }
    fputcsv($output, [$leyenda]);
    // Estadísticas
    fputcsv($output, []);
    fputcsv($output, ['ESTADÍSTICAS DEL CURSO']);
    fputcsv($output, ['Total Estudiantes', $stats['total_estudiantes']]);
    fputcsv($output, ['Total Tareas', $stats['total_tareas']]);
    fputcsv($output, ['Total Categorías', $stats['total_categorias']]);
    fputcsv($output, ['Total Notas', $stats['total_notas']]);
    // Distribución de promedios
    $excelentes = 0; $aprobados = 0; $desaprobados = 0;
    foreach ($estudiantes as $est) {
        $p = isset($est['promedio_final']) ? $est['promedio_final'] : 0;
        if ($p >= 17) $excelentes++;
        elseif ($p >= 11) $aprobados++;
        else $desaprobados++;
    }
    fputcsv($output, ['Excelente (17-20)', $excelentes]);
    fputcsv($output, ['Aprobado (11-16)', $aprobados]);
    fputcsv($output, ['Desaprobado (0-10)', $desaprobados]);
    fclose($output);
    exit;
}

function getColorForGrade($grade) {
    if ($grade >= 18) return 'FF00AA00'; // Verde oscuro - Excelente
    if ($grade >= 16) return 'FF008000'; // Verde - Muy bueno
    if ($grade >= 14) return 'FF0066CC'; // Azul - Bueno
    if ($grade >= 11) return 'FFFF8C00'; // Naranja - Regular (aprobado)
    if ($grade >= 8)  return 'FFFF6600'; // Naranja oscuro - Malo
    return 'FFCC0000'; // Rojo - Muy malo (desaprobado)
}
