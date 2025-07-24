<?php
require_once 'vendor/autoload.php';
require_once 'database.php';
session_start();

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
    
    // Obtener datos para el Excel
    $conn = $db->getConnection();
    
    // Obtener categorías
    $categorias = $db->obtenerCategoriasCurso($courseId);
    
    // Obtener estudiantes con sus promedios
    $sql = "SELECT DISTINCT
                e.id,
                e.nombre_completo,
                e.email
            FROM estudiantes e
            WHERE e.curso_id = :curso_id
            ORDER BY e.nombre_completo";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([':curso_id' => $courseId]);
    $estudiantes = $stmt->fetchAll();
    
    // Calcular promedios por estudiante y categoría
    $promedios = [];
    foreach ($estudiantes as $estudiante) {
        $promedios[$estudiante['id']] = [
            'nombre' => $estudiante['nombre_completo'],
            'email' => $estudiante['email'],
            'categorias' => [],
            'promedio_final' => 0
        ];
        
        $promedioFinal = 0;
        foreach ($categorias as $categoria) {
            // Calcular promedio de la categoría para este estudiante
            $sqlPromedio = "SELECT 
                                AVG(CASE WHEN ne.nota_maxima > 0 THEN (ne.nota / ne.nota_maxima) * 100 ELSE 0 END) as promedio_categoria
                            FROM categoria_tareas ct
                            INNER JOIN notas_estudiantes ne ON ct.tarea_id = ne.tarea_id
                            WHERE ct.categoria_id = :categoria_id 
                            AND ne.estudiante_id = :estudiante_id 
                            AND ne.curso_id = :curso_id";
            
            $stmtPromedio = $conn->prepare($sqlPromedio);
            $stmtPromedio->execute([
                ':categoria_id' => $categoria['id'],
                ':estudiante_id' => $estudiante['id'],
                ':curso_id' => $courseId
            ]);
            
            $resultPromedio = $stmtPromedio->fetch();
            $promedioCategoria = $resultPromedio['promedio_categoria'] ?? 0;
            
            $promedios[$estudiante['id']]['categorias'][$categoria['nombre']] = $promedioCategoria;
            $promedioFinal += ($promedioCategoria * $categoria['peso']) / 100;
        }
        
        $promedios[$estudiante['id']]['promedio_final'] = $promedioFinal;
    }
    
    // Crear el archivo Excel usando PHPSpreadsheet
    if (!class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')) {
        // Si PHPSpreadsheet no está disponible, crear CSV
        crearCSV($nombreCurso, $seccion, $categorias, $promedios);
    } else {
        crearExcel($nombreCurso, $seccion, $categorias, $promedios);
    }
    
} catch (Exception $e) {
    die('❌ Error al generar archivo: ' . $e->getMessage());
}

function crearCSV($nombreCurso, $seccion, $categorias, $promedios) {
    $filename = "Registro de notas {$seccion} {$nombreCurso}.csv";
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Expires: 0');
    
    $output = fopen('php://output', 'w');
    
    // Escribir BOM para UTF-8
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Título
    fputcsv($output, ["Registro de Notas - {$nombreCurso} ({$seccion})"]);
    fputcsv($output, []);
    
    // Encabezados
    $headers = ['Estudiante', 'Email'];
    foreach ($categorias as $categoria) {
        $headers[] = $categoria['nombre'] . ' (' . $categoria['peso'] . '%)';
    }
    $headers[] = 'Promedio Final';
    fputcsv($output, $headers);
    
    // Datos de estudiantes
    foreach ($promedios as $promedio) {
        $row = [$promedio['nombre'], $promedio['email']];
        foreach ($categorias as $categoria) {
            $row[] = number_format($promedio['categorias'][$categoria['nombre']] ?? 0, 2);
        }
        $row[] = number_format($promedio['promedio_final'], 2);
        fputcsv($output, $row);
    }
    
    fclose($output);
}

function crearExcel($nombreCurso, $seccion, $categorias, $promedios) {
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    // Configurar título
    $totalCols = 2 + count($categorias); // Estudiante, Email + categorías
    $lastColLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($totalCols + 1);
    
    $sheet->setCellValue('A1', "Registro de Notas - {$nombreCurso} ({$seccion})");
    $sheet->mergeCells("A1:{$lastColLetter}1");
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

    // Encabezados en fila 3
    $currentCol = 1;
    $row = 3;
    $sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($currentCol++) . $row, 'Estudiante');
    $sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($currentCol++) . $row, 'Email');
    
    foreach ($categorias as $categoria) {
        $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($currentCol++);
        $sheet->setCellValue($colLetter . $row, $categoria['nombre'] . ' (' . $categoria['peso'] . '%)');
    }

    $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($currentCol);
    $sheet->setCellValue($colLetter . $row, 'Promedio Final');

    // Estilo de encabezados
    $sheet->getStyle("A3:{$colLetter}3")->getFont()->setBold(true);
    $sheet->getStyle("A3:{$colLetter}3")->getFill()
        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
        ->getStartColor()->setARGB('FFE2E2E2');

    // Datos de estudiantes
    $row = 4;
    foreach ($promedios as $promedio) {
        $currentCol = 1;
        $colLet = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($currentCol++);
        $sheet->setCellValue($colLet . $row, $promedio['nombre']);
        
        $colLet = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($currentCol++);
        $sheet->setCellValue($colLet . $row, $promedio['email']);

        foreach ($categorias as $categoria) {
            $valor = $promedio['categorias'][$categoria['nombre']] ?? 0;
            $colLet = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($currentCol++);
            $sheet->setCellValue($colLet . $row, round($valor, 2));
        }

        $colLet = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($currentCol);
        $sheet->setCellValue($colLet . $row, round($promedio['promedio_final'], 2));
        $row++;
    }

    // Ajustar ancho de columnas
    for ($i = 1; $i <= $currentCol; $i++) {
        $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
        $sheet->getColumnDimension($letter)->setAutoSize(true);
    }

    // Crear archivo y descargar
    $filename = "Registro de notas Grupo{$seccion} {$nombreCurso}.xlsx";
    
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: must-revalidate');
    header('Expires: 0');

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
}
?>