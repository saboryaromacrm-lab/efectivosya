# 💰 Caja SYA — Sistema de Efectivo

Aplicación web (PWA) para controlar el efectivo de un negocio con varias sucursales: rendiciones de caja, retiros, egresos, reservas de dinero separadas, reportes y exportaciones.

- **Producción:** `https://efectivo.saboryaroma.com/?acceso=<clave>`
- **Repositorio:** `saboryaromacrm-lab/efectivosya` (rama `main`, despliegue automático)

---

## Índice

1. [Arquitectura](#arquitectura)
2. [Acceso](#acceso)
3. [Secciones de la app](#secciones-de-la-app)
4. [Reglas de negocio](#reglas-de-negocio)
5. [Configuración](#configuración)
6. [Base de datos](#base-de-datos)
7. [API](#api)
8. [Instalación y despliegue](#instalación-y-despliegue)
9. [Mantenimiento](#mantenimiento)
10. [Seguridad y pendientes](#seguridad-y-pendientes)

---

## Arquitectura

| Componente | Tecnología | Archivo |
|---|---|---|
| Frontend (SPA) | HTML + CSS + JavaScript sin frameworks | `index.html` |
| API | PHP 7+ con PDO | `api.php` |
| Conexión y headers | PHP | `config.php` |
| Creación de tablas y migraciones | PHP | `setup.php` |
| PWA | Service Worker + manifest | `sw.js`, `manifest.json`, `icons/` |
| Gráficos | Chart.js (CDN) | — |
| Base de datos | MySQL / MariaDB (InnoDB, utf8mb4) | — |

Toda la interfaz vive en `index.html` y se comunica con `api.php` mediante `fetch`, usando `?action=<nombre>`.

**Zona horaria:** la conexión fija `time_zone = '-03:00'` (Argentina, sin horario de verano). Como MySQL guarda los `TIMESTAMP` en UTC, así se leen en hora argentina tanto los registros nuevos como los viejos.

---

## Acceso

La app pide un parámetro en la URL: `?acceso=<clave>`. Si no está o no coincide, muestra una página 404 falsa y no carga nada.

La clave se define en `index.html`, constante `CLAVE_ACCESO`.

> ⚠️ Es una puerta **sólo del frontend**: `api.php` no valida ningún acceso. Ver [Seguridad y pendientes](#seguridad-y-pendientes).

---

## Secciones de la app

### 📝 Movimientos (pantalla principal)

**Registrar Movimiento** — se elige **INGRESO** o **EGRESO**.

Campos comunes:
- **Fecha del Movimiento:** el día al que corresponde. La pone el usuario y por defecto es hoy (en hora local). Admite hasta 7 días a futuro.
- **Monto** y **Observación** (hasta 255 caracteres).

**Ingreso** (rendición/cierre de caja de una sucursal):
- **Sucursal** (obligatoria) y **Monto de Cierre de Caja**.
- **Retiros de Caja** (opcional): con **+ Agregar retiro** se cargan todos los que haga falta. Cada uno lleva monto, concepto y observación, y se guarda como un egreso vinculado al ingreso.
- **Ingreso Físico Real** = cierre − suma de retiros. Se calcula en vivo y avisa si los retiros superan el cierre.
- Si ya existe un ingreso para esa sucursal en esa fecha, avisa y bloquea: no se permite duplicar una rendición.

**Egreso:**
- **Concepto** (obligatorio).
- **Colaborador:** obligatorio si el concepto lo exige.
- **Sucursal:** obligatoria si el concepto lo exige.
- **Proveedor:** obligatorio si el concepto es "Depósito/Depósitos". Desde ahí mismo se puede **crear** un proveedor nuevo (opción "➕ Agregar proveedor nuevo…") o **renombrar** el elegido (botón ✏️).
- **Reservas:** si el concepto es de reserva, es un *aporte*. Si no, se puede activar **"Pagar desde una reserva"** para que salga de una reserva y no de la caja.

**Últimos Movimientos** — lista en **orden de carga** (lo último cargado arriba):
- Muestra de a 10. El botón **Ver 10 más** va sumando bloques hasta el final.
- **Fecha Mov.** (la manual) y **Asentamiento** (fecha y hora automática de carga, que no se edita).
- **⏱ retroactivo:** marca los movimientos cuya fecha no coincide con el día en que se cargaron.
- **Saldo:** saldo de caja después de cargar cada movimiento, en el orden de la lista. La fila de arriba coincide con el Saldo Actual del encabezado y cada fila sale de la de abajo sumando o restando ese movimiento, también entre bloques de "Ver más".
- ✏️ **Editar** y 🗑️ **Eliminar** en cada fila.

**⚠️ Rendiciones Pendientes** — días de los últimos 30 (sin domingos) en que una sucursal que *rinde caja* no cargó ingreso y tampoco figura como día cerrado.

**⏰ Recordatorios de reserva** — avisos de aporte que vencen este mes. Se pueden descartar hasta el mes siguiente.

**📝 Anotaciones / Recordatorios** — notas libres que quedan visibles en la pantalla principal.

**Encabezado fijo:** Saldo Actual y fecha del Último Ingreso.

### 📈 Ingresos
- **Filtros:** mes, rango de fecha del movimiento, sucursal, rango de **fecha de asentamiento** y **orden** (fecha mov. ↑↓ o asentamiento ↑↓).
- **Total filtrado** y gráficos por mes y por sucursal. Los totales y los gráficos respetan los mismos filtros que la tabla.
- Listado paginado de a 50, con salto directo de páginas.
- **📊 Exportar CSV** con los mismos filtros y el mismo orden de la pantalla.

### 📉 Egresos
Igual que Ingresos, filtrando además por concepto, colaborador y proveedor, con gráficos por mes y por concepto. Los retiros de caja se distinguen con la etiqueta **💰 Directo de caja**.

### 📊 Reportes
- **Filtros:** mes, rango de fechas, sucursal, concepto y origen del egreso (normal, directo de caja o todos).
- **Resumen:** ingresos, egresos, balance y **comparación con el período anterior** de la misma duración.
- **Gráficos:** por mes, por concepto, por sucursal, top 10 de colaboradores y de proveedores.
- **Ranking de Gastos por Concepto** y **Depósitos por Proveedor**.
- **Movimientos del Reporte:** detalle completo de lo que suman los totales, para verificarlos a mano.
- **Reservas:** lo aportado y lo gastado se informa aparte, porque los gastos desde reserva no afectan la caja.
- Exportación a CSV del detalle.

### 🏦 Reservas
Cajas separadas del efectivo principal, por ejemplo para aguinaldos o impuestos:
- **Mis Reservas:** saldo, aportes y gastos de cada una.
- **Historial** de cada reserva, con saldo acumulado.
- **Recordatorios de Reserva:** día del mes, monto sugerido y nota para aportar.

### ⚙️ Configuración
- **Sucursales:** alta, baja y **rinde caja** (si participa del control de rendiciones pendientes).
- **Conceptos de Egreso:** alta, baja y los flags **requiere colaborador**, **requiere sucursal** y **es reserva**.
- **Colaboradores** y **Proveedores:** alta, baja y, en proveedores, renombrado ✏️.
- **Reservas:** alta con descripción. Crea automáticamente un concepto vinculado.
- **Días Cerrados por Sucursal:** una o varias sucursales, en un día o un rango, con motivo. No cuentan como rendiciones pendientes.
- **🔧 Herramientas → Recalcular Saldos:** rehace el saldo de todos los movimientos.

### Detalles de interfaz
- **Buscador dentro de los desplegables:** en los que tienen 8 opciones o más. No distingue acentos ("deposito" encuentra "Depósitos"). Se maneja con flechas, Enter y Escape.
- **Vista móvil:** encabezado compacto, navegación en dos filas de tres y tablas convertidas en tarjetas "etiqueta: valor", sin renglones vacíos.
- **PWA:** se puede instalar en el celular como app.

---

## Reglas de negocio

### Cálculo del saldo de caja
El saldo es un acumulado **cronológico por fecha del movimiento** (orden `fecha`, luego `id`):

| Movimiento | Efecto en la caja |
|---|---|
| Ingreso | **suma** |
| Egreso normal | **resta** |
| Retiro de caja (egreso con `origen = caja`) | **resta** |
| Aporte a reserva (`reserva_accion = aporte`) | **resta** (la plata sale de la caja hacia la reserva) |
| Gasto desde reserva (`reserva_accion = gasto`) | **no toca la caja** (sale de la reserva) |

Cada alta, edición o borrado recalcula **sólo desde la fecha afectada en adelante** y escribe sólo las filas que cambian, en lotes. Así el costo no crece con el histórico.

Saldo de una reserva = suma de aportes − suma de gastos.

### Dos fechas por movimiento
- **`fecha`** — a qué día corresponde. Manual y editable.
- **`created_at`** — cuándo se asentó en el sistema. Automática y **no cambia al editar**.

### Retiros de caja
- Cada retiro es un egreso con `origen = 'caja'` y `movimiento_padre_id` = id del ingreso.
- Heredan fecha y sucursal del ingreso. **Al editar el ingreso, los retiros lo siguen.**
- Los retiros no se editan sueltos. Si se elimina el ingreso, se eliminan sus retiros.
- La suma de los retiros debe ser menor al cierre de caja. Hay un máximo de 20 por ingreso.
- No se admiten conceptos que exijan colaborador: esos van como egreso normal.
- Un ingreso con retiros no puede convertirse en egreso.

### Otras validaciones
- **1 ingreso por sucursal por fecha.**
- **Anti-duplicado:** rechaza un movimiento idéntico cargado en los últimos 5 segundos.
- **Reservas:** no se puede gastar más que el saldo de la reserva. Tampoco se puede eliminar un aporte si la reserva quedaría negativa, ni eliminar una reserva con saldo.
- **Bajas lógicas:** sucursales, conceptos, colaboradores, proveedores y reservas no se borran, se desactivan. En los movimientos históricos, el nombre queda marcado como "(eliminado)" o "(eliminada)".
- **Renombrar un proveedor** actualiza también el nombre en todos sus movimientos, para que los reportes no lo partan en dos.

---

## Configuración

### `config.php`
| Constante | Uso |
|---|---|
| `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` | Conexión a MySQL |
| `TZ_OFFSET_MYSQL` | Zona horaria de la sesión MySQL (`-03:00`) |
| `TZ_PHP` | Zona horaria de PHP (`America/Argentina/Buenos_Aires`) |

`setApiHeaders()` envía JSON, CORS y **headers anti-caché**, para que ninguna respuesta de la API quede vieja en el navegador.

### `index.html`
| Elemento | Uso |
|---|---|
| `CLAVE_ACCESO` | Clave del parámetro `?acceso=` |
| `ULTIMOS_POR_PAGINA` | Filas por bloque en Últimos Movimientos (10) |
| `ITEMS_POR_PAGINA` | Filas por página en Ingresos y Egresos (50) |
| `SB_MIN_OPCIONES_BUSCADOR` | Desde cuántas opciones aparece el buscador (8) |

### `sw.js`
`CACHE_NAME` (`caja-sya-vN`): hay que subirle el número en cada cambio de `index.html`, para que los celulares descarten la copia vieja.

---

## Base de datos

| Tabla | Contenido |
|---|---|
| `movimientos` | Ingresos y egresos: `fecha`, `tipo`, `monto`, `saldo` (acumulado), sucursal, concepto, colaborador, proveedor, reserva (`reserva_id`, `reserva_accion`), `origen` (`normal`/`caja`), `movimiento_padre_id`, `observacion`, `created_at` |
| `sucursales` | `nombre`, `rinde_caja`, `activo` |
| `conceptos` | `nombre`, `requiere_colaborador`, `requiere_sucursal`, `es_reserva`, `activo` |
| `colaboradores` | `nombre`, `activo` |
| `proveedores` | `nombre`, `activo` |
| `reservas` | `nombre`, `descripcion`, `concepto_id` (concepto vinculado), `activo` |
| `recordatorios_reserva` | `reserva_id`, `dia_mes`, `monto_sugerido`, `nota`, `activo` |
| `recordatorios_descartados` | Recordatorios descartados por año y mes |
| `dias_cerrados` | `sucursal_id`, `fecha`, `motivo` (único por sucursal y fecha) |
| `anotaciones` | `titulo`, `mensaje`, `activo`, `created_at` |

Los nombres de sucursal, concepto, colaborador, proveedor y reserva se guardan también **desnormalizados** en `movimientos`, para conservar el histórico aunque el registro se dé de baja.

**Índices principales de `movimientos`:** `(fecha, id)`, `(tipo, fecha)`, `created_at`, `(tipo, created_at)`, uno por cada clave foránea y uno para el control anti-duplicado.

---

## API

Todas las llamadas son `api.php?action=<acción>` y responden JSON.

| Acción | Métodos | Descripción |
|---|---|---|
| `init` | GET | Datos iniciales: sucursales, conceptos, colaboradores, proveedores, reservas, anotaciones, días cerrados, saldo |
| `saldo` | GET | Saldo actual y fecha del último ingreso |
| `ultimos` | GET | Últimos movimientos por orden de carga, con `saldo_carga`. Paginado por cursor: `limit`, `antesDeId`, `paginado=1` (devuelve `{movimientos, hayMas}`) |
| `movimientos` | GET, POST | GET: listado con filtros (`fechaDesde`, `fechaHasta`, `tipo`, `sucursalId`, `conceptoId`, `colaboradorId`, `proveedorId`, `origenEgreso`, `asentDesde`, `asentHasta`, `orden`, `limit`, `offset`, `sinTotal`). POST: alta, con `retiros[]` para los ingresos |
| `movimiento` | GET, PUT, DELETE | Un movimiento por `id` |
| `existe-ingreso` | GET | Si ya hay un ingreso para `sucursalId` y `fecha` |
| `sucursales` / `sucursal` | GET, POST / DELETE | Lista y alta / baja |
| `sucursal-config` | PUT | Cambiar `rinde_caja` |
| `conceptos` / `concepto` | GET, POST / DELETE | Lista y alta / baja |
| `concepto-config` | PUT | Cambiar los flags del concepto |
| `colaboradores` / `colaborador` | GET, POST / DELETE | Lista y alta / baja |
| `proveedores` / `proveedor` | GET, POST / PUT, DELETE | Lista y alta / renombrar y baja |
| `reservas` / `reserva` | GET, POST / DELETE | Reservas con saldo / alta y baja |
| `reserva-movimientos` | GET | Historial de una reserva |
| `recordatorios-reserva` / `recordatorio-reserva` | GET, POST / DELETE | Recordatorios configurados |
| `recordatorios-activos` | GET | Recordatorios vencidos este mes y no descartados |
| `recordatorio-descartar` | POST | Descartar un recordatorio por el mes en curso |
| `anotaciones` / `anotacion` | GET, POST / DELETE | Anotaciones |
| `dias-cerrados` / `dia-cerrado` | GET, POST / DELETE | Días cerrados (acepta varias sucursales y un rango) |
| `rendiciones-faltantes` | GET | Rendiciones pendientes (`diasAtras`, 30 por defecto) |
| `reporte-ingresos` / `reporte-egresos` | GET | Totales y datos para gráficos, con los mismos filtros del listado |
| `reporte-general` | GET | Reporte completo con comparación contra el período anterior |
| `exportar` | GET | Copia completa de movimientos y catálogos en JSON |
| `recalcular` | GET | Recalcula el saldo de todos los movimientos |

---

## Instalación y despliegue

### Flujo de trabajo
El repositorio está conectado a Hostinger con **despliegue automático**: cada `push` a `main` se publica en `efectivo.saboryaroma.com` en unos segundos.

```bash
git add -A && git commit -m "descripción del cambio" && git push
```

Antes de cada push que toque `index.html`, subir la versión de `CACHE_NAME` en `sw.js`.

### Instalación desde cero
1. Crear la base MySQL y completar las credenciales en `config.php`.
2. Subir los archivos a la carpeta pública del sitio.
3. Ejecutar una vez `setup.php?setup_key=<clave definida en setup.php>`. Crea las tablas e índices y aplica las migraciones.
4. Entrar a `/?acceso=<clave>` y cargar sucursales, conceptos y demás desde Configuración.

`setup.php` se puede volver a ejecutar sin riesgo: usa `CREATE TABLE IF NOT EXISTS`, cada migración va en su propio bloque y los datos de ejemplo sólo se insertan si las tablas están vacías.

### URL anterior
La instalación vieja en `saboryaroma.com/efectivo/` redirige al subdominio con un `.htaccess`:

```apache
RedirectMatch 301 ^/efectivo/?$ https://efectivo.saboryaroma.com/
RedirectMatch 301 ^/efectivo/(.+)$ https://efectivo.saboryaroma.com/$1
```

---

## Mantenimiento

- **Recalcular Saldos** (Configuración → Herramientas): normaliza el saldo de todas las filas. Sirve después de migraciones o cambios manuales en la base.
- **Ver la versión nueva en el celular:** cerrar y reabrir la app, o `Ctrl+Shift+R` en el navegador.
- **Backups:** el `.gitignore` excluye los `.zip`, `.sql` y `.bak`. Los backups no deben subirse al repositorio: se desplegarían en la carpeta pública y cualquiera podría descargarlos.

---

## Seguridad y pendientes

- **El repositorio es público y `config.php` tiene las credenciales de la base.** Lo más simple para cerrarlo es pasar el repo a privado en GitHub (Settings → General → Change visibility). El despliegue sigue funcionando igual. Si hubiera actividad rara en la base, rotar la contraseña y actualizar `config.php`.
- **La API no tiene autenticación.** El `?acceso=` sólo protege la interfaz. Cualquiera que conozca la URL puede leer o modificar datos. Pendiente: validar un token en `api.php`.
- **El CDN de Hostinger cachea `sw.js` 7 días**, lo que demora la llegada de las versiones nuevas a los celulares. Se resuelve con este `.htaccess` en la carpeta del sitio:

  ```apache
  <FilesMatch "sw\.js$">
      Header set Cache-Control "no-cache, no-store, must-revalidate"
  </FilesMatch>
  ```
