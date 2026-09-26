<?php
require_once 'config.php';

setApiHeaders();

$conn = getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    switch ($action) {

        // ==================== DATOS INICIALES ====================
        case 'init':
            // Obtener sucursales activas
            $stmt = $conn->query("SELECT id, nombre, rinde_caja FROM sucursales WHERE activo = 1 ORDER BY nombre");
            $sucursales = $stmt->fetchAll();

            // Obtener conceptos activos
            $stmt = $conn->query("SELECT id, nombre, requiere_colaborador, requiere_sucursal, es_reserva FROM conceptos WHERE activo = 1 ORDER BY nombre");
            $conceptos = $stmt->fetchAll();

            // Obtener colaboradores activos
            $stmt = $conn->query("SELECT id, nombre FROM colaboradores WHERE activo = 1 ORDER BY nombre");
            $colaboradores = $stmt->fetchAll();

            // Obtener proveedores activos
            $stmt = $conn->query("SELECT id, nombre FROM proveedores WHERE activo = 1 ORDER BY nombre");
            $proveedores = $stmt->fetchAll();

            // Obtener reservas con saldo calculado y concepto vinculado
            $stmt = $conn->query("
                SELECT r.id, r.nombre, r.descripcion, r.concepto_id,
                    COALESCE(SUM(CASE WHEN m.reserva_accion = 'aporte' THEN m.monto ELSE 0 END), 0) -
                    COALESCE(SUM(CASE WHEN m.reserva_accion = 'gasto' THEN m.monto ELSE 0 END), 0) AS saldo
                FROM reservas r
                LEFT JOIN movimientos m ON m.reserva_id = r.id
                WHERE r.activo = 1
                GROUP BY r.id, r.nombre, r.descripcion, r.concepto_id
                ORDER BY r.nombre
            ");
            $reservas = $stmt->fetchAll();

            // Obtener anotaciones activas (orden: más recientes primero)
            $stmt = $conn->query("SELECT id, titulo, mensaje, created_at FROM anotaciones WHERE activo = 1 ORDER BY created_at DESC, id DESC");
            $anotaciones = $stmt->fetchAll();

            // Obtener días cerrados con nombre de sucursal
            $stmt = $conn->query("
                SELECT dc.id, dc.sucursal_id, s.nombre as sucursal_nombre, dc.fecha, dc.motivo
                FROM dias_cerrados dc
                JOIN sucursales s ON s.id = dc.sucursal_id
                ORDER BY dc.fecha DESC
            ");
            $diasCerrados = $stmt->fetchAll();

            // Obtener saldo actual (ordenar por fecha para obtener el saldo cronológicamente correcto)
            $stmt = $conn->query("SELECT saldo FROM movimientos ORDER BY fecha DESC, id DESC LIMIT 1");
            $row = $stmt->fetch();
            $saldo = $row ? floatval($row['saldo']) : 0;

            // Obtener fecha último ingreso (por fecha, no por ID de inserción)
            $stmt = $conn->query("SELECT fecha FROM movimientos WHERE tipo = 'ingreso' ORDER BY fecha DESC LIMIT 1");
            $row = $stmt->fetch();
            $ultimoIngreso = $row ? $row['fecha'] : null;

            jsonResponse([
                'sucursales' => $sucursales,
                'conceptos' => $conceptos,
                'colaboradores' => $colaboradores,
                'proveedores' => $proveedores,
                'reservas' => $reservas,
                'anotaciones' => $anotaciones,
                'diasCerrados' => $diasCerrados,
                'saldo' => $saldo,
                'ultimoIngreso' => $ultimoIngreso
            ]);
            break;

        // ==================== MOVIMIENTOS ====================
        case 'movimientos':
            if ($method === 'GET') {
                // Listar movimientos con filtros
                $where = "1=1";
                $params = [];

                if (!empty($_GET['fechaDesde'])) {
                    $where .= " AND fecha >= :fechaDesde";
                    $params[':fechaDesde'] = $_GET['fechaDesde'];
                }
                if (!empty($_GET['fechaHasta'])) {
                    $where .= " AND fecha <= :fechaHasta";
                    $params[':fechaHasta'] = $_GET['fechaHasta'];
                }
                if (!empty($_GET['tipo'])) {
                    $where .= " AND tipo = :tipo";
                    $params[':tipo'] = $_GET['tipo'];
                }
                if (!empty($_GET['sucursalId'])) {
                    $where .= " AND sucursal_id = :sucursalId";
                    $params[':sucursalId'] = $_GET['sucursalId'];
                }
                if (!empty($_GET['conceptoId'])) {
                    $where .= " AND concepto_id = :conceptoId";
                    $params[':conceptoId'] = $_GET['conceptoId'];
                }
                if (!empty($_GET['colaboradorId'])) {
                    $where .= " AND colaborador_id = :colaboradorId";
                    $params[':colaboradorId'] = $_GET['colaboradorId'];
                }
                if (!empty($_GET['proveedorId'])) {
                    $where .= " AND proveedor_id = :proveedorId";
                    $params[':proveedorId'] = $_GET['proveedorId'];
                }
                if (!empty($_GET['origenEgreso']) && in_array($_GET['origenEgreso'], ['normal', 'caja'])) {
                    $where .= " AND origen = :origenEgreso";
                    $params[':origenEgreso'] = $_GET['origenEgreso'];
                }

                // Filtro por FECHA DE ASENTAMIENTO (cuándo se cargó)
                aplicarFiltroAsentamiento($where, $params);

                // Clamp defensivo: evita que un limit negativo o absurdo tumbe la respuesta.
                // El techo es 10000 porque las exportaciones a CSV piden ese tope y el detalle
                // de Reportes pide 2000: un límite más bajo los truncaría en silencio.
                $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;
                $limit = max(1, min($limit, 10000));
                $offset = isset($_GET['offset']) ? max(0, intval($_GET['offset'])) : 0;

                $orderBy = ordenMovimientos($_GET['orden'] ?? null);

                $sql = "SELECT * FROM movimientos WHERE $where ORDER BY $orderBy LIMIT $limit OFFSET $offset";
                $stmt = $conn->prepare($sql);
                $stmt->execute($params);
                $movimientos = $stmt->fetchAll();

                // Total para paginación.
                // El COUNT(*) sólo se corre cuando hace falta: al navegar entre páginas de un
                // mismo filtro el total no cambia, así que el front lo reusa y manda sinTotal=1.
                if (!empty($_GET['sinTotal'])) {
                    jsonResponse(['movimientos' => $movimientos]);
                }

                $sqlCount = "SELECT COUNT(*) as total FROM movimientos WHERE $where";
                $stmtCount = $conn->prepare($sqlCount);
                $stmtCount->execute($params);
                $total = $stmtCount->fetch()['total'];

                jsonResponse([
                    'movimientos' => $movimientos,
                    'total' => intval($total)
                ]);

            } elseif ($method === 'POST') {
                // Crear movimiento
                $data = json_decode(file_get_contents('php://input'), true);

                // Validaciones básicas
                if (empty($data['fecha']) || empty($data['tipo']) || !isset($data['monto'])) {
                    jsonResponse(['error' => 'Faltan datos requeridos'], 400);
                }

                // Validar monto positivo
                $monto = floatval($data['monto']);
                if ($monto <= 0) {
                    jsonResponse(['error' => 'El monto debe ser mayor a cero'], 400);
                }

                // Validar tipo
                if (!in_array($data['tipo'], ['ingreso', 'egreso'])) {
                    jsonResponse(['error' => 'Tipo de movimiento inválido'], 400);
                }

                // Validar formato de fecha
                $fecha = $data['fecha'];
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
                    jsonResponse(['error' => 'Formato de fecha inválido'], 400);
                }

                // ANTI-DUPLICADO: Verificar si ya existe un movimiento idéntico en los últimos 5 segundos
                $sucIdCheck = isset($data['sucursalId']) ? intval($data['sucursalId']) : null;
                $conIdCheck = isset($data['conceptoId']) ? intval($data['conceptoId']) : null;

                // Construir consulta dinámica para evitar problema de parámetros duplicados en PDO
                $sqlDup = "SELECT id FROM movimientos WHERE fecha = :fecha AND tipo = :tipo AND monto = :monto";
                $paramsDup = [
                    ':fecha' => $fecha,
                    ':tipo' => $data['tipo'],
                    ':monto' => round($monto, 2)
                ];

                if ($sucIdCheck !== null) {
                    $sqlDup .= " AND sucursal_id = :suc_id";
                    $paramsDup[':suc_id'] = $sucIdCheck;
                } else {
                    $sqlDup .= " AND sucursal_id IS NULL";
                }

                if ($conIdCheck !== null) {
                    $sqlDup .= " AND concepto_id = :con_id";
                    $paramsDup[':con_id'] = $conIdCheck;
                } else {
                    $sqlDup .= " AND concepto_id IS NULL";
                }

                $sqlDup .= " AND created_at >= DATE_SUB(NOW(), INTERVAL 5 SECOND) LIMIT 1";

                $stmtDup = $conn->prepare($sqlDup);
                $stmtDup->execute($paramsDup);

                if ($stmtDup->fetch()) {
                    // Ya existe un movimiento idéntico reciente, probablemente duplicado
                    jsonResponse(['error' => 'Movimiento duplicado detectado. Espere unos segundos e intente de nuevo.'], 409);
                }

                // Sanitizar observación
                $observacion = isset($data['observacion']) ? trim(substr($data['observacion'], 0, 255)) : null;

                // Validar sucursal/concepto según el tipo de movimiento
                $sucursalId = null;
                $sucursalNombre = null;
                $conceptoId = null;
                $conceptoNombre = null;
                $colaboradorId = null;
                $colaboradorNombre = null;
                $proveedorId = null;
                $proveedorNombre = null;
                $reservaId = null;
                $reservaNombre = null;
                $reservaAccion = null;

                if ($data['tipo'] === 'ingreso') {
                    // Para ingresos, validar que la sucursal exista
                    if (empty($data['sucursalId'])) {
                        jsonResponse(['error' => 'Debe seleccionar una sucursal para el ingreso'], 400);
                    }
                    $sucursal = validarSucursal($conn, $data['sucursalId']);
                    if (!$sucursal) {
                        jsonResponse(['error' => 'La sucursal seleccionada no existe o fue eliminada'], 400);
                    }
                    $sucursalId = $sucursal['id'];
                    $sucursalNombre = $sucursal['nombre'];

                    // BLOQUEO ESTRICTO: máximo 1 ingreso por sucursal por fecha (anti-duplicación de rendiciones)
                    $stmtUniq = $conn->prepare("SELECT id, monto FROM movimientos WHERE tipo = 'ingreso' AND fecha = :fecha AND sucursal_id = :sucursalId LIMIT 1");
                    $stmtUniq->execute([':fecha' => $fecha, ':sucursalId' => $sucursalId]);
                    $ingresoExistente = $stmtUniq->fetch();
                    if ($ingresoExistente) {
                        jsonResponse([
                            'error' => "Ya existe un ingreso para la sucursal '{$sucursalNombre}' en la fecha {$fecha} (monto registrado: $" . number_format($ingresoExistente['monto'], 2, ',', '.') . "). No se permite duplicar la rendición."
                        ], 409);
                    }
                } else {
                    // Para egresos, validar que el concepto exista
                    if (empty($data['conceptoId'])) {
                        jsonResponse(['error' => 'Debe seleccionar un concepto para el egreso'], 400);
                    }
                    $concepto = validarConcepto($conn, $data['conceptoId']);
                    if (!$concepto) {
                        jsonResponse(['error' => 'El concepto seleccionado no existe o fue eliminado'], 400);
                    }
                    $conceptoId = $concepto['id'];
                    $conceptoNombre = $concepto['nombre'];

                    // Validar colaborador según configuración del concepto
                    if (!empty($data['colaboradorId'])) {
                        $colaborador = validarColaborador($conn, $data['colaboradorId']);
                        if (!$colaborador) {
                            jsonResponse(['error' => 'El colaborador seleccionado no existe o fue eliminado'], 400);
                        }
                        $colaboradorId = $colaborador['id'];
                        $colaboradorNombre = $colaborador['nombre'];
                    } elseif (isset($concepto['requiere_colaborador']) && $concepto['requiere_colaborador']) {
                        jsonResponse(['error' => 'Debe seleccionar un colaborador para este concepto'], 400);
                    }

                    // Validar sucursal según configuración del concepto
                    if (!empty($data['sucursalId'])) {
                        $sucursal = validarSucursal($conn, $data['sucursalId']);
                        if (!$sucursal) {
                            jsonResponse(['error' => 'La sucursal seleccionada no existe o fue eliminada'], 400);
                        }
                        $sucursalId = $sucursal['id'];
                        $sucursalNombre = $sucursal['nombre'];
                    } elseif (isset($concepto['requiere_sucursal']) && $concepto['requiere_sucursal']) {
                        jsonResponse(['error' => 'Debe seleccionar una sucursal para este concepto'], 400);
                    }

                    // Validar proveedor si el concepto es "Depósito"
                    if (conceptoRequiereProveedor($conceptoNombre)) {
                        if (empty($data['proveedorId'])) {
                            jsonResponse(['error' => 'Debe seleccionar un proveedor para el depósito'], 400);
                        }
                        $proveedor = validarProveedor($conn, $data['proveedorId']);
                        if (!$proveedor) {
                            jsonResponse(['error' => 'El proveedor seleccionado no existe o fue eliminado'], 400);
                        }
                        $proveedorId = $proveedor['id'];
                        $proveedorNombre = $proveedor['nombre'];
                    }

                    // ============== LÓGICA DE RESERVAS ==============
                    // Caso 1: concepto.es_reserva=1 → APORTE a una reserva. Resta de caja, suma a reserva.
                    //   - Si el concepto tiene reserva AUTO-VINCULADA (1:1) → se usa esa, sin pedir más.
                    //   - Si no (concepto legacy con es_reserva=1 sin vínculo) → se pide reservaId del body.
                    // Caso 2: data['pagarDesdeReserva']=true → GASTO desde reserva. NO toca caja, resta de reserva.
                    // Mutuamente excluyentes.
                    $esConceptoReserva = !empty($concepto['es_reserva']);
                    $pagarDesdeReserva = !empty($data['pagarDesdeReserva']);

                    if ($esConceptoReserva && $pagarDesdeReserva) {
                        jsonResponse(['error' => 'No se puede usar el concepto de aporte a reserva y pagar desde reserva al mismo tiempo'], 400);
                    }

                    if ($esConceptoReserva) {
                        // APORTE a reserva: buscar primero la reserva AUTO-VINCULADA al concepto
                        $stmtRV = $conn->prepare("SELECT id, nombre FROM reservas WHERE concepto_id = :cid AND activo = 1 LIMIT 1");
                        $stmtRV->execute([':cid' => intval($concepto['id'])]);
                        $reservaVinculada = $stmtRV->fetch();

                        if ($reservaVinculada) {
                            // Auto-vinculada: ignoramos lo que mande el body, la verdad es del backend
                            $reservaId = (int)$reservaVinculada['id'];
                            $reservaNombre = $reservaVinculada['nombre'];
                        } else {
                            // Legacy: pedir reservaId del body (concepto sin vínculo 1:1)
                            if (empty($data['reservaId'])) {
                                jsonResponse(['error' => 'Debe seleccionar a qué reserva se aporta'], 400);
                            }
                            $reserva = validarReserva($conn, $data['reservaId']);
                            if (!$reserva) {
                                jsonResponse(['error' => 'La reserva seleccionada no existe o fue eliminada'], 400);
                            }
                            $reservaId = $reserva['id'];
                            $reservaNombre = $reserva['nombre'];
                        }
                        $reservaAccion = 'aporte';
                    } elseif ($pagarDesdeReserva) {
                        // GASTO desde reserva
                        if (empty($data['reservaId'])) {
                            jsonResponse(['error' => 'Debe seleccionar de qué reserva se paga'], 400);
                        }
                        $reserva = validarReserva($conn, $data['reservaId']);
                        if (!$reserva) {
                            jsonResponse(['error' => 'La reserva seleccionada no existe o fue eliminada'], 400);
                        }
                        // Validar que la reserva tenga saldo suficiente
                        $saldoReserva = calcularSaldoReserva($conn, $reserva['id']);
                        if ($monto > $saldoReserva + 0.001) { // tolerancia de redondeo
                            jsonResponse([
                                'error' => "Saldo insuficiente en la reserva '{$reserva['nombre']}': disponible $" . number_format($saldoReserva, 2, ',', '.') . ", se intenta gastar $" . number_format($monto, 2, ',', '.') . "."
                            ], 400);
                        }
                        $reservaId = $reserva['id'];
                        $reservaNombre = $reserva['nombre'];
                        $reservaAccion = 'gasto';
                    }
                }

                // ==================== RETIROS DE CAJA (solo para ingresos) ====================
                // Se aceptan VARIOS retiros por ingreso, en `retiros` (lista).
                // Por compatibilidad se sigue aceptando el formato viejo de un único
                // retiro suelto (montoRetiro / conceptoRetiroId / observacionRetiro),
                // por si algún navegador quedó con la versión anterior cacheada.
                $retirosValidados = [];

                if ($data['tipo'] === 'ingreso') {
                    $retirosEntrada = [];

                    if (!empty($data['retiros']) && is_array($data['retiros'])) {
                        $retirosEntrada = $data['retiros'];
                    } elseif (isset($data['montoRetiro'])) {
                        $retirosEntrada = [[
                            'monto' => $data['montoRetiro'],
                            'conceptoId' => $data['conceptoRetiroId'] ?? null,
                            'observacion' => $data['observacionRetiro'] ?? null,
                        ]];
                    }

                    if (count($retirosEntrada) > 20) {
                        jsonResponse(['error' => 'No se pueden cargar más de 20 retiros en un mismo ingreso'], 400);
                    }

                    $totalRetiros = 0;
                    foreach ($retirosEntrada as $i => $r) {
                        $n = $i + 1;
                        $montoR = isset($r['monto']) ? floatval($r['monto']) : 0;

                        if ($montoR < 0) {
                            jsonResponse(['error' => "El monto del retiro {$n} no puede ser negativo"], 400);
                        }
                        if ($montoR == 0) {
                            continue; // fila vacía: se ignora
                        }
                        if (empty($r['conceptoId'])) {
                            jsonResponse(['error' => "Debe seleccionar un concepto para el retiro {$n}"], 400);
                        }

                        $conceptoR = validarConcepto($conn, $r['conceptoId']);
                        if (!$conceptoR) {
                            jsonResponse(['error' => "El concepto del retiro {$n} no existe o fue eliminado"], 400);
                        }
                        if (!empty($conceptoR['requiere_colaborador'])) {
                            jsonResponse(['error' => "El concepto '{$conceptoR['nombre']}' (retiro {$n}) requiere un colaborador. Registrelo como egreso normal."], 400);
                        }

                        $totalRetiros += $montoR;

                        // Si el concepto requiere sucursal, se reusa la del ingreso padre
                        $retirosValidados[] = [
                            'monto' => round($montoR, 2),
                            'concepto_id' => $conceptoR['id'],
                            'concepto_nombre' => $conceptoR['nombre'],
                            'observacion' => isset($r['observacion']) ? trim(substr($r['observacion'], 0, 255)) : null,
                        ];
                    }

                    // La suma de TODOS los retiros no puede llegar al cierre de caja:
                    // el ingreso físico real quedaría en cero o negativo.
                    if ($totalRetiros > 0 && $totalRetiros >= $monto) {
                        jsonResponse([
                            'error' => 'La suma de los retiros ($' . number_format($totalRetiros, 2, ',', '.') .
                                       ') debe ser menor al monto de cierre de caja ($' . number_format($monto, 2, ',', '.') . ')'
                        ], 400);
                    }
                }

                // Insertar movimiento principal (saldo temporal, se recalculará)
                $stmt = $conn->prepare("
                    INSERT INTO movimientos (fecha, tipo, sucursal_id, sucursal_nombre, concepto_id, concepto_nombre, colaborador_id, colaborador_nombre, proveedor_id, proveedor_nombre, reserva_id, reserva_nombre, reserva_accion, monto, observacion, saldo, origen)
                    VALUES (:fecha, :tipo, :sucursal_id, :sucursal_nombre, :concepto_id, :concepto_nombre, :colaborador_id, :colaborador_nombre, :proveedor_id, :proveedor_nombre, :reserva_id, :reserva_nombre, :reserva_accion, :monto, :observacion, 0, 'normal')
                ");

                $stmt->execute([
                    ':fecha' => $fecha,
                    ':tipo' => $data['tipo'],
                    ':sucursal_id' => $sucursalId,
                    ':sucursal_nombre' => $sucursalNombre,
                    ':concepto_id' => $conceptoId,
                    ':concepto_nombre' => $conceptoNombre,
                    ':colaborador_id' => $colaboradorId,
                    ':colaborador_nombre' => $colaboradorNombre,
                    ':proveedor_id' => $proveedorId,
                    ':proveedor_nombre' => $proveedorNombre,
                    ':reserva_id' => $reservaId,
                    ':reserva_nombre' => $reservaNombre,
                    ':reserva_accion' => $reservaAccion,
                    ':monto' => $monto,
                    ':observacion' => $observacion
                ]);

                $insertId = $conn->lastInsertId();

                // Cada retiro se guarda como un egreso vinculado al ingreso.
                // Heredan la fecha y la sucursal del ingreso padre.
                if (!empty($retirosValidados)) {
                    try {
                        $stmtR = $conn->prepare("
                            INSERT INTO movimientos (fecha, tipo, sucursal_id, sucursal_nombre, concepto_id, concepto_nombre, monto, observacion, saldo, movimiento_padre_id, origen)
                            VALUES (:fecha, 'egreso', :sucursal_id, :sucursal_nombre, :concepto_id, :concepto_nombre, :monto, :observacion, 0, :padre_id, 'caja')
                        ");
                        foreach ($retirosValidados as $r) {
                            $stmtR->execute([
                                ':fecha' => $fecha,
                                ':sucursal_id' => $sucursalId,
                                ':sucursal_nombre' => $sucursalNombre,
                                ':concepto_id' => $r['concepto_id'],
                                ':concepto_nombre' => $r['concepto_nombre'],
                                ':monto' => $r['monto'],
                                ':observacion' => $r['observacion'],
                                ':padre_id' => $insertId
                            ]);
                        }
                    } catch (Exception $e) {
                        // Si falla algún retiro, se revierte TODO (el ingreso y los retiros
                        // ya insertados) para no dejar un ingreso a medio cargar.
                        $conn->prepare("DELETE FROM movimientos WHERE id = :id OR movimiento_padre_id = :padre_id")
                             ->execute([':id' => $insertId, ':padre_id' => $insertId]);
                        jsonResponse(['error' => 'Error al registrar los retiros: ' . $e->getMessage()], 500);
                    }
                }

                // Recalcular saldos desde la fecha del movimiento en adelante.
                // Las filas anteriores no pueden verse afectadas por un alta posterior.
                recalcularSaldos($conn, $fecha);

                // Obtener el saldo actual correcto
                $stmt = $conn->query("SELECT saldo FROM movimientos ORDER BY fecha DESC, id DESC LIMIT 1");
                $row = $stmt->fetch();
                $saldoActual = $row ? floatval($row['saldo']) : 0;

                jsonResponse([
                    'success' => true,
                    'id' => $insertId,
                    'saldo' => $saldoActual,
                    'retiros' => count($retirosValidados)
                ]);
            }
            break;

        // ==================== MOVIMIENTO INDIVIDUAL ====================
        case 'movimiento':
            $id = $_GET['id'] ?? 0;

            if ($method === 'GET') {
                // Obtener un movimiento por ID
                if (empty($id)) {
                    jsonResponse(['error' => 'ID requerido'], 400);
                }
                $stmt = $conn->prepare("SELECT * FROM movimientos WHERE id = :id");
                $stmt->execute([':id' => $id]);
                $mov = $stmt->fetch();
                if (!$mov) {
                    jsonResponse(['error' => 'Movimiento no encontrado'], 404);
                }
                jsonResponse($mov);
            }

            if ($method === 'PUT') {
                // Actualizar movimiento
                $data = json_decode(file_get_contents('php://input'), true);

                // Validaciones
                if (empty($data['fecha']) || !isset($data['monto'])) {
                    jsonResponse(['error' => 'Faltan datos requeridos'], 400);
                }

                $monto = floatval($data['monto']);
                if ($monto <= 0) {
                    jsonResponse(['error' => 'El monto debe ser mayor a cero'], 400);
                }

                // Validar formato de fecha
                $fecha = $data['fecha'];
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
                    jsonResponse(['error' => 'Formato de fecha inválido'], 400);
                }

                // Sanitizar observación
                $observacion = isset($data['observacion']) ? trim(substr($data['observacion'], 0, 255)) : null;

                // Verificar que el movimiento exista (cargar también los campos de reserva para validaciones de cambio)
                $stmtTipo = $conn->prepare("SELECT fecha, tipo, origen, reserva_id, reserva_nombre, reserva_accion FROM movimientos WHERE id = :id");
                $stmtTipo->execute([':id' => $id]);
                $movActual = $stmtTipo->fetch();

                if (!$movActual) {
                    jsonResponse(['error' => 'El movimiento no existe'], 404);
                }

                // Bloquear edición de egresos de caja (vinculados a un ingreso padre)
                if (isset($movActual['origen']) && $movActual['origen'] === 'caja') {
                    jsonResponse(['error' => 'Los egresos directos de caja no se pueden editar. Elimine el ingreso padre para borrarlos.'], 400);
                }

                // BLOQUEO: si el movimiento está vinculado a una reserva INACTIVA, no permitir editar
                // (Editar movimientos de reservas eliminadas puede romper la matemática global)
                if (!empty($movActual['reserva_id'])) {
                    $stmtRes = $conn->prepare("SELECT activo FROM reservas WHERE id = :id");
                    $stmtRes->execute([':id' => $movActual['reserva_id']]);
                    $resCheck = $stmtRes->fetch();
                    if ($resCheck && intval($resCheck['activo']) === 0) {
                        jsonResponse([
                            'error' => "Este movimiento pertenece a la reserva '{$movActual['reserva_nombre']}' que fue ELIMINADA. No se puede modificar el histórico de reservas eliminadas (rompería la matemática). Si necesitás cambiarlo, recreá la reserva primero."
                        ], 400);
                    }
                }

                // Usar el tipo enviado desde el frontend (permite cambiar tipo)
                // Si no se envía tipo, usar el tipo original
                $tipoMov = isset($data['tipo']) && in_array($data['tipo'], ['ingreso', 'egreso'])
                    ? $data['tipo']
                    : $movActual['tipo'];

                $sucursalId = null;
                $sucursalNombre = null;
                $conceptoId = null;
                $conceptoNombre = null;
                $colaboradorId = null;
                $colaboradorNombre = null;
                $proveedorId = null;
                $proveedorNombre = null;
                $reservaId = null;
                $reservaNombre = null;
                $reservaAccion = null;

                if ($tipoMov === 'ingreso') {
                    // Para ingresos, validar sucursal obligatoria
                    if (empty($data['sucursalId'])) {
                        jsonResponse(['error' => 'Debe seleccionar una sucursal para el ingreso'], 400);
                    }
                    $sucursal = validarSucursal($conn, $data['sucursalId']);
                    if (!$sucursal) {
                        jsonResponse(['error' => 'La sucursal seleccionada no existe o fue eliminada'], 400);
                    }
                    // BLOQUEO ESTRICTO: máximo 1 ingreso por sucursal por fecha — excluyendo el id actual (es el que se está editando)
                    $stmtUniqEdit = $conn->prepare("SELECT id, monto FROM movimientos WHERE tipo = 'ingreso' AND fecha = :fecha AND sucursal_id = :sucursalId AND id != :id LIMIT 1");
                    $stmtUniqEdit->execute([
                        ':fecha' => $fecha,
                        ':sucursalId' => intval($sucursal['id']),
                        ':id' => intval($id)
                    ]);
                    $ingresoExistenteEdit = $stmtUniqEdit->fetch();
                    if ($ingresoExistenteEdit) {
                        jsonResponse([
                            'error' => "Ya existe otro ingreso para la sucursal '{$sucursal['nombre']}' en la fecha {$fecha} (monto: $" . number_format($ingresoExistenteEdit['monto'], 2, ',', '.') . "). No se permite duplicar la rendición."
                        ], 409);
                    }
                    $sucursalId = $sucursal['id'];
                    $sucursalNombre = $sucursal['nombre'];
                } else {
                    // Para egresos, validar concepto obligatorio
                    if (empty($data['conceptoId'])) {
                        jsonResponse(['error' => 'Debe seleccionar un concepto para el egreso'], 400);
                    }
                    $concepto = validarConcepto($conn, $data['conceptoId']);
                    if (!$concepto) {
                        jsonResponse(['error' => 'El concepto seleccionado no existe o fue eliminado'], 400);
                    }
                    $conceptoId = $concepto['id'];
                    $conceptoNombre = $concepto['nombre'];

                    // Validar colaborador según configuración del concepto
                    if (!empty($data['colaboradorId'])) {
                        $colaborador = validarColaborador($conn, $data['colaboradorId']);
                        if (!$colaborador) {
                            jsonResponse(['error' => 'El colaborador seleccionado no existe o fue eliminado'], 400);
                        }
                        $colaboradorId = $colaborador['id'];
                        $colaboradorNombre = $colaborador['nombre'];
                    } elseif (isset($concepto['requiere_colaborador']) && $concepto['requiere_colaborador']) {
                        jsonResponse(['error' => 'Debe seleccionar un colaborador para este concepto'], 400);
                    }

                    // Validar sucursal según configuración del concepto
                    if (!empty($data['sucursalId'])) {
                        $sucursal = validarSucursal($conn, $data['sucursalId']);
                        if (!$sucursal) {
                            jsonResponse(['error' => 'La sucursal seleccionada no existe o fue eliminada'], 400);
                        }
                        $sucursalId = $sucursal['id'];
                        $sucursalNombre = $sucursal['nombre'];
                    } elseif (isset($concepto['requiere_sucursal']) && $concepto['requiere_sucursal']) {
                        jsonResponse(['error' => 'Debe seleccionar una sucursal para este concepto'], 400);
                    }

                    // Validar proveedor si el concepto es "Depósito"
                    if (conceptoRequiereProveedor($conceptoNombre)) {
                        if (empty($data['proveedorId'])) {
                            jsonResponse(['error' => 'Debe seleccionar un proveedor para el depósito'], 400);
                        }
                        $proveedor = validarProveedor($conn, $data['proveedorId']);
                        if (!$proveedor) {
                            jsonResponse(['error' => 'El proveedor seleccionado no existe o fue eliminado'], 400);
                        }
                        $proveedorId = $proveedor['id'];
                        $proveedorNombre = $proveedor['nombre'];
                    }

                    // ============== LÓGICA DE RESERVAS (PUT) ==============
                    $esConceptoReserva = !empty($concepto['es_reserva']);
                    $pagarDesdeReserva = !empty($data['pagarDesdeReserva']);

                    if ($esConceptoReserva && $pagarDesdeReserva) {
                        jsonResponse(['error' => 'No se puede usar el concepto de aporte a reserva y pagar desde reserva al mismo tiempo'], 400);
                    }

                    if ($esConceptoReserva) {
                        // APORTE: buscar reserva AUTO-VINCULADA al concepto
                        $stmtRV = $conn->prepare("SELECT id, nombre FROM reservas WHERE concepto_id = :cid AND activo = 1 LIMIT 1");
                        $stmtRV->execute([':cid' => intval($concepto['id'])]);
                        $reservaVinculada = $stmtRV->fetch();

                        if ($reservaVinculada) {
                            $reservaIdResolved = (int)$reservaVinculada['id'];
                            $reservaNombreResolved = $reservaVinculada['nombre'];
                        } else {
                            // Legacy: pedir del body
                            if (empty($data['reservaId'])) {
                                jsonResponse(['error' => 'Debe seleccionar a qué reserva se aporta'], 400);
                            }
                            $reservaTmp = validarReserva($conn, $data['reservaId']);
                            if (!$reservaTmp) {
                                jsonResponse(['error' => 'La reserva seleccionada no existe o fue eliminada'], 400);
                            }
                            $reservaIdResolved = (int)$reservaTmp['id'];
                            $reservaNombreResolved = $reservaTmp['nombre'];
                        }

                        // Validar que al modificar este aporte no se deje a la reserva en negativo
                        $saldoSinEste = calcularSaldoReserva($conn, $reservaIdResolved, $id);
                        $saldoSimulado = $saldoSinEste + $monto;
                        if ($saldoSimulado < -0.001) {
                            jsonResponse([
                                'error' => "La modificación dejaría la reserva '{$reservaNombreResolved}' en saldo negativo: $" . number_format($saldoSimulado, 2, ',', '.') . "."
                            ], 400);
                        }
                        $reservaId = $reservaIdResolved;
                        $reservaNombre = $reservaNombreResolved;
                        $reservaAccion = 'aporte';
                    } elseif ($pagarDesdeReserva) {
                        // GASTO desde reserva
                        if (empty($data['reservaId'])) {
                            jsonResponse(['error' => 'Debe seleccionar de qué reserva se paga'], 400);
                        }
                        $reserva = validarReserva($conn, $data['reservaId']);
                        if (!$reserva) {
                            jsonResponse(['error' => 'La reserva seleccionada no existe o fue eliminada'], 400);
                        }
                        $saldoSinEste = calcularSaldoReserva($conn, $reserva['id'], $id);
                        if ($monto > $saldoSinEste + 0.001) {
                            jsonResponse([
                                'error' => "Saldo insuficiente en la reserva '{$reserva['nombre']}': disponible $" . number_format($saldoSinEste, 2, ',', '.') . ", se intenta gastar $" . number_format($monto, 2, ',', '.') . "."
                            ], 400);
                        }
                        $reservaId = $reserva['id'];
                        $reservaNombre = $reserva['nombre'];
                        $reservaAccion = 'gasto';
                    }
                }

                // ============== VALIDACIÓN DE RESERVA "ORIGEN" (anti-fantasma) ==============
                // Si el movimiento ORIGINAL era un APORTE a una reserva, y el resultado de la edición
                // hace que ya NO sea aporte a esa misma reserva (cambia concepto, tipo, o reserva destino),
                // entonces ese aporte "se va" de la reserva original. Hay que validar que la reserva
                // original no quede negativa (si tiene gastos asociados que dependían de ese aporte).
                if (
                    $movActual['reserva_id'] !== null
                    && $movActual['reserva_accion'] === 'aporte'
                ) {
                    $reservaIdOriginal = intval($movActual['reserva_id']);
                    $sigueSiendoAporteAMismaReserva = (
                        $reservaAccion === 'aporte'
                        && $reservaId !== null
                        && intval($reservaId) === $reservaIdOriginal
                    );
                    if (!$sigueSiendoAporteAMismaReserva) {
                        // Calcular saldo de la reserva ORIGINAL excluyendo este movimiento (porque va a "salir")
                        $saldoOrigSinEste = calcularSaldoReserva($conn, $reservaIdOriginal, $id);
                        if ($saldoOrigSinEste < -0.001) {
                            jsonResponse([
                                'error' => "Esta edición sacaría el aporte de la reserva '{$movActual['reserva_nombre']}', pero esa reserva tiene gastos posteriores. Quedaría con saldo $" . number_format($saldoOrigSinEste, 2, ',', '.') . ". Eliminá primero los gastos asociados."
                            ], 400);
                        }
                    }
                }
                // Si el movimiento ORIGINAL era un GASTO desde reserva, removerlo solo aumenta saldo
                // (no puede romper nada). No requiere validación.

                $stmt = $conn->prepare("
                    UPDATE movimientos SET
                        fecha = :fecha,
                        tipo = :tipo,
                        sucursal_id = :sucursal_id,
                        sucursal_nombre = :sucursal_nombre,
                        concepto_id = :concepto_id,
                        concepto_nombre = :concepto_nombre,
                        colaborador_id = :colaborador_id,
                        colaborador_nombre = :colaborador_nombre,
                        proveedor_id = :proveedor_id,
                        proveedor_nombre = :proveedor_nombre,
                        reserva_id = :reserva_id,
                        reserva_nombre = :reserva_nombre,
                        reserva_accion = :reserva_accion,
                        monto = :monto,
                        observacion = :observacion
                    WHERE id = :id
                ");

                $stmt->execute([
                    ':id' => $id,
                    ':fecha' => $fecha,
                    ':tipo' => $tipoMov,
                    ':sucursal_id' => $sucursalId,
                    ':sucursal_nombre' => $sucursalNombre,
                    ':concepto_id' => $conceptoId,
                    ':concepto_nombre' => $conceptoNombre,
                    ':colaborador_id' => $colaboradorId,
                    ':colaborador_nombre' => $colaboradorNombre,
                    ':proveedor_id' => $proveedorId,
                    ':proveedor_nombre' => $proveedorNombre,
                    ':reserva_id' => $reservaId,
                    ':reserva_nombre' => $reservaNombre,
                    ':reserva_accion' => $reservaAccion,
                    ':monto' => $monto,
                    ':observacion' => $observacion
                ]);

                // Recalcular saldos desde la fecha MÁS ANTIGUA entre la original y la nueva:
                // si la edición movió la fecha hacia atrás, hay que rehacer desde ahí;
                // si la movió hacia adelante, el tramo viejo también quedó desactualizado.
                $fechaOriginal = $movActual['fecha'] ?? null;
                $desde = ($fechaOriginal && $fechaOriginal < $fecha) ? $fechaOriginal : $fecha;
                recalcularSaldos($conn, $desde);

                // Obtener nuevo saldo (por fecha cronológica)
                $stmt = $conn->query("SELECT saldo FROM movimientos ORDER BY fecha DESC, id DESC LIMIT 1");
                $row = $stmt->fetch();
                $saldo = $row ? floatval($row['saldo']) : 0;

                jsonResponse(['success' => true, 'saldo' => $saldo]);

            } elseif ($method === 'DELETE') {
                // PROTECCIONES DE RESERVA antes de eliminar:
                $stmtMov = $conn->prepare("SELECT id, fecha, reserva_id, reserva_nombre, reserva_accion, monto FROM movimientos WHERE id = :id");
                $stmtMov->execute([':id' => $id]);
                $movDel = $stmtMov->fetch();

                if ($movDel && !empty($movDel['reserva_id'])) {
                    // 1. Bloquear si la reserva fue eliminada (movimiento huérfano)
                    $stmtRes = $conn->prepare("SELECT activo FROM reservas WHERE id = :id");
                    $stmtRes->execute([':id' => $movDel['reserva_id']]);
                    $resCheck = $stmtRes->fetch();
                    if ($resCheck && intval($resCheck['activo']) === 0) {
                        jsonResponse([
                            'error' => "Este movimiento pertenece a la reserva '{$movDel['reserva_nombre']}' que fue ELIMINADA. No se puede modificar el histórico de reservas eliminadas (rompería la matemática). Si necesitás revertirlo, recreá la reserva primero."
                        ], 400);
                    }
                }

                // 2. Si era APORTE: validar que la reserva no quede negativa al sacarlo
                if ($movDel && $movDel['reserva_accion'] === 'aporte' && $movDel['reserva_id']) {
                    $saldoSinEste = calcularSaldoReserva($conn, $movDel['reserva_id'], $id);
                    if ($saldoSinEste < -0.001) {
                        jsonResponse([
                            'error' => "No se puede eliminar este aporte porque la reserva '{$movDel['reserva_nombre']}' quedaría en saldo negativo: $" . number_format($saldoSinEste, 2, ',', '.') . ". Eliminá primero los gastos asociados."
                        ], 400);
                    }
                }

                // Guardar la fecha ANTES de borrar: es el punto desde el cual hay que rehacer
                // el acumulado. Los hijos de caja comparten la fecha del padre.
                $fechaBorrada = $movDel['fecha'] ?? null;

                // Eliminar movimiento (y sus hijos de caja en cascada si los tiene)
                $stmt = $conn->prepare("DELETE FROM movimientos WHERE id = :id OR movimiento_padre_id = :padre_id");
                $stmt->execute([':id' => $id, ':padre_id' => $id]);

                // Recalcular saldos desde la fecha del movimiento eliminado
                recalcularSaldos($conn, $fechaBorrada);

                // Obtener nuevo saldo (por fecha cronológica)
                $stmt = $conn->query("SELECT saldo FROM movimientos ORDER BY fecha DESC, id DESC LIMIT 1");
                $row = $stmt->fetch();
                $saldo = $row ? floatval($row['saldo']) : 0;

                jsonResponse(['success' => true, 'saldo' => $saldo]);
            }
            break;

        // ==================== ÚLTIMOS MOVIMIENTOS ====================
        // IMPORTANTE: se ordena por `id DESC` (orden REAL de carga), NO por `fecha`.
        // Si se ordenara por fecha, un movimiento cargado hoy con fecha retroactiva
        // (ej. una rendición atrasada) nunca aparecería en la lista, porque quedaría
        // por debajo de los movimientos con fecha más nueva ya existentes.
        // `id` es AUTO_INCREMENT = orden de inserción, y usa la PRIMARY KEY (sin filesort).
        case 'ultimos':
            $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;
            $limit = max(1, min($limit, 100)); // clamp defensivo
            $stmt = $conn->prepare("SELECT * FROM movimientos ORDER BY id DESC LIMIT :limit");
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            jsonResponse($stmt->fetchAll());
            break;

        // ==================== SALDO (liviano, para el header) ====================
        // Endpoint mínimo: evita pedir `init` completo (7 queries) sólo para leer el saldo.
        case 'saldo':
            $stmt = $conn->query("SELECT saldo FROM movimientos ORDER BY fecha DESC, id DESC LIMIT 1");
            $row = $stmt->fetch();
            $saldo = $row ? floatval($row['saldo']) : 0;

            $stmt = $conn->query("SELECT fecha FROM movimientos WHERE tipo = 'ingreso' ORDER BY fecha DESC LIMIT 1");
            $row = $stmt->fetch();
            $ultimoIngreso = $row ? $row['fecha'] : null;

            jsonResponse(['saldo' => $saldo, 'ultimoIngreso' => $ultimoIngreso]);
            break;

        // ==================== SUCURSALES ====================
        case 'sucursales':
            if ($method === 'GET') {
                $stmt = $conn->query("SELECT id, nombre, rinde_caja FROM sucursales WHERE activo = 1 ORDER BY nombre");
                jsonResponse($stmt->fetchAll());

            } elseif ($method === 'POST') {
                $data = json_decode(file_get_contents('php://input'), true);

                // Validar nombre
                $nombre = isset($data['nombre']) ? trim($data['nombre']) : '';
                if (empty($nombre)) {
                    jsonResponse(['error' => 'El nombre no puede estar vacío'], 400);
                }
                if (strlen($nombre) > 100) {
                    jsonResponse(['error' => 'El nombre es demasiado largo (máx 100 caracteres)'], 400);
                }

                // Verificar duplicado
                $stmt = $conn->prepare("SELECT id FROM sucursales WHERE nombre = :nombre AND activo = 1");
                $stmt->execute([':nombre' => $nombre]);
                if ($stmt->fetch()) {
                    jsonResponse(['error' => 'Ya existe una sucursal con ese nombre'], 400);
                }

                $stmt = $conn->prepare("INSERT INTO sucursales (nombre) VALUES (:nombre)");
                $stmt->execute([':nombre' => $nombre]);
                jsonResponse(['success' => true, 'id' => $conn->lastInsertId()]);
            }
            break;

        case 'sucursal':
            $id = $_GET['id'] ?? 0;

            if ($method === 'DELETE') {
                // Marcar movimientos con esta sucursal
                $stmt = $conn->prepare("SELECT nombre FROM sucursales WHERE id = :id");
                $stmt->execute([':id' => $id]);
                $sucursal = $stmt->fetch();

                if ($sucursal) {
                    $stmt = $conn->prepare("
                        UPDATE movimientos 
                        SET sucursal_id = NULL, sucursal_nombre = CONCAT(sucursal_nombre, ' (eliminada)')
                        WHERE sucursal_id = :id
                    ");
                    $stmt->execute([':id' => $id]);
                }

                // Desactivar sucursal
                $stmt = $conn->prepare("UPDATE sucursales SET activo = 0 WHERE id = :id");
                $stmt->execute([':id' => $id]);

                jsonResponse(['success' => true]);
            }
            break;

        // ==================== CONCEPTOS ====================
        case 'conceptos':
            if ($method === 'GET') {
                $stmt = $conn->query("SELECT id, nombre, requiere_colaborador, requiere_sucursal, es_reserva FROM conceptos WHERE activo = 1 ORDER BY nombre");
                jsonResponse($stmt->fetchAll());

            } elseif ($method === 'POST') {
                $data = json_decode(file_get_contents('php://input'), true);

                // Validar nombre
                $nombre = isset($data['nombre']) ? trim($data['nombre']) : '';
                if (empty($nombre)) {
                    jsonResponse(['error' => 'El nombre no puede estar vacío'], 400);
                }
                if (strlen($nombre) > 100) {
                    jsonResponse(['error' => 'El nombre es demasiado largo (máx 100 caracteres)'], 400);
                }

                // Verificar duplicado
                $stmt = $conn->prepare("SELECT id FROM conceptos WHERE nombre = :nombre AND activo = 1");
                $stmt->execute([':nombre' => $nombre]);
                if ($stmt->fetch()) {
                    jsonResponse(['error' => 'Ya existe un concepto con ese nombre'], 400);
                }

                $stmt = $conn->prepare("INSERT INTO conceptos (nombre) VALUES (:nombre)");
                $stmt->execute([':nombre' => $nombre]);
                jsonResponse(['success' => true, 'id' => $conn->lastInsertId()]);
            }
            break;

        case 'concepto':
            $id = $_GET['id'] ?? 0;

            if ($method === 'DELETE') {
                // BLOQUEO: si este concepto está vinculado a una reserva activa, no se puede borrar desde acá
                $stmtVinc = $conn->prepare("SELECT id, nombre FROM reservas WHERE concepto_id = :id AND activo = 1 LIMIT 1");
                $stmtVinc->execute([':id' => $id]);
                $resVinc = $stmtVinc->fetch();
                if ($resVinc) {
                    jsonResponse([
                        'error' => "Este concepto está vinculado a la reserva '{$resVinc['nombre']}'. Para eliminarlo, primero eliminá la reserva desde la sección Reservas."
                    ], 400);
                }

                // Marcar movimientos con este concepto
                $stmt = $conn->prepare("SELECT nombre FROM conceptos WHERE id = :id");
                $stmt->execute([':id' => $id]);
                $concepto = $stmt->fetch();

                if ($concepto) {
                    $stmt = $conn->prepare("
                        UPDATE movimientos
                        SET concepto_id = NULL, concepto_nombre = CONCAT(concepto_nombre, ' (eliminado)')
                        WHERE concepto_id = :id
                    ");
                    $stmt->execute([':id' => $id]);
                }

                // Desactivar concepto
                $stmt = $conn->prepare("UPDATE conceptos SET activo = 0 WHERE id = :id");
                $stmt->execute([':id' => $id]);

                jsonResponse(['success' => true]);
            }
            break;

        // ==================== COLABORADORES ====================
        case 'colaboradores':
            if ($method === 'GET') {
                $stmt = $conn->query("SELECT id, nombre FROM colaboradores WHERE activo = 1 ORDER BY nombre");
                jsonResponse($stmt->fetchAll());

            } elseif ($method === 'POST') {
                $data = json_decode(file_get_contents('php://input'), true);

                $nombre = isset($data['nombre']) ? trim($data['nombre']) : '';
                if (empty($nombre)) {
                    jsonResponse(['error' => 'El nombre no puede estar vacío'], 400);
                }
                if (strlen($nombre) > 100) {
                    jsonResponse(['error' => 'El nombre es demasiado largo (máx 100 caracteres)'], 400);
                }

                // Verificar duplicado
                $stmt = $conn->prepare("SELECT id FROM colaboradores WHERE nombre = :nombre AND activo = 1");
                $stmt->execute([':nombre' => $nombre]);
                if ($stmt->fetch()) {
                    jsonResponse(['error' => 'Ya existe un colaborador con ese nombre'], 400);
                }

                $stmt = $conn->prepare("INSERT INTO colaboradores (nombre) VALUES (:nombre)");
                $stmt->execute([':nombre' => $nombre]);
                jsonResponse(['success' => true, 'id' => $conn->lastInsertId()]);
            }
            break;

        case 'colaborador':
            $id = $_GET['id'] ?? 0;

            if ($method === 'DELETE') {
                $stmt = $conn->prepare("SELECT nombre FROM colaboradores WHERE id = :id");
                $stmt->execute([':id' => $id]);
                $colaborador = $stmt->fetch();

                if ($colaborador) {
                    $stmt = $conn->prepare("
                        UPDATE movimientos
                        SET colaborador_id = NULL, colaborador_nombre = CONCAT(colaborador_nombre, ' (eliminado)')
                        WHERE colaborador_id = :id
                    ");
                    $stmt->execute([':id' => $id]);
                }

                $stmt = $conn->prepare("UPDATE colaboradores SET activo = 0 WHERE id = :id");
                $stmt->execute([':id' => $id]);

                jsonResponse(['success' => true]);
            }
            break;

        // ==================== PROVEEDORES ====================
        case 'proveedores':
            if ($method === 'GET') {
                $stmt = $conn->query("SELECT id, nombre FROM proveedores WHERE activo = 1 ORDER BY nombre");
                jsonResponse($stmt->fetchAll());

            } elseif ($method === 'POST') {
                $data = json_decode(file_get_contents('php://input'), true);

                $nombre = isset($data['nombre']) ? trim($data['nombre']) : '';
                if (empty($nombre)) {
                    jsonResponse(['error' => 'El nombre no puede estar vacío'], 400);
                }
                if (strlen($nombre) > 100) {
                    jsonResponse(['error' => 'El nombre es demasiado largo (máx 100 caracteres)'], 400);
                }

                // Verificar duplicado
                $stmt = $conn->prepare("SELECT id FROM proveedores WHERE nombre = :nombre AND activo = 1");
                $stmt->execute([':nombre' => $nombre]);
                if ($stmt->fetch()) {
                    jsonResponse(['error' => 'Ya existe un proveedor con ese nombre'], 400);
                }

                $stmt = $conn->prepare("INSERT INTO proveedores (nombre) VALUES (:nombre)");
                $stmt->execute([':nombre' => $nombre]);
                jsonResponse(['success' => true, 'id' => $conn->lastInsertId()]);
            }
            break;

        case 'proveedor':
            $id = $_GET['id'] ?? 0;

            if (!is_numeric($id) || intval($id) <= 0) {
                jsonResponse(['error' => 'ID de proveedor inválido'], 400);
            }
            $id = intval($id);

            if ($method === 'PUT') {
                // Renombrar un proveedor existente
                $data = json_decode(file_get_contents('php://input'), true);
                $nombre = isset($data['nombre']) ? trim($data['nombre']) : '';

                if (empty($nombre)) {
                    jsonResponse(['error' => 'El nombre no puede estar vacío'], 400);
                }
                if (strlen($nombre) > 100) {
                    jsonResponse(['error' => 'El nombre es demasiado largo (máx 100 caracteres)'], 400);
                }

                $stmt = $conn->prepare("SELECT nombre FROM proveedores WHERE id = :id AND activo = 1");
                $stmt->execute([':id' => $id]);
                $actual = $stmt->fetch();
                if (!$actual) {
                    jsonResponse(['error' => 'El proveedor no existe o fue eliminado'], 404);
                }

                // Que no choque con OTRO proveedor activo
                $stmt = $conn->prepare("SELECT id FROM proveedores WHERE nombre = :nombre AND activo = 1 AND id <> :id");
                $stmt->execute([':nombre' => $nombre, ':id' => $id]);
                if ($stmt->fetch()) {
                    jsonResponse(['error' => 'Ya existe otro proveedor con ese nombre'], 400);
                }

                if ($actual['nombre'] === $nombre) {
                    jsonResponse(['success' => true, 'sinCambios' => true, 'movimientosActualizados' => 0]);
                }

                try {
                    $conn->beginTransaction();

                    $conn->prepare("UPDATE proveedores SET nombre = :nombre WHERE id = :id")
                         ->execute([':nombre' => $nombre, ':id' => $id]);

                    // CRÍTICO: `movimientos` guarda el nombre desnormalizado en
                    // proveedor_nombre, y `reporte-egresos` agrupa por esa columna.
                    // Sin propagar el cambio, el mismo proveedor aparecería partido
                    // en dos filas del reporte (nombre viejo y nombre nuevo).
                    $stmtMov = $conn->prepare("UPDATE movimientos SET proveedor_nombre = :nombre WHERE proveedor_id = :id");
                    $stmtMov->execute([':nombre' => $nombre, ':id' => $id]);
                    $afectados = $stmtMov->rowCount();

                    $conn->commit();

                    jsonResponse([
                        'success' => true,
                        'nombreAnterior' => $actual['nombre'],
                        'movimientosActualizados' => $afectados
                    ]);
                } catch (Exception $e) {
                    if ($conn->inTransaction()) {
                        $conn->rollBack();
                    }
                    throw $e;
                }
            }

            if ($method === 'DELETE') {
                $stmt = $conn->prepare("SELECT nombre FROM proveedores WHERE id = :id");
                $stmt->execute([':id' => $id]);
                $proveedor = $stmt->fetch();

                if ($proveedor) {
                    $stmt = $conn->prepare("
                        UPDATE movimientos
                        SET proveedor_id = NULL, proveedor_nombre = CONCAT(proveedor_nombre, ' (eliminado)')
                        WHERE proveedor_id = :id
                    ");
                    $stmt->execute([':id' => $id]);
                }

                $stmt = $conn->prepare("UPDATE proveedores SET activo = 0 WHERE id = :id");
                $stmt->execute([':id' => $id]);

                jsonResponse(['success' => true]);
            }
            break;

        // ==================== RESERVAS ====================
        case 'reservas':
            if ($method === 'GET') {
                // Devuelve reservas activas con saldo calculado y concepto vinculado
                $stmt = $conn->query("
                    SELECT r.id, r.nombre, r.descripcion, r.concepto_id,
                        COALESCE(SUM(CASE WHEN m.reserva_accion = 'aporte' THEN m.monto ELSE 0 END), 0) -
                        COALESCE(SUM(CASE WHEN m.reserva_accion = 'gasto' THEN m.monto ELSE 0 END), 0) AS saldo,
                        COUNT(CASE WHEN m.reserva_accion = 'aporte' THEN 1 END) AS cantidad_aportes,
                        COUNT(CASE WHEN m.reserva_accion = 'gasto' THEN 1 END) AS cantidad_gastos
                    FROM reservas r
                    LEFT JOIN movimientos m ON m.reserva_id = r.id
                    WHERE r.activo = 1
                    GROUP BY r.id, r.nombre, r.descripcion, r.concepto_id
                    ORDER BY r.nombre
                ");
                jsonResponse($stmt->fetchAll());

            } elseif ($method === 'POST') {
                $data = json_decode(file_get_contents('php://input'), true);

                $nombre = isset($data['nombre']) ? trim($data['nombre']) : '';
                $descripcion = isset($data['descripcion']) ? trim(substr($data['descripcion'], 0, 255)) : null;
                if ($descripcion === '') $descripcion = null;

                if (empty($nombre)) {
                    jsonResponse(['error' => 'El nombre no puede estar vacío'], 400);
                }
                if (strlen($nombre) > 100) {
                    jsonResponse(['error' => 'El nombre es demasiado largo (máx 100 caracteres)'], 400);
                }

                // Nombre del concepto auto-creado (con prefijo claro)
                $nombreConcepto = "Aporte Reserva: " . $nombre;
                if (strlen($nombreConcepto) > 100) {
                    jsonResponse(['error' => 'El nombre es demasiado largo (máx 84 caracteres por el prefijo "Aporte Reserva: ")'], 400);
                }

                // Verificar duplicado de RESERVA
                $stmt = $conn->prepare("SELECT id FROM reservas WHERE nombre = :nombre AND activo = 1");
                $stmt->execute([':nombre' => $nombre]);
                if ($stmt->fetch()) {
                    jsonResponse(['error' => 'Ya existe una reserva con ese nombre'], 400);
                }

                // Verificar duplicado de CONCEPTO con el nombre auto-generado
                $stmt = $conn->prepare("SELECT id FROM conceptos WHERE nombre = :nombre AND activo = 1");
                $stmt->execute([':nombre' => $nombreConcepto]);
                if ($stmt->fetch()) {
                    jsonResponse(['error' => "Ya existe un concepto activo con el nombre '{$nombreConcepto}'. Usá otro nombre para la reserva."], 400);
                }

                // Transacción atómica: o se crean ambos, o ninguno
                try {
                    $conn->beginTransaction();

                    // 1. Insertar concepto auto-marcado como reserva
                    $stmt = $conn->prepare("INSERT INTO conceptos (nombre, es_reserva) VALUES (:nombre, 1)");
                    $stmt->execute([':nombre' => $nombreConcepto]);
                    $conceptoId = (int)$conn->lastInsertId();

                    // 2. Insertar reserva con vínculo al concepto
                    $stmt = $conn->prepare("INSERT INTO reservas (nombre, descripcion, concepto_id) VALUES (:nombre, :descripcion, :concepto_id)");
                    $stmt->execute([
                        ':nombre' => $nombre,
                        ':descripcion' => $descripcion,
                        ':concepto_id' => $conceptoId
                    ]);
                    $reservaId = (int)$conn->lastInsertId();

                    $conn->commit();

                    jsonResponse([
                        'success' => true,
                        'id' => $reservaId,
                        'conceptoId' => $conceptoId,
                        'conceptoNombre' => $nombreConcepto
                    ]);
                } catch (Exception $e) {
                    if ($conn->inTransaction()) {
                        $conn->rollBack();
                    }
                    jsonResponse(['error' => 'Error al crear reserva: ' . $e->getMessage()], 500);
                }
            }
            break;

        case 'reserva':
            $id = $_GET['id'] ?? 0;

            if ($method === 'DELETE') {
                $stmt = $conn->prepare("SELECT id, nombre, concepto_id FROM reservas WHERE id = :id AND activo = 1");
                $stmt->execute([':id' => $id]);
                $reserva = $stmt->fetch();

                if (!$reserva) {
                    jsonResponse(['error' => 'La reserva no existe'], 404);
                }

                // No permitir eliminar si tiene saldo > 0 (hay que vaciarla primero)
                $saldoActual = calcularSaldoReserva($conn, $id);
                if (round($saldoActual, 2) > 0) {
                    jsonResponse([
                        'error' => "No se puede eliminar la reserva '{$reserva['nombre']}' porque tiene saldo $" . number_format($saldoActual, 2, ',', '.') . ". Primero gastá o liberá el saldo."
                    ], 400);
                }

                // Transacción atómica: desactivar reserva + concepto vinculado
                try {
                    $conn->beginTransaction();

                    // Marcar movimientos huérfanos (solo nombre, mantener id para histórico)
                    $stmt = $conn->prepare("
                        UPDATE movimientos
                        SET reserva_nombre = CONCAT(IFNULL(reserva_nombre, ''), ' (eliminada)')
                        WHERE reserva_id = :id
                    ");
                    $stmt->execute([':id' => $id]);

                    // Desactivar recordatorios
                    $stmt = $conn->prepare("UPDATE recordatorios_reserva SET activo = 0 WHERE reserva_id = :id");
                    $stmt->execute([':id' => $id]);

                    // Soft-delete reserva
                    $stmt = $conn->prepare("UPDATE reservas SET activo = 0 WHERE id = :id");
                    $stmt->execute([':id' => $id]);

                    // Soft-delete concepto vinculado (si existe)
                    if (!empty($reserva['concepto_id'])) {
                        $stmt = $conn->prepare("UPDATE conceptos SET activo = 0 WHERE id = :id AND es_reserva = 1");
                        $stmt->execute([':id' => $reserva['concepto_id']]);
                    }

                    $conn->commit();
                    jsonResponse(['success' => true]);
                } catch (Exception $e) {
                    if ($conn->inTransaction()) $conn->rollBack();
                    jsonResponse(['error' => 'Error al eliminar reserva: ' . $e->getMessage()], 500);
                }
            }
            break;

        // ==================== RECORDATORIOS DE RESERVA ====================
        case 'recordatorios-reserva':
            if ($method === 'GET') {
                // Lista todos los recordatorios configurados (activos)
                $stmt = $conn->query("
                    SELECT rr.id, rr.reserva_id, r.nombre AS reserva_nombre, rr.dia_mes, rr.monto_sugerido, rr.nota, rr.activo
                    FROM recordatorios_reserva rr
                    JOIN reservas r ON r.id = rr.reserva_id
                    WHERE rr.activo = 1 AND r.activo = 1
                    ORDER BY rr.dia_mes, r.nombre
                ");
                jsonResponse($stmt->fetchAll());

            } elseif ($method === 'POST') {
                $data = json_decode(file_get_contents('php://input'), true);

                $reservaId = isset($data['reservaId']) ? intval($data['reservaId']) : 0;
                $diaMes = isset($data['diaMes']) ? intval($data['diaMes']) : 0;
                $montoSugerido = isset($data['montoSugerido']) && $data['montoSugerido'] !== '' ? floatval($data['montoSugerido']) : null;
                $nota = isset($data['nota']) ? trim(substr($data['nota'], 0, 255)) : null;
                if ($nota === '') $nota = null;

                // Validaciones
                if ($diaMes < 1 || $diaMes > 31) {
                    jsonResponse(['error' => 'El día del mes debe estar entre 1 y 31'], 400);
                }
                if ($montoSugerido !== null && $montoSugerido < 0) {
                    jsonResponse(['error' => 'El monto sugerido no puede ser negativo'], 400);
                }
                $reserva = validarReserva($conn, $reservaId);
                if (!$reserva) {
                    jsonResponse(['error' => 'La reserva seleccionada no existe o fue eliminada'], 400);
                }

                $stmt = $conn->prepare("
                    INSERT INTO recordatorios_reserva (reserva_id, dia_mes, monto_sugerido, nota)
                    VALUES (:reservaId, :diaMes, :monto, :nota)
                ");
                $stmt->execute([
                    ':reservaId' => $reservaId,
                    ':diaMes' => $diaMes,
                    ':monto' => $montoSugerido,
                    ':nota' => $nota
                ]);
                jsonResponse(['success' => true, 'id' => $conn->lastInsertId()]);
            }
            break;

        case 'recordatorio-reserva':
            $id = $_GET['id'] ?? 0;

            if ($method === 'DELETE') {
                $stmt = $conn->prepare("UPDATE recordatorios_reserva SET activo = 0 WHERE id = :id");
                $stmt->execute([':id' => $id]);
                // También limpiar descartados de este recordatorio (ya no aplican)
                $stmt = $conn->prepare("DELETE FROM recordatorios_descartados WHERE recordatorio_id = :id");
                $stmt->execute([':id' => $id]);
                jsonResponse(['success' => true]);
            }
            break;

        // Recordatorios "activos hoy": día actual ≥ dia_mes Y no descartados este mes
        case 'recordatorios-activos':
            $hoy = new DateTime('today');
            $anio = (int)$hoy->format('Y');
            $mes = (int)$hoy->format('m');
            $diaActual = (int)$hoy->format('d');

            // Días en el mes actual (para manejar día 31 en febrero, etc.)
            $ultimoDiaMes = (int)$hoy->format('t');

            $stmt = $conn->prepare("
                SELECT rr.id AS recordatorio_id, rr.reserva_id, r.nombre AS reserva_nombre,
                       rr.dia_mes, rr.monto_sugerido, rr.nota
                FROM recordatorios_reserva rr
                JOIN reservas r ON r.id = rr.reserva_id
                WHERE rr.activo = 1 AND r.activo = 1
                  AND rr.id NOT IN (
                      SELECT recordatorio_id FROM recordatorios_descartados
                      WHERE anio = :anio AND mes = :mes
                  )
            ");
            $stmt->execute([':anio' => $anio, ':mes' => $mes]);
            $todos = $stmt->fetchAll();

            // Filtrar por día: si dia_mes <= diaActual, mostrar.
            // Si dia_mes > último día del mes (ej: 31 en feb), mostrarlo el último día.
            $activos = [];
            foreach ($todos as $r) {
                $diaConfigurado = intval($r['dia_mes']);
                $diaEfectivo = min($diaConfigurado, $ultimoDiaMes);
                if ($diaActual >= $diaEfectivo) {
                    $r['dia_efectivo'] = $diaEfectivo;
                    $activos[] = $r;
                }
            }
            jsonResponse($activos);
            break;

        case 'recordatorio-descartar':
            // POST con recordatorioId → marca descartado para el mes actual
            if ($method === 'POST') {
                $data = json_decode(file_get_contents('php://input'), true);
                $recId = isset($data['recordatorioId']) ? intval($data['recordatorioId']) : 0;
                if ($recId <= 0) {
                    jsonResponse(['error' => 'ID de recordatorio inválido'], 400);
                }
                $hoy = new DateTime('today');
                $anio = (int)$hoy->format('Y');
                $mes = (int)$hoy->format('m');

                try {
                    $stmt = $conn->prepare("
                        INSERT INTO recordatorios_descartados (recordatorio_id, anio, mes)
                        VALUES (:rec, :anio, :mes)
                    ");
                    $stmt->execute([':rec' => $recId, ':anio' => $anio, ':mes' => $mes]);
                } catch (PDOException $e) {
                    // Duplicado (ya estaba descartado) → ignorar silenciosamente
                    if ($e->getCode() != 23000) throw $e;
                }
                jsonResponse(['success' => true]);
            }
            break;

        // ==================== ANOTACIONES / RECORDATORIOS ====================
        case 'anotaciones':
            if ($method === 'GET') {
                $stmt = $conn->query("SELECT id, titulo, mensaje, created_at FROM anotaciones WHERE activo = 1 ORDER BY created_at DESC, id DESC");
                jsonResponse($stmt->fetchAll());

            } elseif ($method === 'POST') {
                $data = json_decode(file_get_contents('php://input'), true);

                $titulo = isset($data['titulo']) ? trim($data['titulo']) : '';
                $mensaje = isset($data['mensaje']) ? trim(substr($data['mensaje'], 0, 500)) : null;
                if ($mensaje === '') $mensaje = null;

                if (empty($titulo)) {
                    jsonResponse(['error' => 'El título es obligatorio'], 400);
                }
                if (strlen($titulo) > 120) {
                    jsonResponse(['error' => 'El título es demasiado largo (máx 120 caracteres)'], 400);
                }

                $stmt = $conn->prepare("INSERT INTO anotaciones (titulo, mensaje) VALUES (:titulo, :mensaje)");
                $stmt->execute([':titulo' => $titulo, ':mensaje' => $mensaje]);

                // Devolver la anotación recién creada para que el frontend la agregue sin refetch
                $newId = (int)$conn->lastInsertId();
                $stmt = $conn->prepare("SELECT id, titulo, mensaje, created_at FROM anotaciones WHERE id = :id");
                $stmt->execute([':id' => $newId]);
                $nueva = $stmt->fetch();

                jsonResponse(['success' => true, 'anotacion' => $nueva]);
            }
            break;

        case 'anotacion':
            $id = $_GET['id'] ?? 0;
            if ($method === 'DELETE') {
                if (empty($id) || !is_numeric($id)) {
                    jsonResponse(['error' => 'ID inválido'], 400);
                }
                $stmt = $conn->prepare("UPDATE anotaciones SET activo = 0 WHERE id = :id");
                $stmt->execute([':id' => intval($id)]);
                jsonResponse(['success' => true]);
            }
            break;

        // ==================== HISTORIAL DE UNA RESERVA ====================
        case 'reserva-movimientos':
            $reservaId = $_GET['id'] ?? 0;
            if (empty($reservaId) || !is_numeric($reservaId)) {
                jsonResponse(['error' => 'ID de reserva inválido'], 400);
            }
            $stmt = $conn->prepare("
                SELECT id, fecha, tipo, concepto_nombre, monto, observacion, reserva_accion, created_at
                FROM movimientos
                WHERE reserva_id = :id
                ORDER BY fecha DESC, id DESC
            ");
            $stmt->execute([':id' => intval($reservaId)]);
            $movs = $stmt->fetchAll();
            $saldo = calcularSaldoReserva($conn, $reservaId);
            jsonResponse([
                'movimientos' => $movs,
                'saldo' => $saldo
            ]);
            break;

        // ==================== DÍAS CERRADOS ====================
        case 'dias-cerrados':
            if ($method === 'GET') {
                $stmt = $conn->query("
                    SELECT dc.id, dc.sucursal_id, s.nombre as sucursal_nombre, dc.fecha, dc.motivo
                    FROM dias_cerrados dc
                    JOIN sucursales s ON s.id = dc.sucursal_id
                    ORDER BY dc.fecha DESC
                ");
                jsonResponse($stmt->fetchAll());

            } elseif ($method === 'POST') {
                $data = json_decode(file_get_contents('php://input'), true);

                // Acepta sucursalIds (array) o sucursalId (single) para compatibilidad
                $sucursalIds = [];
                if (!empty($data['sucursalIds']) && is_array($data['sucursalIds'])) {
                    $sucursalIds = array_map('intval', $data['sucursalIds']);
                } elseif (!empty($data['sucursalId'])) {
                    $sucursalIds = [intval($data['sucursalId'])];
                }

                if (empty($sucursalIds)) {
                    jsonResponse(['error' => 'Debe seleccionar al menos una sucursal'], 400);
                }

                // Acepta fechaDesde/fechaHasta o fecha única
                $fechaDesde = $data['fechaDesde'] ?? $data['fecha'] ?? null;
                $fechaHasta = $data['fechaHasta'] ?? $fechaDesde;

                if (!$fechaDesde) {
                    jsonResponse(['error' => 'Debe seleccionar una fecha'], 400);
                }
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaDesde) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaHasta)) {
                    jsonResponse(['error' => 'Formato de fecha inválido'], 400);
                }

                $dDesde = new DateTime($fechaDesde);
                $dHasta = new DateTime($fechaHasta);
                if ($dHasta < $dDesde) {
                    jsonResponse(['error' => 'La fecha hasta debe ser mayor o igual a fecha desde'], 400);
                }
                $diasRango = $dDesde->diff($dHasta)->days + 1;
                if ($diasRango > 365) {
                    jsonResponse(['error' => 'El rango no puede superar 365 días'], 400);
                }

                $motivo = isset($data['motivo']) ? trim(substr($data['motivo'], 0, 255)) : null;
                if ($motivo === '') $motivo = null;

                // Validar todas las sucursales primero
                $sucursalesValidas = [];
                foreach ($sucursalIds as $sid) {
                    $suc = validarSucursal($conn, $sid);
                    if (!$suc) {
                        jsonResponse(['error' => 'Una de las sucursales no existe o fue eliminada'], 400);
                    }
                    $sucursalesValidas[$suc['id']] = $suc['nombre'];
                }

                // Generar todas las fechas del rango
                $fechas = [];
                $cursor = clone $dDesde;
                while ($cursor <= $dHasta) {
                    $fechas[] = $cursor->format('Y-m-d');
                    $cursor->modify('+1 day');
                }

                // Insertar todos los pares sucursal × fecha, ignorando duplicados
                $creados = [];
                $duplicados = 0;
                $stmtIns = $conn->prepare("INSERT INTO dias_cerrados (sucursal_id, fecha, motivo) VALUES (:sid, :fecha, :motivo)");

                foreach ($sucursalesValidas as $sid => $snombre) {
                    foreach ($fechas as $f) {
                        try {
                            $stmtIns->execute([':sid' => $sid, ':fecha' => $f, ':motivo' => $motivo]);
                            $creados[] = [
                                'id' => (int)$conn->lastInsertId(),
                                'sucursal_id' => $sid,
                                'sucursal_nombre' => $snombre,
                                'fecha' => $f,
                                'motivo' => $motivo
                            ];
                        } catch (PDOException $e) {
                            // Duplicado (UNIQUE constraint violation) → skip silencioso
                            if ($e->getCode() == 23000) {
                                $duplicados++;
                            } else {
                                throw $e;
                            }
                        }
                    }
                }

                jsonResponse([
                    'success' => true,
                    'creados' => $creados,
                    'totalCreados' => count($creados),
                    'duplicados' => $duplicados
                ]);
            }
            break;

        case 'dia-cerrado':
            $id = $_GET['id'] ?? 0;

            if ($method === 'DELETE') {
                $stmt = $conn->prepare("DELETE FROM dias_cerrados WHERE id = :id");
                $stmt->execute([':id' => $id]);
                jsonResponse(['success' => true]);
            }
            break;

        // ==================== RENDICIONES FALTANTES ====================
        case 'rendiciones-faltantes':
            $diasAtras = isset($_GET['diasAtras']) ? intval($_GET['diasAtras']) : 30;
            if ($diasAtras < 1 || $diasAtras > 365) $diasAtras = 30;

            $hoy = new DateTime('today');
            $ayer = (clone $hoy)->modify('-1 day');
            $desde = (clone $hoy)->modify("-{$diasAtras} days");

            $fechaDesdeStr = $desde->format('Y-m-d');
            $fechaHastaStr = $ayer->format('Y-m-d');

            // Si el rango es inválido (no hay días pasados), retornar vacío
            if ($desde > $ayer) {
                jsonResponse([]);
                break;
            }

            // Sucursales activas que rinden caja
            $stmt = $conn->query("SELECT id, nombre FROM sucursales WHERE activo = 1 AND rinde_caja = 1");
            $sucursales = $stmt->fetchAll();

            if (empty($sucursales)) {
                jsonResponse([]);
                break;
            }

            // Ingresos existentes por sucursal/fecha en el rango
            $stmt = $conn->prepare("
                SELECT DISTINCT sucursal_id, fecha
                FROM movimientos
                WHERE tipo = 'ingreso'
                  AND sucursal_id IS NOT NULL
                  AND fecha BETWEEN :desde AND :hasta
            ");
            $stmt->execute([':desde' => $fechaDesdeStr, ':hasta' => $fechaHastaStr]);
            $rendidos = [];
            foreach ($stmt->fetchAll() as $r) {
                $rendidos[$r['sucursal_id'] . '|' . $r['fecha']] = true;
            }

            // Días cerrados por sucursal/fecha en el rango
            $stmt = $conn->prepare("
                SELECT sucursal_id, fecha
                FROM dias_cerrados
                WHERE fecha BETWEEN :desde AND :hasta
            ");
            $stmt->execute([':desde' => $fechaDesdeStr, ':hasta' => $fechaHastaStr]);
            $cerrados = [];
            foreach ($stmt->fetchAll() as $c) {
                $cerrados[$c['sucursal_id'] . '|' . $c['fecha']] = true;
            }

            // Iterar día a día y armar faltantes
            $faltantes = [];
            $cursor = clone $desde;
            while ($cursor <= $ayer) {
                // DAYOFWEEK: domingo=0 en PHP DateTime->format('w')
                $dow = (int)$cursor->format('w');
                if ($dow !== 0) { // no domingo
                    $fechaStr = $cursor->format('Y-m-d');
                    foreach ($sucursales as $suc) {
                        $clave = $suc['id'] . '|' . $fechaStr;
                        if (isset($cerrados[$clave])) continue;
                        if (isset($rendidos[$clave])) continue;
                        $faltantes[] = [
                            'fecha' => $fechaStr,
                            'sucursal_id' => $suc['id'],
                            'sucursal_nombre' => $suc['nombre']
                        ];
                    }
                }
                $cursor->modify('+1 day');
            }

            // Ordenar por fecha DESC, luego sucursal
            usort($faltantes, function ($a, $b) {
                if ($a['fecha'] !== $b['fecha']) return strcmp($b['fecha'], $a['fecha']);
                return strcmp($a['sucursal_nombre'], $b['sucursal_nombre']);
            });

            jsonResponse($faltantes);
            break;

        // ==================== CONFIGURACIÓN DE SUCURSALES ====================
        case 'sucursal-config':
            $id = $_GET['id'] ?? 0;

            if ($method === 'PUT') {
                $data = json_decode(file_get_contents('php://input'), true);

                if (!isset($data['rinde_caja'])) {
                    jsonResponse(['error' => 'Falta el campo rinde_caja'], 400);
                }

                $rinde = $data['rinde_caja'] ? 1 : 0;
                $stmt = $conn->prepare("UPDATE sucursales SET rinde_caja = :rinde WHERE id = :id AND activo = 1");
                $stmt->execute([':rinde' => $rinde, ':id' => $id]);

                jsonResponse(['success' => true]);
            }
            break;

        // ==================== CONFIGURACIÓN DE CONCEPTOS ====================
        case 'concepto-config':
            $id = $_GET['id'] ?? 0;

            if ($method === 'PUT') {
                $data = json_decode(file_get_contents('php://input'), true);

                $sets = [];
                $params = [':id' => $id];

                if (isset($data['requiere_colaborador'])) {
                    $sets[] = "requiere_colaborador = :req_colab";
                    $params[':req_colab'] = $data['requiere_colaborador'] ? 1 : 0;
                }
                if (isset($data['requiere_sucursal'])) {
                    $sets[] = "requiere_sucursal = :req_suc";
                    $params[':req_suc'] = $data['requiere_sucursal'] ? 1 : 0;
                }
                if (isset($data['es_reserva'])) {
                    $sets[] = "es_reserva = :es_res";
                    $params[':es_res'] = $data['es_reserva'] ? 1 : 0;
                }

                if (!empty($sets)) {
                    $sql = "UPDATE conceptos SET " . implode(', ', $sets) . " WHERE id = :id AND activo = 1";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute($params);
                }

                jsonResponse(['success' => true]);
            }
            break;

        // ==================== REPORTES ====================
        case 'reporte-ingresos':
            $where = "tipo = 'ingreso'";
            $params = [];

            if (!empty($_GET['fechaDesde'])) {
                $where .= " AND fecha >= :fechaDesde";
                $params[':fechaDesde'] = $_GET['fechaDesde'];
            }
            if (!empty($_GET['fechaHasta'])) {
                $where .= " AND fecha <= :fechaHasta";
                $params[':fechaHasta'] = $_GET['fechaHasta'];
            }
            if (!empty($_GET['sucursalId'])) {
                $where .= " AND sucursal_id = :sucursalId";
                $params[':sucursalId'] = $_GET['sucursalId'];
            }

            // Mismo filtro de asentamiento que la tabla, para que el total y los
            // gráficos no queden desfasados del listado.
            aplicarFiltroAsentamiento($where, $params);

            // Por mes
            $sql = "SELECT DATE_FORMAT(fecha, '%Y-%m') as mes, SUM(monto) as total
                    FROM movimientos WHERE $where GROUP BY mes ORDER BY mes";
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $porMes = $stmt->fetchAll();

            // Por sucursal
            $sql = "SELECT sucursal_nombre as sucursal, SUM(monto) as total 
                    FROM movimientos WHERE $where AND sucursal_nombre IS NOT NULL
                    GROUP BY sucursal_nombre ORDER BY total DESC";
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $porSucursal = $stmt->fetchAll();

            // Total
            $sql = "SELECT SUM(monto) as total FROM movimientos WHERE $where";
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $total = $stmt->fetch()['total'] ?? 0;

            jsonResponse([
                'porMes' => $porMes,
                'porSucursal' => $porSucursal,
                'total' => floatval($total)
            ]);
            break;

        case 'reporte-egresos':
            $where = "tipo = 'egreso'";
            $params = [];

            if (!empty($_GET['fechaDesde'])) {
                $where .= " AND fecha >= :fechaDesde";
                $params[':fechaDesde'] = $_GET['fechaDesde'];
            }
            if (!empty($_GET['fechaHasta'])) {
                $where .= " AND fecha <= :fechaHasta";
                $params[':fechaHasta'] = $_GET['fechaHasta'];
            }
            if (!empty($_GET['conceptoId'])) {
                $where .= " AND concepto_id = :conceptoId";
                $params[':conceptoId'] = $_GET['conceptoId'];
            }
            if (!empty($_GET['colaboradorId'])) {
                $where .= " AND colaborador_id = :colaboradorId";
                $params[':colaboradorId'] = $_GET['colaboradorId'];
            }
            if (!empty($_GET['proveedorId'])) {
                $where .= " AND proveedor_id = :proveedorId";
                $params[':proveedorId'] = $_GET['proveedorId'];
            }

            // Mismo filtro de asentamiento que la tabla, para que el total y los
            // gráficos no queden desfasados del listado.
            aplicarFiltroAsentamiento($where, $params);

            // Por mes
            $sql = "SELECT DATE_FORMAT(fecha, '%Y-%m') as mes, SUM(monto) as total
                    FROM movimientos WHERE $where GROUP BY mes ORDER BY mes";
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $porMes = $stmt->fetchAll();

            // Por concepto
            $sql = "SELECT concepto_nombre as concepto, SUM(monto) as total
                    FROM movimientos WHERE $where AND concepto_nombre IS NOT NULL
                    GROUP BY concepto_nombre ORDER BY total DESC";
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $porConcepto = $stmt->fetchAll();

            // Por proveedor
            $sql = "SELECT proveedor_nombre as proveedor, SUM(monto) as total
                    FROM movimientos WHERE $where AND proveedor_nombre IS NOT NULL
                    GROUP BY proveedor_nombre ORDER BY total DESC";
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $porProveedor = $stmt->fetchAll();

            // Total
            $sql = "SELECT SUM(monto) as total FROM movimientos WHERE $where";
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $total = $stmt->fetch()['total'] ?? 0;

            jsonResponse([
                'porMes' => $porMes,
                'porConcepto' => $porConcepto,
                'porProveedor' => $porProveedor,
                'total' => floatval($total)
            ]);
            break;

        // ==================== VERIFICAR INGRESO EXISTENTE ====================
        // Devuelve si ya existe un ingreso para esa sucursal+fecha. Soporta excluirId para edición.
        case 'existe-ingreso':
            $fechaCheck = $_GET['fecha'] ?? '';
            $sucursalIdCheck = $_GET['sucursalId'] ?? '';
            $excluirId = $_GET['excluirId'] ?? null;

            // Validaciones estrictas
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaCheck) || !is_numeric($sucursalIdCheck)) {
                jsonResponse(['exists' => false]);
                break;
            }

            $sqlCheck = "SELECT id, monto, sucursal_nombre, observacion, created_at FROM movimientos
                         WHERE tipo = 'ingreso' AND fecha = :fecha AND sucursal_id = :sucursalId";
            $paramsCheck = [
                ':fecha' => $fechaCheck,
                ':sucursalId' => intval($sucursalIdCheck)
            ];

            if ($excluirId !== null && is_numeric($excluirId)) {
                $sqlCheck .= " AND id != :excluirId";
                $paramsCheck[':excluirId'] = intval($excluirId);
            }

            $sqlCheck .= " LIMIT 1";

            $stmtCheck = $conn->prepare($sqlCheck);
            $stmtCheck->execute($paramsCheck);
            $rowCheck = $stmtCheck->fetch();

            jsonResponse([
                'exists' => $rowCheck !== false,
                'ingreso' => $rowCheck ?: null
            ]);
            break;

        // ==================== REPORTE GENERAL ====================
        case 'reporte-general':
            $where = "1=1";
            $params = [];
            $prevWhere = "1=1";
            $prevParams = [];

            // Calcular período anterior automáticamente
            $fechaDesde = $_GET['fechaDesde'] ?? null;
            $fechaHasta = $_GET['fechaHasta'] ?? null;

            if ($fechaDesde && $fechaHasta) {
                $where .= " AND fecha >= :fechaDesde AND fecha <= :fechaHasta";
                $params[':fechaDesde'] = $fechaDesde;
                $params[':fechaHasta'] = $fechaHasta;

                // Calcular período anterior de la misma duración
                $d1 = new DateTime($fechaDesde);
                $d2 = new DateTime($fechaHasta);
                $dias = $d1->diff($d2)->days + 1;
                $prevHasta = (clone $d1)->modify('-1 day')->format('Y-m-d');
                $prevDesde = (clone $d1)->modify("-{$dias} days")->format('Y-m-d');
                $prevWhere .= " AND fecha >= :prevDesde AND fecha <= :prevHasta";
                $prevParams[':prevDesde'] = $prevDesde;
                $prevParams[':prevHasta'] = $prevHasta;
            } elseif ($fechaDesde) {
                $where .= " AND fecha >= :fechaDesde";
                $params[':fechaDesde'] = $fechaDesde;
            } elseif ($fechaHasta) {
                $where .= " AND fecha <= :fechaHasta";
                $params[':fechaHasta'] = $fechaHasta;
            }

            // Filtro adicional para egresos (origen: normal, caja, o todos)
            $origenEgreso = $_GET['origenEgreso'] ?? null;
            $egresoOrigenWhere = "";
            $egresoOrigenParam = [];
            if ($origenEgreso === 'normal' || $origenEgreso === 'caja') {
                $egresoOrigenWhere = " AND origen = :origenEgreso";
                $egresoOrigenParam[':origenEgreso'] = $origenEgreso;
            }

            // Filtro de concepto: aplica a TODAS las queries (los ingresos no tienen concepto_id, así que con filtro activo se zeroan automáticamente — es el comportamiento deseado)
            $conceptoIdFiltro = $_GET['conceptoId'] ?? null;
            $conceptoWhere = "";
            $conceptoParam = [];
            if (!empty($conceptoIdFiltro) && is_numeric($conceptoIdFiltro)) {
                $conceptoWhere = " AND concepto_id = :conceptoIdFiltro";
                $conceptoParam[':conceptoIdFiltro'] = intval($conceptoIdFiltro);
            }

            // Filtro de sucursal: aplica a TODAS las queries (ingresos y egresos con sucursal_id = X)
            $sucursalIdFiltro = $_GET['sucursalId'] ?? null;
            $sucursalWhere = "";
            $sucursalParam = [];
            if (!empty($sucursalIdFiltro) && is_numeric($sucursalIdFiltro)) {
                $sucursalWhere = " AND sucursal_id = :sucursalIdFiltro";
                $sucursalParam[':sucursalIdFiltro'] = intval($sucursalIdFiltro);
            }

            // ---- PERÍODO ACTUAL ----
            // Helpers para combinar filtros consistentemente:
            // - INGRESO: sucursal + concepto (concepto zeroa porque ingresos tienen NULL concepto_id)
            // - EGRESO: sucursal + concepto + origen + EXCLUIR gastos desde reserva
            //   (los gastos desde reserva NO afectan caja → no deben contarse como egreso del flujo de caja)
            $excluirGastoReserva = " AND (reserva_accion IS NULL OR reserva_accion = 'aporte')";
            $ingFilter = $sucursalWhere . $conceptoWhere;
            $ingParams = array_merge($sucursalParam, $conceptoParam);
            $egrFilter = $sucursalWhere . $conceptoWhere . $egresoOrigenWhere . $excluirGastoReserva;
            $egrParams = array_merge($sucursalParam, $conceptoParam, $egresoOrigenParam);

            // Total ingresos
            $sql = "SELECT SUM(monto) as total FROM movimientos WHERE $where AND tipo = 'ingreso'" . $ingFilter;
            $stmt = $conn->prepare($sql);
            $stmt->execute(array_merge($params, $ingParams));
            $totalIngresos = floatval($stmt->fetch()['total'] ?? 0);

            // Total egresos
            $sql = "SELECT SUM(monto) as total FROM movimientos WHERE $where AND tipo = 'egreso'" . $egrFilter;
            $stmt = $conn->prepare($sql);
            $stmt->execute(array_merge($params, $egrParams));
            $totalEgresos = floatval($stmt->fetch()['total'] ?? 0);

            // Ingresos por mes
            $sql = "SELECT DATE_FORMAT(fecha, '%Y-%m') as mes, SUM(monto) as total
                    FROM movimientos WHERE $where AND tipo = 'ingreso'" . $ingFilter . "
                    GROUP BY mes ORDER BY mes";
            $stmt = $conn->prepare($sql);
            $stmt->execute(array_merge($params, $ingParams));
            $ingresosPorMes = $stmt->fetchAll();

            // Egresos por mes
            $sql = "SELECT DATE_FORMAT(fecha, '%Y-%m') as mes, SUM(monto) as total
                    FROM movimientos WHERE $where AND tipo = 'egreso'" . $egrFilter . "
                    GROUP BY mes ORDER BY mes";
            $stmt = $conn->prepare($sql);
            $stmt->execute(array_merge($params, $egrParams));
            $egresosPorMes = $stmt->fetchAll();

            // Egresos por concepto
            $sql = "SELECT concepto_nombre as concepto, SUM(monto) as total
                    FROM movimientos WHERE $where AND tipo = 'egreso' AND concepto_nombre IS NOT NULL" . $egrFilter . "
                    GROUP BY concepto_nombre ORDER BY total DESC";
            $stmt = $conn->prepare($sql);
            $stmt->execute(array_merge($params, $egrParams));
            $egresosPorConcepto = $stmt->fetchAll();

            // Ingresos por sucursal
            $sql = "SELECT sucursal_nombre as sucursal, SUM(monto) as total
                    FROM movimientos WHERE $where AND tipo = 'ingreso' AND sucursal_nombre IS NOT NULL" . $ingFilter . "
                    GROUP BY sucursal_nombre ORDER BY total DESC";
            $stmt = $conn->prepare($sql);
            $stmt->execute(array_merge($params, $ingParams));
            $ingresosPorSucursal = $stmt->fetchAll();

            // Egresos por colaborador (top 10)
            $sql = "SELECT colaborador_nombre as colaborador, SUM(monto) as total
                    FROM movimientos WHERE $where AND tipo = 'egreso' AND colaborador_nombre IS NOT NULL" . $egrFilter . "
                    GROUP BY colaborador_nombre ORDER BY total DESC LIMIT 10";
            $stmt = $conn->prepare($sql);
            $stmt->execute(array_merge($params, $egrParams));
            $egresosPorColaborador = $stmt->fetchAll();

            // Egresos por proveedor (top 10)
            $sql = "SELECT proveedor_nombre as proveedor, SUM(monto) as total
                    FROM movimientos WHERE $where AND tipo = 'egreso' AND proveedor_nombre IS NOT NULL" . $egrFilter . "
                    GROUP BY proveedor_nombre ORDER BY total DESC LIMIT 10";
            $stmt = $conn->prepare($sql);
            $stmt->execute(array_merge($params, $egrParams));
            $egresosPorProveedor = $stmt->fetchAll();

            // Total egresos DIRECTOS DE CAJA (origen='caja' hardcoded; aplica sucursal + concepto)
            $sql = "SELECT SUM(monto) as total FROM movimientos WHERE $where AND tipo = 'egreso' AND origen = 'caja'" . $sucursalWhere . $conceptoWhere;
            $stmt = $conn->prepare($sql);
            $stmt->execute(array_merge($params, $sucursalParam, $conceptoParam));
            $totalEgresosCaja = floatval($stmt->fetch()['total'] ?? 0);

            // Egresos de caja por concepto (origen='caja' hardcoded; aplica sucursal + concepto)
            $sql = "SELECT concepto_nombre as concepto, SUM(monto) as total
                    FROM movimientos WHERE $where AND tipo = 'egreso' AND origen = 'caja' AND concepto_nombre IS NOT NULL" . $sucursalWhere . $conceptoWhere . "
                    GROUP BY concepto_nombre ORDER BY total DESC";
            $stmt = $conn->prepare($sql);
            $stmt->execute(array_merge($params, $sucursalParam, $conceptoParam));
            $egresosCajaPorConcepto = $stmt->fetchAll();

            // ===== RESERVAS (no afectan saldo de caja, se reportan aparte) =====
            // Total APORTADO a reservas en el período (estos sí salieron de caja)
            $sql = "SELECT SUM(monto) as total FROM movimientos WHERE $where AND tipo = 'egreso' AND reserva_accion = 'aporte'" . $sucursalWhere . $conceptoWhere;
            $stmt = $conn->prepare($sql);
            $stmt->execute(array_merge($params, $sucursalParam, $conceptoParam));
            $totalAportadoReservas = floatval($stmt->fetch()['total'] ?? 0);

            // Total GASTADO desde reservas en el período (NO sale de caja, sale de reservas)
            $sql = "SELECT SUM(monto) as total FROM movimientos WHERE $where AND tipo = 'egreso' AND reserva_accion = 'gasto'" . $sucursalWhere . $conceptoWhere;
            $stmt = $conn->prepare($sql);
            $stmt->execute(array_merge($params, $sucursalParam, $conceptoParam));
            $totalGastadoDesdeReservas = floatval($stmt->fetch()['total'] ?? 0);

            // ---- PERÍODO ANTERIOR (solo si hay rango definido) ----
            $prevTotalIngresos = null;
            $prevTotalEgresos = null;
            $prevTotalEgresosCaja = null;
            $prevEgresosPorConcepto = [];
            $prevIngresosPorSucursal = [];
            $prevEgresosPorColaborador = [];
            $prevEgresosPorProveedor = [];

            if ($fechaDesde && $fechaHasta) {
                // Ingresos
                $sql = "SELECT SUM(monto) as total FROM movimientos WHERE $prevWhere AND tipo = 'ingreso'" . $ingFilter;
                $stmt = $conn->prepare($sql);
                $stmt->execute(array_merge($prevParams, $ingParams));
                $prevTotalIngresos = floatval($stmt->fetch()['total'] ?? 0);

                // Egresos
                $sql = "SELECT SUM(monto) as total FROM movimientos WHERE $prevWhere AND tipo = 'egreso'" . $egrFilter;
                $stmt = $conn->prepare($sql);
                $stmt->execute(array_merge($prevParams, $egrParams));
                $prevTotalEgresos = floatval($stmt->fetch()['total'] ?? 0);

                $sql = "SELECT concepto_nombre as concepto, SUM(monto) as total
                        FROM movimientos WHERE $prevWhere AND tipo = 'egreso' AND concepto_nombre IS NOT NULL" . $egrFilter . "
                        GROUP BY concepto_nombre";
                $stmt = $conn->prepare($sql);
                $stmt->execute(array_merge($prevParams, $egrParams));
                $prevEgresosPorConcepto = $stmt->fetchAll();

                $sql = "SELECT sucursal_nombre as sucursal, SUM(monto) as total
                        FROM movimientos WHERE $prevWhere AND tipo = 'ingreso' AND sucursal_nombre IS NOT NULL" . $ingFilter . "
                        GROUP BY sucursal_nombre";
                $stmt = $conn->prepare($sql);
                $stmt->execute(array_merge($prevParams, $ingParams));
                $prevIngresosPorSucursal = $stmt->fetchAll();

                $sql = "SELECT colaborador_nombre as colaborador, SUM(monto) as total
                        FROM movimientos WHERE $prevWhere AND tipo = 'egreso' AND colaborador_nombre IS NOT NULL" . $egrFilter . "
                        GROUP BY colaborador_nombre";
                $stmt = $conn->prepare($sql);
                $stmt->execute(array_merge($prevParams, $egrParams));
                $prevEgresosPorColaborador = $stmt->fetchAll();

                $sql = "SELECT proveedor_nombre as proveedor, SUM(monto) as total
                        FROM movimientos WHERE $prevWhere AND tipo = 'egreso' AND proveedor_nombre IS NOT NULL" . $egrFilter . "
                        GROUP BY proveedor_nombre";
                $stmt = $conn->prepare($sql);
                $stmt->execute(array_merge($prevParams, $egrParams));
                $prevEgresosPorProveedor = $stmt->fetchAll();

                $sql = "SELECT SUM(monto) as total FROM movimientos WHERE $prevWhere AND tipo = 'egreso' AND origen = 'caja'" . $sucursalWhere . $conceptoWhere;
                $stmt = $conn->prepare($sql);
                $stmt->execute(array_merge($prevParams, $sucursalParam, $conceptoParam));
                $prevTotalEgresosCaja = floatval($stmt->fetch()['total'] ?? 0);
            }

            jsonResponse([
                'totalIngresos' => $totalIngresos,
                'totalEgresos' => $totalEgresos,
                'balance' => round($totalIngresos - $totalEgresos, 2),
                'totalEgresosCaja' => $totalEgresosCaja,
                'prevTotalIngresos' => $prevTotalIngresos,
                'prevTotalEgresos' => $prevTotalEgresos,
                'prevTotalEgresosCaja' => $prevTotalEgresosCaja,
                'prevBalance' => ($prevTotalIngresos !== null) ? round($prevTotalIngresos - $prevTotalEgresos, 2) : null,
                'ingresosPorMes' => $ingresosPorMes,
                'egresosPorMes' => $egresosPorMes,
                'egresosPorConcepto' => $egresosPorConcepto,
                'egresosCajaPorConcepto' => $egresosCajaPorConcepto,
                'prevEgresosPorConcepto' => $prevEgresosPorConcepto,
                'ingresosPorSucursal' => $ingresosPorSucursal,
                'prevIngresosPorSucursal' => $prevIngresosPorSucursal,
                'egresosPorColaborador' => $egresosPorColaborador,
                'prevEgresosPorColaborador' => $prevEgresosPorColaborador,
                'egresosPorProveedor' => $egresosPorProveedor,
                'prevEgresosPorProveedor' => $prevEgresosPorProveedor,
                'totalAportadoReservas' => $totalAportadoReservas,
                'totalGastadoDesdeReservas' => $totalGastadoDesdeReservas
            ]);
            break;

        // ==================== EXPORTAR ====================
        case 'exportar':
            $stmt = $conn->query("SELECT * FROM movimientos ORDER BY fecha, id");
            $movimientos = $stmt->fetchAll();

            $stmt = $conn->query("SELECT * FROM sucursales WHERE activo = 1");
            $sucursales = $stmt->fetchAll();

            $stmt = $conn->query("SELECT * FROM conceptos WHERE activo = 1");
            $conceptos = $stmt->fetchAll();

            $stmt = $conn->query("SELECT * FROM colaboradores WHERE activo = 1");
            $colaboradores = $stmt->fetchAll();

            $stmt = $conn->query("SELECT * FROM proveedores WHERE activo = 1");
            $proveedores = $stmt->fetchAll();

            jsonResponse([
                'movimientos' => $movimientos,
                'sucursales' => $sucursales,
                'conceptos' => $conceptos,
                'colaboradores' => $colaboradores,
                'proveedores' => $proveedores,
                'exportDate' => date('Y-m-d H:i:s')
            ]);
            break;

        // ==================== RECALCULAR SALDOS ====================
        case 'recalcular':
            // Forzar recálculo de todos los saldos (usar con precaución)
            recalcularSaldos($conn);

            // Obtener el nuevo saldo actual
            $stmt = $conn->query("SELECT saldo FROM movimientos ORDER BY fecha DESC, id DESC LIMIT 1");
            $row = $stmt->fetch();
            $saldo = $row ? floatval($row['saldo']) : 0;

            // Contar movimientos recalculados
            $stmt = $conn->query("SELECT COUNT(*) as total FROM movimientos");
            $total = $stmt->fetch()['total'];

            jsonResponse([
                'success' => true,
                'mensaje' => "Se recalcularon los saldos de $total movimientos",
                'saldoActual' => $saldo
            ]);
            break;

        default:
            jsonResponse(['error' => 'Acción no válida'], 400);
    }

} catch (PDOException $e) {
    jsonResponse(['error' => $e->getMessage()], 500);
}

// ==================== FUNCIONES AUXILIARES ====================
/**
 * Agrega al WHERE el filtro por FECHA DE ASENTAMIENTO (`created_at`): cuándo se cargó
 * realmente el movimiento, sin importar la fecha a la que corresponde.
 *
 * `created_at` es un TIMESTAMP (fecha + hora), así que NO alcanza con comparar contra
 * 'YYYY-MM-DD': `created_at <= '2026-09-22'` equivale a `<= 2026-09-22 00:00:00` y dejaría
 * afuera todo ese día. Se usa un rango semiabierto [desde 00:00 , hasta+1día 00:00).
 *
 * Tampoco se usa DATE(created_at) porque aplicar una función sobre la columna impide
 * usar el índice; comparar la columna "pelada" contra constantes sí lo aprovecha.
 *
 * Se aplica desde los 3 endpoints que listan o suman movimientos (`movimientos`,
 * `reporte-ingresos`, `reporte-egresos`) para que la tabla, los totales y los gráficos
 * respondan siempre al mismo filtro.
 */
function aplicarFiltroAsentamiento(&$where, &$params)
{
    $esFecha = function ($v) { return !empty($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v); };

    if ($esFecha($_GET['asentDesde'] ?? null)) {
        $where .= " AND created_at >= :asentDesde";
        $params[':asentDesde'] = $_GET['asentDesde'] . ' 00:00:00';
    }
    if ($esFecha($_GET['asentHasta'] ?? null)) {
        // Límite superior = día siguiente a las 00:00, para incluir todo el día "hasta"
        $where .= " AND created_at < :asentHasta";
        $params[':asentHasta'] = date('Y-m-d 00:00:00', strtotime($_GET['asentHasta'] . ' +1 day'));
    }
}

/**
 * Traduce el parámetro `orden` a un ORDER BY seguro (lista blanca: nunca se interpola
 * entrada del usuario en el SQL).
 *
 * Para "asentamiento" se ordena por `id` y no por `created_at`: `id` es AUTO_INCREMENT,
 * así que crece exactamente en el orden en que se asientan los movimientos, y al ser la
 * PRIMARY KEY se recorre sin filesort. `created_at` no tiene índice propio, así que
 * ordenar por esa columna obligaría a ordenar en memoria todo el resultado filtrado.
 */
function ordenMovimientos($orden)
{
    $opciones = [
        'fecha_desc' => 'fecha DESC, id DESC',
        'fecha_asc'  => 'fecha ASC, id ASC',
        'asent_desc' => 'id DESC',
        'asent_asc'  => 'id ASC',
    ];
    return $opciones[$orden] ?? $opciones['fecha_desc'];
}

/**
 * Recalcula la columna `saldo` (saldo acumulado de caja) de los movimientos.
 *
 * NOTA: LOCK TABLES hace commit implícito, por lo que NO se puede usar con transacciones PDO.
 * Usamos SELECT ... FOR UPDATE dentro de una transacción para bloquear las filas.
 *
 * REGLA MATEMÁTICA CRÍTICA (caja principal):
 * - Ingreso: SUMA al saldo de caja
 * - Egreso normal: RESTA del saldo de caja
 * - Aporte a reserva (egreso con reserva_accion='aporte'): RESTA del saldo de caja (sale plata real de caja)
 * - Gasto desde reserva (egreso con reserva_accion='gasto'): NO TOCA el saldo de caja
 *   (esa plata ya salió de caja cuando se hizo el aporte; ahora solo sale de la reserva)
 *
 * OPTIMIZACIÓN (escalabilidad):
 * El saldo es un acumulado cronológico, así que un movimiento con fecha F sólo puede
 * afectar el saldo de las filas con fecha >= F. Pasando $desdeFecha se recalcula
 * únicamente ese tramo, tomando como base el saldo de la última fila anterior a F.
 * En el caso normal (se carga con fecha de hoy) esto toca unas pocas filas en vez de
 * la tabla entera, y el costo deja de crecer con el histórico.
 *
 * Además se escriben sólo las filas cuyo saldo REALMENTE cambió, y en UPDATEs por lote
 * (CASE/WHEN) en vez de un round-trip a MySQL por fila.
 *
 * @param PDO         $conn
 * @param string|null $desdeFecha Fecha 'YYYY-MM-DD' desde la cual recalcular. null = tabla completa.
 */
function recalcularSaldos($conn, $desdeFecha = null)
{
    // Validar el formato de la fecha: si no es confiable, degradar a recálculo completo.
    if ($desdeFecha !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $desdeFecha)) {
        $desdeFecha = null;
    }

    try {
        // Iniciar transacción
        $conn->beginTransaction();

        $saldo = 0;

        if ($desdeFecha !== null) {
            // Saldo base = saldo de la última fila ANTERIOR al tramo a recalcular.
            // Esas filas no se tocan porque su acumulado no puede haber cambiado.
            $stmtBase = $conn->prepare("
                SELECT saldo FROM movimientos
                WHERE fecha < :desde
                ORDER BY fecha DESC, id DESC
                LIMIT 1
            ");
            $stmtBase->execute([':desde' => $desdeFecha]);
            $rowBase = $stmtBase->fetch();
            $saldo = $rowBase ? floatval($rowBase['saldo']) : 0;

            $stmt = $conn->prepare("
                SELECT id, tipo, monto, reserva_accion, saldo
                FROM movimientos
                WHERE fecha >= :desde
                ORDER BY fecha, id
                FOR UPDATE
            ");
            $stmt->execute([':desde' => $desdeFecha]);
        } else {
            $stmt = $conn->query("
                SELECT id, tipo, monto, reserva_accion, saldo
                FROM movimientos
                ORDER BY fecha, id
                FOR UPDATE
            ");
        }

        $movimientos = $stmt->fetchAll();

        // Acumular y quedarse SÓLO con las filas que cambian
        $cambios = []; // id => nuevoSaldo
        foreach ($movimientos as $mov) {
            $monto = floatval($mov['monto']);
            $reservaAccion = $mov['reserva_accion'] ?? null;

            if ($mov['tipo'] === 'ingreso') {
                $saldo += $monto;
            } else {
                // Egreso: solo afecta caja si NO es un gasto desde reserva
                if ($reservaAccion !== 'gasto') {
                    $saldo -= $monto;
                }
                // Si reserva_accion === 'gasto', no se toca el saldo de caja
            }

            $nuevoSaldo = round($saldo, 2);

            // Comparar en centavos para evitar falsos positivos por float
            if (round(floatval($mov['saldo']) * 100) !== round($nuevoSaldo * 100)) {
                $cambios[intval($mov['id'])] = $nuevoSaldo;
            }
        }

        // Escribir en lotes: 1 UPDATE cada 500 filas en vez de 1 UPDATE por fila
        if (!empty($cambios)) {
            foreach (array_chunk($cambios, 500, true) as $lote) {
                $casos = '';
                $ids = [];
                $params = [];
                $i = 0;
                foreach ($lote as $id => $nuevoSaldo) {
                    // OJO: PDO con EMULATE_PREPARES=false NO permite repetir un placeholder
                    // nombrado en la misma consulta, por eso el CASE y el IN usan nombres
                    // distintos (:id{n} / :wid{n}) aunque lleven el mismo valor.
                    $casos .= " WHEN :id{$i} THEN :saldo{$i}";
                    $params[":id{$i}"] = $id;
                    $params[":saldo{$i}"] = $nuevoSaldo;
                    $params[":wid{$i}"] = $id;
                    $ids[] = ":wid{$i}";
                    $i++;
                }
                // ELSE saldo: si por cualquier motivo el WHERE alcanzara una fila fuera del
                // CASE, conserva su valor en vez de escribir NULL.
                $sql = "UPDATE movimientos SET saldo = CASE id{$casos} ELSE saldo END WHERE id IN (" . implode(',', $ids) . ")";
                $conn->prepare($sql)->execute($params);
            }
        }

        // Confirmar transacción
        $conn->commit();

    } catch (Exception $e) {
        // Revertir en caso de error
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }
}

/**
 * Calcula el saldo actual de una reserva específica.
 * Saldo = SUMA(aportes) - SUMA(gastos)
 * Opcionalmente excluye un movimiento específico (útil para validaciones de edición/eliminación)
 */
function calcularSaldoReserva($conn, $reservaId, $excluirMovId = null)
{
    if (empty($reservaId) || !is_numeric($reservaId)) {
        return 0;
    }

    $sql = "SELECT
                COALESCE(SUM(CASE WHEN reserva_accion = 'aporte' THEN monto ELSE 0 END), 0) -
                COALESCE(SUM(CASE WHEN reserva_accion = 'gasto' THEN monto ELSE 0 END), 0) AS saldo
            FROM movimientos
            WHERE reserva_id = :reservaId";
    $params = [':reservaId' => intval($reservaId)];

    if ($excluirMovId !== null && is_numeric($excluirMovId)) {
        $sql .= " AND id != :excluirId";
        $params[':excluirId'] = intval($excluirMovId);
    }

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return floatval($row['saldo'] ?? 0);
}

/**
 * Valida que una reserva exista y esté activa
 */
function validarReserva($conn, $reservaId)
{
    if (empty($reservaId)) {
        return false;
    }
    $stmt = $conn->prepare("SELECT id, nombre FROM reservas WHERE id = :id AND activo = 1");
    $stmt->execute([':id' => $reservaId]);
    return $stmt->fetch();
}

// Función para validar que una sucursal existe y está activa
function validarSucursal($conn, $sucursalId)
{
    if (empty($sucursalId)) {
        return false;
    }
    $stmt = $conn->prepare("SELECT id, nombre FROM sucursales WHERE id = :id AND activo = 1");
    $stmt->execute([':id' => $sucursalId]);
    return $stmt->fetch();
}

// Función para validar que un concepto existe y está activo
function validarConcepto($conn, $conceptoId)
{
    if (empty($conceptoId)) {
        return false;
    }
    $stmt = $conn->prepare("SELECT id, nombre, requiere_colaborador, requiere_sucursal, es_reserva FROM conceptos WHERE id = :id AND activo = 1");
    $stmt->execute([':id' => $conceptoId]);
    return $stmt->fetch();
}

// Función para validar que un colaborador existe y está activo
function validarColaborador($conn, $colaboradorId)
{
    if (empty($colaboradorId)) {
        return false;
    }
    $stmt = $conn->prepare("SELECT id, nombre FROM colaboradores WHERE id = :id AND activo = 1");
    $stmt->execute([':id' => $colaboradorId]);
    return $stmt->fetch();
}

// Función para validar que un proveedor existe y está activo
function validarProveedor($conn, $proveedorId)
{
    if (empty($proveedorId)) {
        return false;
    }
    $stmt = $conn->prepare("SELECT id, nombre FROM proveedores WHERE id = :id AND activo = 1");
    $stmt->execute([':id' => $proveedorId]);
    return $stmt->fetch();
}

// Normaliza un nombre de concepto para comparación (sin acentos, minúsculas, trim)
function normalizarNombre($nombre)
{
    $n = trim(mb_strtolower($nombre, 'UTF-8'));
    $from = ['á','é','í','ó','ú','ü','Á','É','Í','Ó','Ú','Ü','ñ','Ñ'];
    $to   = ['a','e','i','o','u','u','a','e','i','o','u','u','n','n'];
    return str_replace($from, $to, $n);
}

// ¿Este concepto requiere proveedor? Hardcoded: "Depósito" o "Depósitos" (acepta singular y plural)
function conceptoRequiereProveedor($nombreConcepto)
{
    $n = normalizarNombre($nombreConcepto);
    return $n === 'deposito' || $n === 'depositos';
}
?>