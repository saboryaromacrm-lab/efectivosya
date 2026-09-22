<?php
// PROTECCIÓN: Solo ejecutar desde CLI o con clave de acceso
$esCliAccess = php_sapi_name() === 'cli';
$tieneClaveAcceso = isset($_GET['setup_key']) && $_GET['setup_key'] === 'sya2025_setup';

if (!$esCliAccess && !$tieneClaveAcceso) {
    http_response_code(404);
    echo '<!DOCTYPE html><html><head><title>404 Not Found</title></head><body><h1>Not Found</h1></body></html>';
    exit;
}

require_once 'config.php';

try {
    $conn = getConnection();

    // Crear tabla de sucursales
    $conn->exec("
        CREATE TABLE IF NOT EXISTS sucursales (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nombre VARCHAR(100) NOT NULL,
            activo TINYINT(1) DEFAULT 1,
            rinde_caja TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Crear tabla de conceptos
    $conn->exec("
        CREATE TABLE IF NOT EXISTS conceptos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nombre VARCHAR(100) NOT NULL,
            requiere_colaborador TINYINT(1) DEFAULT 0,
            requiere_sucursal TINYINT(1) DEFAULT 0,
            activo TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Crear tabla de colaboradores
    $conn->exec("
        CREATE TABLE IF NOT EXISTS colaboradores (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nombre VARCHAR(100) NOT NULL,
            activo TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Crear tabla de proveedores
    $conn->exec("
        CREATE TABLE IF NOT EXISTS proveedores (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nombre VARCHAR(100) NOT NULL,
            activo TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Crear tabla de reservas (cajas separadas tipo MercadoPago)
    // concepto_id: vínculo 1:1 al concepto auto-creado para esta reserva (NULL si es reserva legacy sin concepto)
    $conn->exec("
        CREATE TABLE IF NOT EXISTS reservas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nombre VARCHAR(100) NOT NULL,
            descripcion VARCHAR(255) NULL,
            concepto_id INT NULL,
            activo TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_concepto (concepto_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Crear tabla de anotaciones / recordatorios (banners en Movimientos)
    $conn->exec("
        CREATE TABLE IF NOT EXISTS anotaciones (
            id INT AUTO_INCREMENT PRIMARY KEY,
            titulo VARCHAR(120) NOT NULL,
            mensaje VARCHAR(500) NULL,
            activo TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_activo (activo, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Crear tabla de recordatorios mensuales por reserva
    $conn->exec("
        CREATE TABLE IF NOT EXISTS recordatorios_reserva (
            id INT AUTO_INCREMENT PRIMARY KEY,
            reserva_id INT NOT NULL,
            dia_mes TINYINT NOT NULL,
            monto_sugerido DECIMAL(15,2) NULL,
            nota VARCHAR(255) NULL,
            activo TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_reserva (reserva_id),
            INDEX idx_activo (activo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Crear tabla de recordatorios descartados por mes (cuando el usuario hace 'Eliminar')
    $conn->exec("
        CREATE TABLE IF NOT EXISTS recordatorios_descartados (
            id INT AUTO_INCREMENT PRIMARY KEY,
            recordatorio_id INT NOT NULL,
            anio SMALLINT NOT NULL,
            mes TINYINT NOT NULL,
            descartado_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_rec_periodo (recordatorio_id, anio, mes),
            INDEX idx_periodo (anio, mes)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Crear tabla de días cerrados por sucursal
    $conn->exec("
        CREATE TABLE IF NOT EXISTS dias_cerrados (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sucursal_id INT NOT NULL,
            fecha DATE NOT NULL,
            motivo VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_suc_fecha (sucursal_id, fecha),
            INDEX idx_fecha (fecha),
            INDEX idx_sucursal (sucursal_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Crear tabla de movimientos
    $conn->exec("
        CREATE TABLE IF NOT EXISTS movimientos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            fecha DATE NOT NULL,
            tipo ENUM('ingreso', 'egreso') NOT NULL,
            sucursal_id INT NULL,
            sucursal_nombre VARCHAR(100) NULL,
            concepto_id INT NULL,
            concepto_nombre VARCHAR(100) NULL,
            colaborador_id INT NULL,
            colaborador_nombre VARCHAR(100) NULL,
            proveedor_id INT NULL,
            proveedor_nombre VARCHAR(100) NULL,
            reserva_id INT NULL,
            reserva_nombre VARCHAR(100) NULL,
            reserva_accion ENUM('aporte','gasto') NULL,
            movimiento_padre_id INT NULL,
            origen ENUM('normal','caja') DEFAULT 'normal',
            monto DECIMAL(15,2) NOT NULL,
            observacion VARCHAR(255) NULL,
            saldo DECIMAL(15,2) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_fecha (fecha),
            INDEX idx_tipo (tipo),
            INDEX idx_sucursal (sucursal_id),
            INDEX idx_concepto (concepto_id),
            INDEX idx_colaborador (colaborador_id),
            INDEX idx_proveedor (proveedor_id),
            INDEX idx_reserva (reserva_id),
            INDEX idx_reserva_accion (reserva_accion),
            INDEX idx_padre (movimiento_padre_id),
            INDEX idx_origen (origen),
            INDEX idx_fecha_id (fecha, id),
            INDEX idx_tipo_fecha (tipo, fecha),
            INDEX idx_duplicado (fecha, tipo, monto, sucursal_id, concepto_id, created_at),
            -- Filtro por fecha de asentamiento (cuándo se cargó el movimiento)
            INDEX idx_created (created_at),
            INDEX idx_tipo_created (tipo, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Agregar índice para detección de duplicados si no existe (para bases de datos existentes)
    try {
        $conn->exec("CREATE INDEX idx_duplicado ON movimientos (fecha, tipo, monto, sucursal_id, concepto_id, created_at)");
    } catch (PDOException $e) {
        // El índice ya existe, ignorar error
    }

    // Índices para filtrar y ordenar por FECHA DE ASENTAMIENTO (created_at).
    // El segundo cubre el caso más común: listar ingresos o egresos de un rango de carga.
    try {
        $conn->exec("CREATE INDEX idx_created ON movimientos (created_at)");
    } catch (PDOException $e) {
        // El índice ya existe, ignorar error
    }

    try {
        $conn->exec("CREATE INDEX idx_tipo_created ON movimientos (tipo, created_at)");
    } catch (PDOException $e) {
        // El índice ya existe, ignorar error
    }

    // Migraciones para bases de datos existentes
    try {
        $conn->exec("ALTER TABLE conceptos ADD COLUMN requiere_colaborador TINYINT(1) DEFAULT 0");
    } catch (PDOException $e) {
        // La columna ya existe
    }

    try {
        $conn->exec("ALTER TABLE sucursales ADD COLUMN rinde_caja TINYINT(1) DEFAULT 1");
    } catch (PDOException $e) {
        // La columna ya existe
    }

    try {
        $conn->exec("ALTER TABLE conceptos ADD COLUMN requiere_sucursal TINYINT(1) DEFAULT 0");
    } catch (PDOException $e) {
        // La columna ya existe
    }

    try {
        $conn->exec("ALTER TABLE movimientos ADD COLUMN movimiento_padre_id INT NULL AFTER colaborador_nombre");
    } catch (PDOException $e) {
        // La columna ya existe
    }

    try {
        $conn->exec("ALTER TABLE movimientos ADD COLUMN origen ENUM('normal','caja') DEFAULT 'normal' AFTER movimiento_padre_id");
    } catch (PDOException $e) {
        // La columna ya existe
    }

    try {
        $conn->exec("CREATE INDEX idx_padre ON movimientos (movimiento_padre_id)");
    } catch (PDOException $e) {
        // El índice ya existe
    }

    try {
        $conn->exec("CREATE INDEX idx_origen ON movimientos (origen)");
    } catch (PDOException $e) {
        // El índice ya existe
    }

    try {
        $conn->exec("ALTER TABLE movimientos ADD COLUMN colaborador_id INT NULL AFTER concepto_nombre");
        $conn->exec("ALTER TABLE movimientos ADD COLUMN colaborador_nombre VARCHAR(100) NULL AFTER colaborador_id");
        $conn->exec("CREATE INDEX idx_colaborador ON movimientos (colaborador_id)");
    } catch (PDOException $e) {
        // Las columnas ya existen
    }

    try {
        $conn->exec("ALTER TABLE movimientos ADD COLUMN proveedor_id INT NULL AFTER colaborador_nombre");
    } catch (PDOException $e) {
        // La columna ya existe
    }

    try {
        $conn->exec("ALTER TABLE movimientos ADD COLUMN proveedor_nombre VARCHAR(100) NULL AFTER proveedor_id");
    } catch (PDOException $e) {
        // La columna ya existe
    }

    try {
        $conn->exec("CREATE INDEX idx_proveedor ON movimientos (proveedor_id)");
    } catch (PDOException $e) {
        // El índice ya existe
    }

    // Migraciones de RESERVAS
    try {
        $conn->exec("ALTER TABLE conceptos ADD COLUMN es_reserva TINYINT(1) DEFAULT 0");
    } catch (PDOException $e) {
        // La columna ya existe
    }

    try {
        $conn->exec("ALTER TABLE movimientos ADD COLUMN reserva_id INT NULL AFTER proveedor_nombre");
    } catch (PDOException $e) {
        // La columna ya existe
    }

    try {
        $conn->exec("ALTER TABLE movimientos ADD COLUMN reserva_nombre VARCHAR(100) NULL AFTER reserva_id");
    } catch (PDOException $e) {
        // La columna ya existe
    }

    try {
        $conn->exec("ALTER TABLE movimientos ADD COLUMN reserva_accion ENUM('aporte','gasto') NULL AFTER reserva_nombre");
    } catch (PDOException $e) {
        // La columna ya existe
    }

    try {
        $conn->exec("CREATE INDEX idx_reserva ON movimientos (reserva_id)");
    } catch (PDOException $e) {
        // El índice ya existe
    }

    try {
        $conn->exec("CREATE INDEX idx_reserva_accion ON movimientos (reserva_accion)");
    } catch (PDOException $e) {
        // El índice ya existe
    }

    // Migración: vínculo concepto_id en reservas (para auto-creación 1:1)
    try {
        $conn->exec("ALTER TABLE reservas ADD COLUMN concepto_id INT NULL AFTER descripcion");
    } catch (PDOException $e) {
        // La columna ya existe
    }

    try {
        $conn->exec("CREATE INDEX idx_concepto ON reservas (concepto_id)");
    } catch (PDOException $e) {
        // El índice ya existe
    }

    // ===== MIGRACIÓN AUTOMÁTICA: vincular reservas legacy con concepto auto-creado =====
    // Para cada reserva activa SIN concepto_id, buscar (o crear) el concepto "Aporte Reserva: X"
    // y vincularlos. Esto arregla reservas creadas antes del refactor de auto-vinculación.
    $migracionReservasResult = ['vinculadas_existentes' => 0, 'conceptos_creados' => 0];
    try {
        $stmt = $conn->query("SELECT id, nombre FROM reservas WHERE concepto_id IS NULL AND activo = 1");
        $reservasLegacy = $stmt->fetchAll();

        foreach ($reservasLegacy as $r) {
            $nombreConceptoEsperado = "Aporte Reserva: " . $r['nombre'];

            // ¿Ya existe un concepto activo con ese nombre? (puede ser que el usuario lo haya creado manual con tilde "Es Reserva")
            $stmt2 = $conn->prepare("SELECT id FROM conceptos WHERE nombre = :n AND activo = 1");
            $stmt2->execute([':n' => $nombreConceptoEsperado]);
            $existente = $stmt2->fetch();

            if ($existente) {
                // Reusar el concepto existente: marcarlo como reserva si no lo está
                $stmt2 = $conn->prepare("UPDATE conceptos SET es_reserva = 1 WHERE id = :id");
                $stmt2->execute([':id' => $existente['id']]);
                $conceptoIdMig = (int)$existente['id'];
                $migracionReservasResult['vinculadas_existentes']++;
            } else {
                // Crear el concepto auto-vinculado
                $stmt2 = $conn->prepare("INSERT INTO conceptos (nombre, es_reserva) VALUES (:n, 1)");
                $stmt2->execute([':n' => $nombreConceptoEsperado]);
                $conceptoIdMig = (int)$conn->lastInsertId();
                $migracionReservasResult['conceptos_creados']++;
            }

            // Vincular la reserva
            $stmt2 = $conn->prepare("UPDATE reservas SET concepto_id = :cid WHERE id = :rid");
            $stmt2->execute([':cid' => $conceptoIdMig, ':rid' => $r['id']]);
        }
    } catch (PDOException $e) {
        // Si algo falla acá no es bloqueante
        $migracionReservasResult['error'] = $e->getMessage();
    }

    // Insertar sucursales por defecto si no existen
    $stmt = $conn->query("SELECT COUNT(*) as total FROM sucursales");
    $count = $stmt->fetch()['total'];

    if ($count == 0) {
        $conn->exec("
            INSERT INTO sucursales (nombre) VALUES 
            ('Sucursal Centro'),
            ('Sucursal Norte'),
            ('Sucursal Sur'),
            ('Sucursal Este'),
            ('Sucursal Oeste')
        ");
    }

    // Insertar conceptos por defecto si no existen
    $stmt = $conn->query("SELECT COUNT(*) as total FROM conceptos");
    $count = $stmt->fetch()['total'];

    if ($count == 0) {
        $conn->exec("
            INSERT INTO conceptos (nombre) VALUES 
            ('Pago a Proveedores'),
            ('Servicios'),
            ('Sueldos'),
            ('Retiro Personal'),
            ('Gastos Varios')
        ");
    }

    echo json_encode([
        'success' => true,
        'message' => 'Base de datos inicializada correctamente',
        'tables' => ['sucursales', 'conceptos', 'colaboradores', 'proveedores', 'reservas', 'recordatorios_reserva', 'recordatorios_descartados', 'anotaciones', 'movimientos', 'dias_cerrados'],
        'migracionReservasLegacy' => $migracionReservasResult
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>