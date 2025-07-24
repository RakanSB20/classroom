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
            $sqlEstudiantes = "SELECT DISTINCT id, nombre_completo, email 
                              FROM estudiantes 
                              WHERE curso_id = :curso_id 
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