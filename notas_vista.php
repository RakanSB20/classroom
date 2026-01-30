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
    Google_Service_Classroom::CLASSROOM_ROSTERS_READONLY,
    Google_Service_Classroom::CLASSROOM_COURSES_READONLY,
    Google_Service_Classroom::CLASSROOM_COURSEWORK_STUDENTS_READONLY,
    Google_Service_Classroom::CLASSROOM_COURSEWORK_ME_READONLY,
    Google_Service_Classroom::CLASSROOM_STUDENT_SUBMISSIONS_STUDENTS_READONLY
]);
$client->setAccessToken($_SESSION['access_token']);

// Refrescar token si expiró
if ($client->isAccessTokenExpired()) {
    if ($client->getRefreshToken()) {
        $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
        $_SESSION['access_token'] = $client->getAccessToken();
    } else {
        unset($_SESSION['access_token']);
        header('Location: index.php');
        exit;
    }
}

$service = new Google_Service_Classroom($client);
$db = Database::getInstance();

$courseId = $_GET['courseId'] ?? '';
$mensaje = '';
$curso = null;
$categorias = [];
$tareas = [];
$estudiantes = [];

if ($courseId) {
    try {
        // Obtener información del curso
        $curso = $service->courses->get($courseId);
        
        // Sincronizar curso en BD
        $db->sincronizarCurso([
            'id' => $curso->getId(),
            'nombre' => $curso->getName(),
            'seccion' => $curso->getSection(),
            'aula' => $curso->getRoom()
        ]);

        // PASO 1: Extraer categorías REALES de Google Classroom
        // Obtener configuración del curso para categorías (SIN gradingScheme)
        $courseDetails = $service->courses->get($courseId, [
            'fields' => 'id,name,gradebookSettings(calculationType,gradeCategories(id,name,weight))'
        ]);

        // Reemplaza getGradingScheme() por gradebookSettings
        $gradebook = method_exists($courseDetails, 'getGradebookSettings')
            ? $courseDetails->getGradebookSettings()
            : null;

       $categoriasExtraidas = [];

        if ($gradebook && method_exists($gradebook, 'getGradeCategories')) {
    // 1) Asegúrate de que el curso use categorías ponderadas
            $calcType = method_exists($gradebook, 'getCalculationType') ? $gradebook->getCalculationType() : null;
                 if ($calcType !== 'WEIGHTED_CATEGORIES') {
        // Si no usa WEIGHTED_CATEGORIES, no insertamos ni mostramos categorías
                 $gradeCategories = [];
                  } else {
                    $gradeCategories = $gradebook->getGradeCategories() ?: [];
                    }

    foreach ($gradeCategories as $category) {
        // 2) Conversión segura del peso
        $pesoRaw = $category->getWeight(); // a veces viene como 300000 para 30%
        if ($pesoRaw === null) {
            // Sin peso => saltar esta categoría
            continue;
        }

        // Si es muy grande, asumimos escala de millonésimos y convertimos
        $peso = ($pesoRaw > 1000) ? ($pesoRaw / 10000.0) : (float)$pesoRaw;

        // 3) Validar rango 0..100 para no violar el CHECK de la tabla
        if ($peso < 0 || $peso > 100) {
            continue;
        }

        // Normalizar a 2 decimales (tu columna es DECIMAL(5,2))
        $peso = (float) number_format($peso, 2, '.', '');

        // Arreglo de salida (si lo usas en la vista)
        $categoriasExtraidas[] = [
            'id'     => $category->getId(),
            'nombre' => $category->getName(),
            'peso'   => $peso
        ];

        // 4) Guardar/actualizar en BD
        $db->sincronizarCategoria([
            'curso_id'              => $courseId,
            'classroom_category_id' => $category->getId(),
            'nombre'                => $category->getName(),
            'peso'                  => $peso
        ]);
    }
}

        // PASO 2: Obtener y sincronizar estudiantes
        $response = $service->courses_students->listCoursesStudents($courseId);
        $studentsList = $response->getStudents();
        
        if ($studentsList) {
            foreach ($studentsList as $student) {
                $profile = $student->getProfile();
                $estudiantes[] = [
                    'id' => $student->getUserId(),
                    'nombre' => $profile->getName()->getFullName(),
                    'email' => $profile->getEmailAddress()
                ];
                
                // Sincronizar estudiante en BD
                $conn = $db->getConnection();
                $stmt = $conn->prepare("INSERT INTO estudiantes (id, curso_id, nombre_completo, email) 
                                       VALUES (:id, :curso_id, :nombre, :email) 
                                       ON DUPLICATE KEY UPDATE 
                                       nombre_completo = VALUES(nombre_completo), 
                                       email = VALUES(email)");
                $stmt->execute([
                    ':id' => $student->getUserId(),
                    ':curso_id' => $courseId,
                    ':nombre' => $profile->getName()->getFullName(),
                    ':email' => $profile->getEmailAddress()
                ]);
            }
        }

        // PASO 3: Obtener y sincronizar tareas del curso
        $courseworkResponse = $service->courses_courseWork->listCoursesCourseWork($courseId /*, [
            // opcional: puedes pedir campos específicos
            // 'fields' => 'courseWork(id,title,description,maxPoints,gradeCategory(id))'
        ]*/);
        $courseworks = $courseworkResponse->getCourseWork();
        
        if ($courseworks) {
            foreach ($courseworks as $coursework) {
                // Obtener el ID de categoría desde el objeto gradeCategory (no getGradeCategoryId())
                $gc = method_exists($coursework, 'getGradeCategory') ? $coursework->getGradeCategory() : null;
                $gradeCategoryId = ($gc && method_exists($gc, 'getId')) ? $gc->getId() : null;

                $tareas[] = [
                    'id' => $coursework->getId(),
                    'titulo' => $coursework->getTitle(),
                    'descripcion' => $coursework->getDescription(),
                    'puntos_max' => $coursework->getMaxPoints(),
                    'category_id' => $gradeCategoryId
                ];
                
                // Sincronizar tarea en BD
                $db->sincronizarTarea([
                    'id' => $coursework->getId(),
                    'curso_id' => $courseId,
                    'titulo' => $coursework->getTitle(),
                    'descripcion' => $coursework->getDescription(),
                    'puntos_max' => $coursework->getMaxPoints()
                ]);

                // PASO 4: Asignar automáticamente la tarea a su categoría si existe
                if ($gradeCategoryId) {
                    $db->asignarTareaCategoriaByClassroomId($courseId, $gradeCategoryId, $coursework->getId());
                }
                
                // PASO 5: Obtener calificaciones de la tarea
                try {
                    $submissions = $service->courses_courseWork_studentSubmissions
                        ->listCoursesCourseWorkStudentSubmissions($courseId, $coursework->getId());
                    
                    foreach ($submissions->getStudentSubmissions() as $submission) {
                        if ($submission->getAssignedGrade() !== null) {
                            $db->sincronizarNota([
                                'curso_id' => $courseId,
                                'estudiante_id' => $submission->getUserId(),
                                'tarea_id' => $coursework->getId(),
                                'nota' => $submission->getAssignedGrade(),
                                'nota_maxima' => $coursework->getMaxPoints()
                            ]);
                        }
                    }
                } catch (Exception $e) {
                    // Continuar si no se pueden obtener las calificaciones
                }
            }
        }

        // Obtener categorías y tareas desde BD (ya sincronizadas)
        $categorias = $db->obtenerCategoriasCurso($courseId);
        $tareas = $db->obtenerTareasCurso($courseId);

    } catch (Exception $e) {
        $mensaje = '❌ Error al cargar datos: ' . $e->getMessage();
    }
} else {
    $mensaje = '❌ No se proporcionó ID de curso.';
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestión de Notas</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .categoria-card {
            border: 2px solid #dee2e6;
            border-radius: 12px;
            margin-bottom: 20px;
            overflow: hidden;
            transition: all 0.3s ease;
        }
        .categoria-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }
        .categoria-header {
            background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
            color: white;
            padding: 15px 20px;
        }
        .categoria-peso {
            background: rgba(255,255,255,0.2);
            padding: 4px 12px;
            border-radius: 15px;
            font-size: 0.9em;
            font-weight: bold;
        }
        .tarea-tag {
            background: #e0e7ff;
            color: #3730a3;
            padding: 6px 12px;
            margin: 4px;
            border-radius: 15px;
            font-size: 0.85em;
            display: inline-block;
            transition: all 0.3s ease;
        }
        .tarea-tag:hover {
            background: #c7d2fe;
            transform: scale(1.02);
        }
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #6b7280;
        }
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
        .btn-add-task {
            background: linear-gradient(135deg, #059669 0%, #0d9488 100%);
            border: none;
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            transition: all 0.3s ease;
        }
        .btn-add-task:hover {
            background: linear-gradient(135deg, #047857 0%, #0f766e 100%);
            transform: translateY(-2px);
            color: white;
        }
    </style>
</head>
<body class="bg-light">
<div class="container py-4">
    <div class="row">
        <div class="col-12">
            <h2 class="mb-4 text-primary">
                <i class="fas fa-chart-line me-2"></i>
                Control de Evaluaciones - <?= htmlspecialchars($curso ? $curso->getName() : 'Curso') ?>
            </h2>

            <?php if ($mensaje): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($mensaje) ?></div>
            <?php endif; ?>

            <?php if ($curso): ?>
                <?php if (!empty($categorias)): ?>
                    <div class="card shadow-sm mb-4">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0">
                                <i class="fas fa-list-alt me-2"></i>
                                Categorías de Evaluación de Google Classroom
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php foreach ($categorias as $categoria): ?>
                                <div class="categoria-card">
                                    <div class="categoria-header d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="mb-1"><?= htmlspecialchars($categoria['nombre']) ?></h6>
                                            <span class="categoria-peso">
                                                <i class="fas fa-weight-hanging me-1"></i>
                                                Peso: <?= number_format($categoria['peso'], 1) ?>%
                                            </span>
                                        </div>
                                        <button class="btn btn-add-task btn-sm" 
                                                onclick="abrirModalTareas(<?= $categoria['id'] ?>, '<?= htmlspecialchars($categoria['nombre']) ?>')">
                                            <i class="fas fa-plus me-1"></i>Gestionar tareas
                                        </button>
                                    </div>
                                    <div class="p-3" id="tareas-categoria-<?= $categoria['id'] ?>">
                                        <?php 
                                        $tareasCategoria = $db->obtenerTareasCategoria($categoria['id']);
                                        if ($tareasCategoria): 
                                        ?>
                                            <?php foreach ($tareasCategoria as $tarea): ?>
                                                <span class="tarea-tag">
                                                    <i class="fas fa-tasks me-1"></i>
                                                    <?= htmlspecialchars($tarea['titulo']) ?>
                                                    <small class="ms-1 text-muted">(<?= $tarea['puntos_max'] ?? 0 ?>pts)</small>
                                                </span>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <div class="empty-state">
                                                <i class="fas fa-tasks"></i>
                                                <p class="mb-0">No hay tareas asignadas a esta categoría</p>
                                                <small>Usa el botón "Gestionar tareas" para asignar tareas</small>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            
                            <div class="text-center mt-4">
                                <!--<button class="btn btn-success btn-lg me-3" onclick="calcularPromediosPonderados()">
                                    <i class="fas fa-calculator me-2"></i>Calcular Promedios Ponderados
                                </button>-->
                                <!-- Eliminado: el botón de Ver Tabla de Notas ahora se reemplaza por un apartado fijo -->
                                <a href="exportar_excel.php?courseId=<?= $courseId ?>" class="btn btn-warning btn-lg me-2">
                                    <i class="fas fa-file-excel me-2"></i>Exportar Excel
                                </a>
                                <!-- NUEVO: Exportar Excel excluyendo la nota más baja -->
                                <a href="exportar_excel.php?courseId=<?= $courseId ?>&omit_min=1" class="btn btn-warning btn-lg">
                                    <i class="fas fa-file-excel me-2"></i>Exportar Excel (sin nota más baja)
                                </a>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="card shadow-sm mb-4">
                        <div class="card-body text-center py-5">
                            <i class="fas fa-info-circle fa-3x text-muted mb-3"></i>
                            <h4 class="text-muted mb-3">No hay categorías de evaluación configuradas</h4>
                            <p class="text-muted mb-4">
                                Para usar el sistema de notas ponderadas, primero debes crear categorías de evaluación 
                                en Google Classroom con sus respectivos pesos.
                            </p>
                            <div class="alert alert-info">
                                <strong>¿Cómo crear categorías en Google Classroom?</strong><br>
                                1. Ve a tu curso en Google Classroom<br>
                                2. Haz clic en "Trabajo de clase"<br>
                                3. Clic en "Configuración" (engranaje)<br>
                                4. Selecciona "Cálculo de calificaciones"<br>
                                5. Activa "Cálculo de calificaciones" y crea tus categorías con pesos
                            </div>
                            <button class="btn btn-primary" onclick="location.reload()">
                                <i class="fas fa-sync-alt me-2"></i>Recargar para verificar categorías
                            </button>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php
            // Mostrar la tabla de notas debajo de las categorías cuando existe un curso y al menos una categoría.
            if ($curso && !empty($categorias)):
            ?>
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-secondary text-white">
                    <h5 class="mb-0">
                        <i class="fas fa-table me-2"></i>
                        Tabla de Notas
                    </h5>
                </div>
                <div class="card-body">
                    <!-- Sección de controles para el modo de cálculo de la tabla -->
                    <div class="d-flex flex-column flex-md-row align-items-start align-items-md-center mb-3" id="controlesTabla">
                        <div id="estadoTabla" class="me-md-3 mb-2 mb-md-0 fw-bold text-info">
                            <!-- Mensaje de estado se actualizará dinámicamente -->
                        </div>
                        <div class="btn-group" role="group" aria-label="Modo de cálculo">
                            <button id="btnModoNormal" type="button" class="btn btn-outline-primary" onclick="verTablaNotasModo(0)">
                                <i class="fas fa-list me-1"></i> Tabla sin cambios
                            </button>
                            <button id="btnModoSinMenor" type="button" class="btn btn-outline-warning" onclick="verTablaNotasModo(1)">
                                <i class="fas fa-minus-circle me-1"></i> Tabla eliminando la nota más baja
                            </button>
                        </div>
                    </div>
                    <!-- Contenedor donde se renderizará la tabla -->
                    <div id="tablaNotasContainer"></div>
                </div>
            </div>
            <?php endif; ?>

            <a href="index.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left me-1"></i>Volver
            </a>
        </div>
    </div>
</div>

<!-- Modal para seleccionar tareas -->
<div class="modal fade" id="modalTareas" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-tasks me-2"></i>
                    Gestionar Tareas para: <span id="nombreCategoria"></span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <input type="text" class="form-control" id="buscarTarea" placeholder="Buscar tarea...">
                </div>
                <div id="listaTareas">
                    <!-- Las tareas se cargarán aquí dinámicamente -->
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" onclick="aplicarSeleccionTareas()">
                    <i class="fas fa-check me-1"></i>Aplicar Selección
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal para tabla de notas -->
<div class="modal fade" id="modalNotas" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-chart-bar me-2"></i>
                    Promedios Ponderados por Categoría
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="tablaNotas">
                    <!-- La tabla se cargará aquí dinámicamente -->
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
let categoriaActual = null;
let tareasDisponibles = <?= json_encode($tareas) ?>;
// Global variable para categorías y sus tareas. Se inicializa con las categorías sincronizadas,
// pero se actualizará dinámicamente al cargar la tabla desde el backend.
let categorias = <?= json_encode($categorias) ?>;

function abrirModalTareas(categoriaId, nombreCategoria) {
    categoriaActual = categoriaId;
    document.getElementById('nombreCategoria').textContent = nombreCategoria;
    
    fetch(`gestionar_notas.php?action=obtener_tareas_categoria&categoria_id=${categoriaId}`)
        .then(response => response.json())
        .then(data => {
            const tareasAsignadas = data.map(t => t.id);
            mostrarListaTareas(tareasAsignadas);
            new bootstrap.Modal(document.getElementById('modalTareas')).show();
        });
}

function mostrarListaTareas(tareasAsignadas = []) {
    const container = document.getElementById('listaTareas');
    container.innerHTML = '';
    
    if (tareasDisponibles.length === 0) {
        container.innerHTML = '<p class="text-center text-muted">No hay tareas disponibles en este curso.</p>';
        return;
    }
    
    tareasDisponibles.forEach(tarea => {
        const isSelected = tareasAsignadas.includes(tarea.id);
        const taskDiv = document.createElement('div');
        taskDiv.className = `task-item p-3 border rounded mb-2 ${isSelected ? 'bg-light border-primary' : ''}`;
        taskDiv.innerHTML = `
            <label class="d-flex align-items-center cursor-pointer w-100 mb-0">
                <input type="checkbox" class="task-checkbox me-3" value="${tarea.id}" ${isSelected ? 'checked' : ''}>
                <div class="flex-grow-1">
                    <strong class="d-block">${tarea.titulo}</strong>
                    ${tarea.descripcion ? `<small class="text-muted d-block">${tarea.descripcion}</small>` : ''}
                    <small class="text-info"><i class="fas fa-star me-1"></i>Puntos máximos: ${tarea.puntos_max || 'No definido'}</small>
                </div>
            </label>
        `;
        
        const checkbox = taskDiv.querySelector('.task-checkbox');
        checkbox.addEventListener('change', function() {
            if (this.checked) {
                taskDiv.classList.add('bg-light', 'border-primary');
            } else {
                taskDiv.classList.remove('bg-light', 'border-primary');
            }
        });
        
        container.appendChild(taskDiv);
    });
}

function aplicarSeleccionTareas() {
    const checkboxes = document.querySelectorAll('#listaTareas .task-checkbox:checked');
    const tareasSeleccionadas = Array.from(checkboxes).map(cb => cb.value);
    
    fetch('gestionar_notas.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            action: 'asignar_tareas_categoria',
            categoria_id: categoriaActual,
            tareas: tareasSeleccionadas
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            actualizarVistaTareas(categoriaActual, tareasSeleccionadas);
            bootstrap.Modal.getInstance(document.getElementById('modalTareas')).hide();
            mostrarMensaje('Tareas asignadas correctamente', 'success');
        } else {
            mostrarMensaje('Error al asignar tareas: ' + data.message, 'danger');
        }
    });
}

function actualizarVistaTareas(categoriaId, tareasIds) {
    const container = document.getElementById(`tareas-categoria-${categoriaId}`);
    
    if (tareasIds.length === 0) {
        container.innerHTML = `
            <div class="empty-state">
                <i class="fas fa-tasks"></i>
                <p class="mb-0">No hay tareas asignadas a esta categoría</p>
                <small>Usa el botón "Gestionar tareas" para asignar tareas</small>
            </div>
        `;
    } else {
        const tareasTags = tareasIds.map(tareaId => {
            const tarea = tareasDisponibles.find(t => t.id === tareaId);
            return `<span class="tarea-tag">
                <i class="fas fa-tasks me-1"></i>
                ${tarea ? tarea.titulo : 'Tarea no encontrada'}
                <small class="ms-1 text-muted">(${tarea ? (tarea.puntos_max ?? 0) : 0}pts)</small>
            </span>`;
        }).join('');
        container.innerHTML = tareasTags;
    }
}

function calcularPromediosPonderados() {
    fetch(`gestionar_notas.php?action=calcular_promedios_ponderados&course_id=<?= $courseId ?>`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                mostrarMensaje('Promedios ponderados calculados correctamente', 'success');
                verTablaNotas();
            } else {
                mostrarMensaje('Error al calcular promedios: ' + data.message, 'danger');
            }
        });
}

// Obsoleto: verTablaNotas() se dejó de usar porque la tabla ahora se muestra en la página.
// function verTablaNotas() {
//     fetch(`gestionar_notas.php?action=obtener_promedios_ponderados&course_id=<?= $courseId ?>`)
//         .then(response => response.json())
//         .then(data => {
//             mostrarTablaNotasPonderadas(data);
//             new bootstrap.Modal(document.getElementById('modalNotas')).show();
//         });
// }

// Obtiene y muestra la tabla de promedios actuales en la página.
// Nueva función para mostrar la tabla según el modo de cálculo.
// omitir = 0 -> tabla con todas las notas
// omitir = 1 -> tabla eliminando la nota más baja
function verTablaNotasModo(omitir) {
    // Actualizar mensaje y clases de botones
    const estadoEl = document.getElementById('estadoTabla');
    const btnNormal = document.getElementById('btnModoNormal');
    const btnSinMenor = document.getElementById('btnModoSinMenor');
    if (omitir === 1) {
        estadoEl.textContent = 'Tabla eliminando la nota más baja';
        btnNormal.classList.remove('btn-primary', 'active');
        btnNormal.classList.add('btn-outline-primary');
        btnSinMenor.classList.remove('btn-outline-warning');
        btnSinMenor.classList.add('btn-warning', 'active');
    } else {
        estadoEl.textContent = 'Tabla sin cambios: se consideran todas las notas';
        btnSinMenor.classList.remove('btn-warning', 'active');
        btnSinMenor.classList.add('btn-outline-warning');
        btnNormal.classList.remove('btn-outline-primary');
        btnNormal.classList.add('btn-primary', 'active');
    }
    // Obtener datos desde el backend según el modo
    fetch(`gestionar_notas.php?action=obtener_tabla_notas&course_id=<?= $courseId ?>&omitir_menor=${omitir}`)
        .then(response => response.json())
        .then(data => {
            // Actualizar categorías globales si el backend las devuelve
            if (data.categorias) {
                categorias = data.categorias;
            }
            mostrarTablaNotasPonderadas(data);
        });
}

function mostrarTablaNotasPonderadas(datos) {
    const container = document.getElementById('tablaNotasContainer') || document.getElementById('tablaNotas');

    // Validar datos
    if (!datos || !datos.estudiantes || datos.estudiantes.length === 0) {
        container.innerHTML = '<p class="text-center">No hay datos de notas disponibles.</p>';
        return;
    }
    // Actualizar categorías globales (incluyen tareas)
    if (datos.categorias) {
        categorias = datos.categorias;
    }
    const estudiantes = datos.estudiantes;

    // Construir cabecera de la tabla con doble fila (categorías y tareas)
    let html = '<div class="table-responsive"><table class="table table-striped table-hover">';
    html += '<thead>';
    // Fila de categorías
    html += '<tr>';
    html += '<th rowspan="2">N°</th>';
    html += '<th rowspan="2">Nombre</th>';
    categorias.forEach(cat => {
        const tareaCount = (cat.tareas && cat.tareas.length) ? cat.tareas.length : 0;
        // +1 para el promedio de la categoría
        const colspan = tareaCount + 1;
        html += `<th class="text-center" colspan="${colspan}">${cat.nombre}<br><small>(${cat.peso}%)</small></th>`;
    });
    html += '<th rowspan="2" class="bg-success text-white text-center">Promedio Final</th>';
    html += '</tr>';
    // Fila de tareas y promedio por categoría
    html += '<tr>';
    categorias.forEach(cat => {
        if (cat.tareas && cat.tareas.length) {
            let idx = 1;
            cat.tareas.forEach(tarea => {
                // Usamos un número secuencial por tarea dentro de su categoría para simplificar
                html += `<th class="text-center" title="${tarea.titulo}">T${idx++}</th>`;
            });
        }
        // Columna para promedio de la categoría
        html += '<th class="text-center">Prom</th>';
    });
    html += '</tr>';
    html += '</thead><tbody>';

    // Construir filas de estudiantes
    estudiantes.forEach((est, index) => {
        html += '<tr>';
        // Número y nombre
        html += `<td>${index + 1}</td>`;
        html += `<td><strong>${est.nombre}</strong></td>`;
        // Recorrer categorías y sus tareas
        categorias.forEach(cat => {
            if (cat.tareas && cat.tareas.length) {
                cat.tareas.forEach(tarea => {
                    const nota = (est.notas_tareas && est.notas_tareas[tarea.id] !== undefined) ? est.notas_tareas[tarea.id] : 0;
                    // Determinar clases de color según nota
                    let clase = '';
                    if (nota >= 17) {
                        clase = 'text-success';
                    } else if (nota >= 11) {
                        clase = 'text-warning';
                    } else {
                        clase = 'text-danger';
                    }
                    // Si esta tarea fue la omitida, agregar clase de resaltado
                    let omitidaClase = '';
                    if (est.tarea_omitida && est.tarea_omitida == tarea.id) {
                        omitidaClase = ' bg-secondary text-white';
                    }
                    html += `<td class="text-center ${clase}${omitidaClase}">${nota.toFixed(2)}</td>`;
                });
            }
            // Promedio de la categoría
            const promCat = (est.promedios_categoria && est.promedios_categoria[cat.id] !== undefined) ? est.promedios_categoria[cat.id] : 0;
            let claseProm = '';
            if (promCat >= 17) {
                claseProm = 'text-success';
            } else if (promCat >= 11) {
                claseProm = 'text-warning';
            } else {
                claseProm = 'text-danger';
            }
            html += `<td class="text-center ${claseProm}"><strong>${promCat.toFixed(2)}</strong></td>`;
        });
        // Promedio final
        const promFinal = est.promedio_final || 0;
        let claseFinal = '';
        if (promFinal >= 17) {
            claseFinal = 'text-success';
        } else if (promFinal >= 11) {
            claseFinal = 'text-warning';
        } else {
            claseFinal = 'text-danger';
        }
        html += `<td class="text-center ${claseFinal}"><strong>${promFinal.toFixed(2)}</strong></td>`;
        html += '</tr>';
    });
    html += '</tbody></table></div>';
    // Leyenda de colores y nota omitida
    html += '<div class="mt-3">';
    html += '<small class="text-muted"><i class="fas fa-info-circle me-1"></i>';
    html += 'Los promedios se calculan de forma ponderada según los pesos asignados en Google Classroom (escala 0-20).';
    html += '<br><strong>Escala de evaluación:</strong> ';
    html += '<span class="text-success">17-20: Excelente</span> | ';
    html += '<span class="text-warning">11-16: Aprobado</span> | ';
    html += '<span class="text-danger">0-10: Desaprobado</span>';
    html += '<br><span class="bg-secondary text-white px-2 py-1 rounded">Celda gris</span>: nota omitida para el cálculo del promedio';
    html += '</small></div>';
    container.innerHTML = html;
}

function mostrarMensaje(mensaje, tipo) {
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${tipo} alert-dismissible fade show`;
    alertDiv.innerHTML = `
        ${mensaje}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    
    const container = document.querySelector('.container');
    container.insertBefore(alertDiv, container.firstChild.nextSibling);
    
    // Auto-dismiss después de 5 segundos
    setTimeout(() => {
        if (alertDiv.parentNode) {
            alertDiv.remove();
        }
    }, 5000);
}

// Buscador de tareas
document.addEventListener('DOMContentLoaded', function() {
    const buscarInput = document.getElementById('buscarTarea');
    if (buscarInput) {
        buscarInput.addEventListener('input', function() {
            const termino = this.value.toLowerCase();
            const taskItems = document.querySelectorAll('.task-item');
            
            taskItems.forEach(item => {
                const texto = item.textContent.toLowerCase();
                if (texto.includes(termino)) {
                    item.style.display = 'block';
                } else {
                    item.style.display = 'none';
                }
            });
        });
    }

    // Cargar la tabla por defecto en modo "sin cambios" cuando se cargue la página
    <?php if ($curso && !empty($categorias)): ?>
    verTablaNotasModo(0);
    <?php endif; ?>
});
</script>
</body>
</html>