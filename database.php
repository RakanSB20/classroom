<?php
class Database {
    private static $instance = null;
    private $connection;
    
    private $host = 'localhost';
    private $username = 'root';
    private $password = '';
    private $database = 'classroom_notas';
    
    private function __construct() {
        try {
            $this->connection = new PDO(
                "mysql:host={$this->host};dbname={$this->database};charset=utf8mb4",
                $this->username,
                $this->password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch (PDOException $e) {
            die("Error de conexión a la base de datos: " . $e->getMessage());
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->connection;
    }
    
    // Método para sincronizar curso desde Classroom
    public function sincronizarCurso($curso) {
        $sql = "INSERT INTO cursos (id, nombre, seccion, aula) 
                VALUES (:id, :nombre, :seccion, :aula) 
                ON DUPLICATE KEY UPDATE 
                nombre = VALUES(nombre), 
                seccion = VALUES(seccion), 
                aula = VALUES(aula)";
        
        $stmt = $this->connection->prepare($sql);
        return $stmt->execute([
            ':id' => $curso['id'],
            ':nombre' => $curso['nombre'],
            ':seccion' => $curso['seccion'] ?? '',
            ':aula' => $curso['aula'] ?? ''
        ]);
    }
    
    // Método MEJORADO para sincronizar categorías desde Classroom
    public function sincronizarCategoria($categoria) {
        $sql = "INSERT INTO categorias (curso_id, classroom_category_id, nombre, peso) 
                VALUES (:curso_id, :classroom_category_id, :nombre, :peso) 
                ON DUPLICATE KEY UPDATE 
                nombre = VALUES(nombre), 
                peso = VALUES(peso)";
        
        $stmt = $this->connection->prepare($sql);
        return $stmt->execute([
            ':curso_id' => $categoria['curso_id'],
            ':classroom_category_id' => $categoria['classroom_category_id'],
            ':nombre' => $categoria['nombre'],
            ':peso' => $categoria['peso'] ?? 0
        ]);
    }
    
    // Método para sincronizar tareas desde Classroom
    public function sincronizarTarea($tarea) {
        $sql = "INSERT INTO tareas (id, curso_id, titulo, descripcion, puntos_max) 
                VALUES (:id, :curso_id, :titulo, :descripcion, :puntos_max) 
                ON DUPLICATE KEY UPDATE 
                titulo = VALUES(titulo), 
                descripcion = VALUES(descripcion), 
                puntos_max = VALUES(puntos_max)";
        
        $stmt = $this->connection->prepare($sql);
        return $stmt->execute([
            ':id' => $tarea['id'],
            ':curso_id' => $tarea['curso_id'],
            ':titulo' => $tarea['titulo'],
            ':descripcion' => $tarea['descripcion'] ?? '',
            ':puntos_max' => $tarea['puntos_max'] ?? 0
        ]);
    }
    
    // NUEVO: Método para asignar tarea a categoría usando Classroom ID
    public function asignarTareaCategoriaByClassroomId($curso_id, $classroom_category_id, $tarea_id) {
        $sql = "INSERT IGNORE INTO categoria_tareas (categoria_id, tarea_id) 
                SELECT c.id, :tarea_id 
                FROM categorias c 
                WHERE c.curso_id = :curso_id 
                AND c.classroom_category_id = :classroom_category_id";
        
        $stmt = $this->connection->prepare($sql);
        return $stmt->execute([
            ':curso_id' => $curso_id,
            ':classroom_category_id' => $classroom_category_id,
            ':tarea_id' => $tarea_id
        ]);
    }
    
    // Método para obtener categorías de un curso (SOLO las que existen en Classroom)
    public function obtenerCategoriasCurso($curso_id) {
        $sql = "SELECT * FROM categorias 
                WHERE curso_id = :curso_id 
                AND classroom_category_id IS NOT NULL 
                ORDER BY nombre";
        $stmt = $this->connection->prepare($sql);
        $stmt->execute([':curso_id' => $curso_id]);
        return $stmt->fetchAll();
    }
    
    // Método para obtener tareas de un curso
    public function obtenerTareasCurso($curso_id) {
        $sql = "SELECT * FROM tareas WHERE curso_id = :curso_id ORDER BY titulo";
        $stmt = $this->connection->prepare($sql);
        $stmt->execute([':curso_id' => $curso_id]);
        return $stmt->fetchAll();
    }
    
    // Método para obtener tareas de una categoría
    public function obtenerTareasCategoria($categoria_id) {
        $sql = "SELECT t.* FROM tareas t 
                INNER JOIN categoria_tareas ct ON t.id = ct.tarea_id 
                WHERE ct.categoria_id = :categoria_id 
                ORDER BY t.titulo";
        $stmt = $this->connection->prepare($sql);
        $stmt->execute([':categoria_id' => $categoria_id]);
        return $stmt->fetchAll();
    }
    
    // Método para asignar tarea a categoría
    public function asignarTareaCategoria($categoria_id, $tarea_id) {
        $sql = "INSERT IGNORE INTO categoria_tareas (categoria_id, tarea_id) 
                VALUES (:categoria_id, :tarea_id)";
        $stmt = $this->connection->prepare($sql);
        return $stmt->execute([
            ':categoria_id' => $categoria_id,
            ':tarea_id' => $tarea_id
        ]);
    }
    
    // Método para quitar tarea de categoría
    public function quitarTareaCategoria($categoria_id, $tarea_id) {
        $sql = "DELETE FROM categoria_tareas 
                WHERE categoria_id = :categoria_id AND tarea_id = :tarea_id";
        $stmt = $this->connection->prepare($sql);
        return $stmt->execute([
            ':categoria_id' => $categoria_id,
            ':tarea_id' => $tarea_id
        ]);
    }
    
    // Método para limpiar asignaciones de una categoría
    public function limpiarAsignacionesCategoria($categoria_id) {
        $sql = "DELETE FROM categoria_tareas WHERE categoria_id = :categoria_id";
        $stmt = $this->connection->prepare($sql);
        return $stmt->execute([':categoria_id' => $categoria_id]);
    }
    
    // Método para sincronizar nota de estudiante
    public function sincronizarNota($nota) {
        $sql = "INSERT INTO notas_estudiantes 
                (curso_id, estudiante_id, tarea_id, nota, nota_maxima) 
                VALUES (:curso_id, :estudiante_id, :tarea_id, :nota, :nota_maxima) 
                ON DUPLICATE KEY UPDATE 
                nota = VALUES(nota), 
                nota_maxima = VALUES(nota_maxima)";
        
        $stmt = $this->connection->prepare($sql);
        return $stmt->execute([
            ':curso_id' => $nota['curso_id'],
            ':estudiante_id' => $nota['estudiante_id'],
            ':tarea_id' => $nota['tarea_id'],
            ':nota' => $nota['nota'],
            ':nota_maxima' => $nota['nota_maxima']
        ]);
    }
    
    // NUEVO: Método mejorado para calcular promedios ponderados reales (escala 0-20)
    public function calcularPromediosPonderados($curso_id) {
        try {
            // Obtener todos los estudiantes del curso
            // Seleccionar solo estudiantes activos para evitar incluir eliminados
            $sqlEstudiantes = "SELECT DISTINCT id, nombre_completo, email 
                              FROM estudiantes 
                              WHERE curso_id = :curso_id 
                              AND (activo = 1 OR activo IS NULL) 
                              ORDER BY nombre_completo";
            $stmtEstudiantes = $this->connection->prepare($sqlEstudiantes);
            $stmtEstudiantes->execute([':curso_id' => $curso_id]);
            $estudiantes = $stmtEstudiantes->fetchAll();
            
            // Obtener categorías del curso
            $categorias = $this->obtenerCategoriasCurso($curso_id);
            
            if (empty($categorias)) {
                return ['estudiantes' => [], 'mensaje' => 'No hay categorías configuradas'];
            }
            
            $resultados = [];
            
            foreach ($estudiantes as $estudiante) {
                $estudianteData = [
                    'id' => $estudiante['id'],
                    'nombre' => $estudiante['nombre_completo'],
                    'email' => $estudiante['email'],
                    'promedios_categoria' => [],
                    'promedio_final' => 0
                ];
                
                $promedioFinal = 0;
                $pesoTotalUsado = 0;
                
                foreach ($categorias as $categoria) {
                    // Calcular promedio de la categoría para este estudiante
                    $sqlPromedio = "SELECT 
                                        AVG(CASE 
                                            WHEN ne.nota_maxima > 0 THEN (ne.nota / ne.nota_maxima) * 20
                                            ELSE 0 
                                        END) as promedio_categoria,
                                        COUNT(ne.nota) as cantidad_notas
                                    FROM categoria_tareas ct
                                    INNER JOIN notas_estudiantes ne ON ct.tarea_id = ne.tarea_id
                                    WHERE ct.categoria_id = :categoria_id 
                                    AND ne.estudiante_id = :estudiante_id 
                                    AND ne.curso_id = :curso_id
                                    AND ne.nota IS NOT NULL";
                    
                    $stmtPromedio = $this->connection->prepare($sqlPromedio);
                    $stmtPromedio->execute([
                        ':categoria_id' => $categoria['id'],
                        ':estudiante_id' => $estudiante['id'],
                        ':curso_id' => $curso_id
                    ]);
                    
                    $resultPromedio = $stmtPromedio->fetch();
                    $promedioCategoria = $resultPromedio['promedio_categoria'] ?? 0;
                    $cantidadNotas = $resultPromedio['cantidad_notas'] ?? 0;
                    
                    // Redondear a 2 decimales
                    $promedioCategoria = round($promedioCategoria, 2);
                    
                    // Solo incluir la categoría si tiene notas
                    if ($cantidadNotas > 0) {
                        $estudianteData['promedios_categoria'][$categoria['id']] = $promedioCategoria;
                        $promedioFinal += ($promedioCategoria * $categoria['peso']) / 100;
                        $pesoTotalUsado += $categoria['peso'];
                    } else {
                        $estudianteData['promedios_categoria'][$categoria['id']] = 0;
                    }
                }
                
                $estudianteData['promedio_final'] = round($promedioFinal, 2);
                $resultados[] = $estudianteData;
            }
            
            return ['estudiantes' => $resultados, 'categorias' => $categorias];
            
        } catch (Exception $e) {
            throw new Exception("Error al calcular promedios ponderados: " . $e->getMessage());
        }
    }
    
    // Método LEGACY mantenido para compatibilidad (escala 0-20)
    public function calcularPromedios($curso_id) {
        $sql = "SELECT 
                    e.id as estudiante_id,
                    e.nombre_completo,
                    e.email,
                    c.id as categoria_id,
                    c.nombre as categoria_nombre,
                    c.peso,
                    AVG(CASE 
                        WHEN ne.nota_maxima > 0 THEN (ne.nota / ne.nota_maxima) * 20
                        ELSE 0 
                    END) as promedio_categoria
                FROM estudiantes e
                CROSS JOIN categorias c
                LEFT JOIN categoria_tareas ct ON c.id = ct.categoria_id
                LEFT JOIN notas_estudiantes ne ON e.id = ne.estudiante_id 
                    AND ct.tarea_id = ne.tarea_id 
                    AND ne.curso_id = :curso_id
                WHERE e.curso_id = :curso_id 
                AND c.curso_id = :curso_id
                AND c.classroom_category_id IS NOT NULL
                GROUP BY e.id, c.id
                ORDER BY e.nombre_completo, c.nombre";
        
        $stmt = $this->connection->prepare($sql);
        $stmt->execute([':curso_id' => $curso_id]);
        return $stmt->fetchAll();
    }

    // NUEVO: Método para calcular promedios ponderados excluyendo la nota más baja en cada categoría (si hay más de una)
    public function calcularPromediosPonderadosSinMenor($curso_id) {
        try {
            // Obtener todos los estudiantes del curso
            // Seleccionar solo estudiantes activos para evitar incluir eliminados
            $sqlEstudiantes = "SELECT DISTINCT id, nombre_completo, email 
                              FROM estudiantes 
                              WHERE curso_id = :curso_id 
                              AND (activo = 1 OR activo IS NULL) 
                              ORDER BY nombre_completo";
            $stmtEstudiantes = $this->connection->prepare($sqlEstudiantes);
            $stmtEstudiantes->execute([':curso_id' => $curso_id]);
            $estudiantes = $stmtEstudiantes->fetchAll();

            // Obtener categorías del curso
            $categorias = $this->obtenerCategoriasCurso($curso_id);
            if (empty($categorias)) {
                return ['estudiantes' => [], 'categorias' => $categorias];
            }

            $resultados = [];

            foreach ($estudiantes as $estudiante) {
                $estudianteData = [
                    'id' => $estudiante['id'],
                    'nombre' => $estudiante['nombre_completo'],
                    'email' => $estudiante['email'],
                    'promedios_categoria' => [],
                    'promedio_final' => 0
                ];
                $promedioFinal = 0;

                foreach ($categorias as $categoria) {
                    // Obtener todas las notas vigesimales de la categoría para este estudiante
                    $sqlNotas = "SELECT 
                                    (CASE 
                                        WHEN ne.nota_maxima > 0 THEN (ne.nota / ne.nota_maxima) * 20 
                                        ELSE 0 
                                    END) as nota_vigesimal
                                 FROM categoria_tareas ct
                                 INNER JOIN notas_estudiantes ne ON ct.tarea_id = ne.tarea_id
                                 WHERE ct.categoria_id = :categoria_id 
                                 AND ne.estudiante_id = :estudiante_id 
                                 AND ne.curso_id = :curso_id
                                 AND ne.nota IS NOT NULL";
                    $stmtNotas = $this->connection->prepare($sqlNotas);
                    $stmtNotas->execute([
                        ':categoria_id' => $categoria['id'],
                        ':estudiante_id' => $estudiante['id'],
                        ':curso_id' => $curso_id
                    ]);
                    $filasNotas = $stmtNotas->fetchAll();

                    $notas = [];
                    foreach ($filasNotas as $fila) {
                        $notaVal = $fila['nota_vigesimal'];
                        if ($notaVal !== null) {
                            $notas[] = (float)$notaVal;
                        }
                    }

                    if (count($notas) > 1) {
                        // Eliminar la nota más baja
                        sort($notas); // orden ascendente
                        array_shift($notas); // quitar el primer elemento (mínimo)
                    }

                    if (count($notas) > 0) {
                        $promedioCat = array_sum($notas) / count($notas);
                    } else {
                        $promedioCat = 0;
                    }
                    $promedioCat = round($promedioCat, 2);

                    $estudianteData['promedios_categoria'][$categoria['id']] = $promedioCat;
                    $promedioFinal += ($promedioCat * $categoria['peso']) / 100;
                }

                $estudianteData['promedio_final'] = round($promedioFinal, 2);
                $resultados[] = $estudianteData;
            }

            return ['estudiantes' => $resultados, 'categorias' => $categorias];

        } catch (Exception $e) {
            throw new Exception("Error al calcular promedios ponderados sin menor: " . $e->getMessage());
        }
    }

    /**
     * NUEVO: Calcula una tabla completa de notas para un curso. La tabla incluye todas las tareas
     * organizadas por categorías y, para cada estudiante, la nota obtenida en cada tarea (escala 0-20),
     * el promedio por categoría y el promedio ponderado final.  Si $omitir_menor es 1 se eliminará la
     * nota más baja de todas las tareas de un estudiante, pero solamente de las categorías que tengan
     * al menos 2 tareas asignadas.  La tarea eliminada queda identificada en el campo
     * 'tarea_omitida' de cada estudiante para poder resaltar la celda en la vista.
     *
     * @param string $curso_id ID del curso de Google Classroom
     * @param int    $omitir_menor 0 = no omitir nota, 1 = omitir la nota más baja global
     * @return array  Estructura con claves 'categorias' (lista de categorías con sus tareas) y
     *                'estudiantes' (notas por tarea, promedios y tarea omitida)
     */
    public function calcularTablaCompleta($curso_id, $omitir_menor = 0) {
        try {
            // Obtener categorías del curso (solo aquellas con classroom_category_id para evitar categorías manuales)
            $categorias = $this->obtenerCategoriasCurso($curso_id);
            if (empty($categorias)) {
                return ['categorias' => [], 'estudiantes' => []];
            }

            // Obtener tareas por categoría
            $tareasPorCategoria = [];
            $tareasCountByCategoria = [];
            foreach ($categorias as $categoria) {
                $lista = $this->obtenerTareasCategoria($categoria['id']);
                $tareasPorCategoria[$categoria['id']] = $lista;
                $tareasCountByCategoria[$categoria['id']] = count($lista);
            }

            // Obtener todos los estudiantes activos del curso
            $sqlEstudiantes = "SELECT id, nombre_completo, email
                               FROM estudiantes
                               WHERE curso_id = :curso_id
                               AND (activo = 1 OR activo IS NULL)
                               ORDER BY nombre_completo";
            $stmtEstudiantes = $this->connection->prepare($sqlEstudiantes);
            $stmtEstudiantes->execute([':curso_id' => $curso_id]);
            $estudiantes = $stmtEstudiantes->fetchAll();
            if (empty($estudiantes)) {
                return ['categorias' => $categorias, 'estudiantes' => []];
            }

            // Obtener todas las notas de estudiantes para el curso en un solo query
            $sqlNotas = "SELECT estudiante_id, tarea_id, nota, nota_maxima
                         FROM notas_estudiantes
                         WHERE curso_id = :curso_id";
            $stmtNotas = $this->connection->prepare($sqlNotas);
            $stmtNotas->execute([':curso_id' => $curso_id]);
            $notasFilas = $stmtNotas->fetchAll();
            // Organizar notas en un array indexado por estudiante y tarea
            $notasPorEstudiante = [];
            foreach ($notasFilas as $fila) {
                $estId = $fila['estudiante_id'];
                $tarId = $fila['tarea_id'];
                if (!isset($notasPorEstudiante[$estId])) {
                    $notasPorEstudiante[$estId] = [];
                }
                $notasPorEstudiante[$estId][$tarId] = [
                    'nota' => $fila['nota'],
                    'nota_maxima' => $fila['nota_maxima']
                ];
            }

            $resultadosEstudiantes = [];

            // Para cada estudiante, calcular notas y promedios
            foreach ($estudiantes as $est) {
                $estId = $est['id'];
                $estData = [
                    'id' => $estId,
                    'nombre' => $est['nombre_completo'],
                    'email' => $est['email'],
                    'tarea_omitida' => null,
                    'notas_tareas' => [],
                    'promedios_categoria' => [],
                    'promedio_final' => 0
                ];

                // Determinar tarea a omitir si corresponde
                $tareaOmitidaId = null;
                if ($omitir_menor == 1) {
                    $minNota = null;
                    $minTareaId = null;
                    // Recorremos todas las tareas candidatas
                    foreach ($tareasPorCategoria as $catId => $tareasLista) {
                        // Solo considerar categorías con 2 o más tareas
                        if ($tareasCountByCategoria[$catId] >= 2) {
                            foreach ($tareasLista as $tarea) {
                                $tId = $tarea['id'];
                                // Calcular nota vigesimal (0-20). Si no hay nota, tratamos como 0.
                                $notaInfo = $notasPorEstudiante[$estId][$tId] ?? null;
                                $notaV = 0;
                                if ($notaInfo && $notaInfo['nota_maxima'] > 0) {
                                    $notaV = ($notaInfo['nota'] / $notaInfo['nota_maxima']) * 20;
                                }
                                // Buscar la mínima
                                if ($minNota === null || $notaV < $minNota) {
                                    $minNota = $notaV;
                                    $minTareaId = $tId;
                                }
                            }
                        }
                    }
                    $tareaOmitidaId = $minTareaId;
                    $estData['tarea_omitida'] = $tareaOmitidaId;
                }

                // Calcular notas por tarea y promedios por categoría
                $promedioFinal = 0;
                foreach ($categorias as $cat) {
                    $catId = $cat['id'];
                    $taskList = $tareasPorCategoria[$catId];
                    $sumNotas = 0;
                    $contNotas = 0;
                    foreach ($taskList as $tarea) {
                        $tId = $tarea['id'];
                        // Calcular nota vigesimal (0-20)
                        $notaInfo = $notasPorEstudiante[$estId][$tId] ?? null;
                        $notaV = 0;
                        if ($notaInfo && $notaInfo['nota_maxima'] > 0) {
                            $notaV = ($notaInfo['nota'] / $notaInfo['nota_maxima']) * 20;
                        }
                        // Guardar en notas_tareas
                        $estData['notas_tareas'][$tId] = round($notaV, 2);
                        // Si esta tarea es la omitida, no la tomamos en cuenta para el promedio
                        if ($omitir_menor == 1 && $tareaOmitidaId && $tId == $tareaOmitidaId) {
                            continue;
                        }
                        $sumNotas += $notaV;
                        $contNotas++;
                    }
                    // Calcular promedio de la categoría
                    $promCat = ($contNotas > 0) ? ($sumNotas / $contNotas) : 0;
                    $promCat = round($promCat, 2);
                    $estData['promedios_categoria'][$catId] = $promCat;
                    // Sumar al promedio final ponderado
                    $promedioFinal += ($promCat * $cat['peso']) / 100;
                }
                $estData['promedio_final'] = round($promedioFinal, 2);
                $resultadosEstudiantes[] = $estData;
            }

            // Construir estructura de categorías con tareas
            $categoriasConTareas = [];
            foreach ($categorias as $cat) {
                $catId = $cat['id'];
                $categoriasConTareas[] = [
                    'id' => $catId,
                    'nombre' => $cat['nombre'],
                    'peso' => $cat['peso'],
                    'tareas' => $tareasPorCategoria[$catId] ?? []
                ];
            }

            return [
                'categorias' => $categoriasConTareas,
                'estudiantes' => $resultadosEstudiantes
            ];
        } catch (Exception $e) {
            throw new Exception("Error al calcular tabla completa: " . $e->getMessage());
        }
    }
    
    // NUEVO: Método para limpiar categorías que no están en Classroom
    public function limpiarCategoriasNoClasroom($curso_id) {
        $sql = "DELETE FROM categorias 
                WHERE curso_id = :curso_id 
                AND classroom_category_id IS NULL";
        $stmt = $this->connection->prepare($sql);
        return $stmt->execute([':curso_id' => $curso_id]);
    }
    
    // NUEVO: Método para verificar si existen categorías en el curso
    public function existenCategorias($curso_id) {
        $sql = "SELECT COUNT(*) as total 
                FROM categorias 
                WHERE curso_id = :curso_id 
                AND classroom_category_id IS NOT NULL";
        $stmt = $this->connection->prepare($sql);
        $stmt->execute([':curso_id' => $curso_id]);
        $result = $stmt->fetch();
        return $result['total'] > 0;
    }
    
    // NUEVO: Método para obtener estadísticas del curso
    public function obtenerEstadisticasCurso($curso_id) {
        $stats = [];
        
        // Total de estudiantes
        $sql = "SELECT COUNT(*) as total FROM estudiantes WHERE curso_id = :curso_id";
        $stmt = $this->connection->prepare($sql);
        $stmt->execute([':curso_id' => $curso_id]);
        $stats['total_estudiantes'] = $stmt->fetch()['total'];
        
        // Total de tareas
        $sql = "SELECT COUNT(*) as total FROM tareas WHERE curso_id = :curso_id";
        $stmt = $this->connection->prepare($sql);
        $stmt->execute([':curso_id' => $curso_id]);
        $stats['total_tareas'] = $stmt->fetch()['total'];
        
        // Total de categorías
        $sql = "SELECT COUNT(*) as total FROM categorias 
                WHERE curso_id = :curso_id AND classroom_category_id IS NOT NULL";
        $stmt = $this->connection->prepare($sql);
        $stmt->execute([':curso_id' => $curso_id]);
        $stats['total_categorias'] = $stmt->fetch()['total'];
        
        // Total de notas
        $sql = "SELECT COUNT(*) as total FROM notas_estudiantes WHERE curso_id = :curso_id";
        $stmt = $this->connection->prepare($sql);
        $stmt->execute([':curso_id' => $curso_id]);
        $stats['total_notas'] = $stmt->fetch()['total'];
        
        return $stats;
    }
}