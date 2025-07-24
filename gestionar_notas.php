<?php
require_once 'database.php';
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['access_token'])) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$db = Database::getInstance();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';
    
    switch ($action) {
        case 'obtener_tareas_categoria':
            $categoria_id = $_GET['categoria_id'] ?? '';
            if ($categoria_id) {
                try {
                    $tareas = $db->obtenerTareasCategoria($categoria_id);
                    echo json_encode($tareas);
                } catch (Exception $e) {
                    echo json_encode(['error' => $e->getMessage()]);
                }
            } else {
                echo json_encode([]);
            }
            break;
            
        case 'calcular_promedios':
            // Método legacy mantenido para compatibilidad
            $course_id = $_GET['course_id'] ?? '';
            if ($course_id) {
                try {
                    $promedios = $db->calcularPromedios($course_id);
                    echo json_encode($promedios);
                } catch (Exception $e) {
                    echo json_encode(['error' => $e->getMessage()]);
                }
            } else {
                echo json_encode([]);
            }
            break;
            
        case 'obtener_promedios_ponderados':
            $course_id = $_GET['course_id'] ?? '';
            if ($course_id) {
                try {
                    $resultados = $db->calcularPromediosPonderados($course_id);
                    echo json_encode($resultados);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'ID de curso requerido']);
            }
            break;
            
        case 'verificar_categorias':
            $course_id = $_GET['course_id'] ?? '';
            if ($course_id) {
                try {
                    $existen = $db->existenCategorias($course_id);
                    $categorias = $db->obtenerCategoriasCurso($course_id);
                    echo json_encode([
                        'success' => true,
                        'existen_categorias' => $existen,
                        'categorias' => $categorias,
                        'total' => count($categorias)
                    ]);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'ID de curso requerido']);
            }
            break;
            
        case 'obtener_estadisticas':
            $course_id = $_GET['course_id'] ?? '';
            if ($course_id) {
                try {
                    $stats = $db->obtenerEstadisticasCurso($course_id);
                    echo json_encode(['success' => true, 'estadisticas' => $stats]);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'ID de curso requerido']);
            }
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Acción no válida']);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    
    switch ($action) {
        case 'asignar_tareas_categoria':
            $categoria_id = $input['categoria_id'] ?? '';
            $tareas = $input['tareas'] ?? [];
            
            if ($categoria_id) {
                try {
                    // Limpiar asignaciones existentes
                    $db->limpiarAsignacionesCategoria($categoria_id);
                    
                    // Asignar nuevas tareas
                    foreach ($tareas as $tarea_id) {
                        $db->asignarTareaCategoria($categoria_id, $tarea_id);
                    }
                    
                    echo json_encode([
                        'success' => true, 
                        'message' => 'Tareas asignadas correctamente',
                        'total_asignadas' => count($tareas)
                    ]);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'ID de categoría requerido']);
            }
            break;
            
        case 'actualizar_categoria':
            $categoria_id = $input['categoria_id'] ?? '';
            $peso = $input['peso'] ?? '';
            
            if ($categoria_id && is_numeric($peso)) {
                try {
                    $conn = $db->getConnection();
                    $stmt = $conn->prepare("UPDATE categorias SET peso = :peso WHERE id = :id");
                    $result = $stmt->execute([':peso' => $peso, ':id' => $categoria_id]);
                    
                    if ($result) {
                        echo json_encode(['success' => true, 'message' => 'Peso actualizado correctamente']);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Error al actualizar peso']);
                    }
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Datos incompletos o inválidos']);
            }
            break;
            
        case 'calcular_promedios_ponderados':
            $course_id = $input['course_id'] ?? '';
            
            if ($course_id) {
                try {
                    $resultados = $db->calcularPromediosPonderados($course_id);
                    
                    if (empty($resultados['estudiantes'])) {
                        echo json_encode([
                            'success' => false, 
                            'message' => 'No hay datos suficientes para calcular promedios'
                        ]);
                    } else {
                        echo json_encode([
                            'success' => true, 
                            'message' => 'Promedios calculados correctamente',
                            'total_estudiantes' => count($resultados['estudiantes']),
                            'total_categorias' => count($resultados['categorias'])
                        ]);
                    }
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'ID de curso requerido']);
            }
            break;
            
        case 'obtener_detalle_notas':
            $course_id = $input['course_id'] ?? '';
            
            if ($course_id) {
                try {
                    $conn = $db->getConnection();
                    
                    // Obtener estudiantes con sus notas detalladas
                    $sql = "SELECT 
                                e.id as estudiante_id,
                                e.nombre_completo,
                                e.email,
                                c.nombre as categoria_nombre,
                                c.peso,
                                t.titulo as tarea_titulo,
                                ne.nota,
                                ne.nota_maxima,
                                CASE 
                                    WHEN ne.nota_maxima > 0 THEN (ne.nota / ne.nota_maxima) * 20 
                                    ELSE 0 
                                END as nota_vigesimal
                            FROM estudiantes e
                            CROSS JOIN categorias c
                            LEFT JOIN categoria_tareas ct ON c.id = ct.categoria_id
                            LEFT JOIN tareas t ON ct.tarea_id = t.id
                            LEFT JOIN notas_estudiantes ne ON e.id = ne.estudiante_id 
                                AND t.id = ne.tarea_id 
                                AND ne.curso_id = :curso_id
                            WHERE e.curso_id = :curso_id 
                            AND c.curso_id = :curso_id
                            AND c.classroom_category_id IS NOT NULL
                            ORDER BY e.nombre_completo, c.nombre, t.titulo";
                    
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([':curso_id' => $course_id]);
                    $detalles = $stmt->fetchAll();
                    
                    echo json_encode(['success' => true, 'data' => $detalles]);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'ID de curso requerido']);
            }
            break;
            
        case 'limpiar_categorias_no_classroom':
            $course_id = $input['course_id'] ?? '';
            
            if ($course_id) {
                try {
                    $result = $db->limpiarCategoriasNoClasroom($course_id);
                    echo json_encode([
                        'success' => true, 
                        'message' => 'Categorías no vinculadas a Classroom eliminadas'
                    ]);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'ID de curso requerido']);
            }
            break;
            
        case 'validar_pesos_categorias':
            $course_id = $input['course_id'] ?? '';
            
            if ($course_id) {
                try {
                    $categorias = $db->obtenerCategoriasCurso($course_id);
                    $pesoTotal = array_sum(array_column($categorias, 'peso'));
                    
                    $validacion = [
                        'peso_total' => $pesoTotal,
                        'es_valido' => abs($pesoTotal - 100) <= 1, // Permitir margen de error de 1%
                        'categorias' => $categorias,
                        'diferencia' => 100 - $pesoTotal
                    ];
                    
                    echo json_encode(['success' => true, 'validacion' => $validacion]);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'ID de curso requerido']);
            }
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Acción no válida']);
    }
}
?>