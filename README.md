# 📦 Caja SYA - Sistema de Efectivo

> Sistema de gestión de caja para control de ingresos y egresos de efectivo

---

## 🎯 Objetivo

**Caja SYA** es una aplicación web diseñada para llevar un **control integral del flujo de efectivo** de un negocio con múltiples sucursales. Permite registrar, consultar y analizar todos los movimientos de dinero de manera centralizada.

---

## ✨ Funcionalidades Principales

### 1. Registro de Movimientos
- **Ingresos**: Dinero que entra (ej: ventas, recaudación de sucursales)
- **Egresos**: Dinero que sale (ej: pagos, sueldos, gastos varios)
- Cada movimiento registra: fecha, monto, sucursal/concepto, y observaciones

### 2. Control de Saldo en Tiempo Real
- Saldo actual siempre visible en el header
- Recálculo automático tras cada operación
- Fecha del último ingreso registrado

### 3. Gestión Multi-Sucursal
- Configurar sucursales activas
- Asociar ingresos a sucursales específicas
- Ver reportes filtrados por sucursal

### 4. Categorización de Egresos
- Conceptos personalizables (proveedores, servicios, sueldos, etc.)
- Filtrar egresos por concepto
- Análisis de gastos por categoría

### 5. Reportes y Análisis
- **Ingresos**: Total por período, gráficos por sucursal
- **Egresos**: Total por período, gráficos por concepto
- Filtros por fecha, sucursal y concepto
- Exportación de datos

---

## 🏗️ Arquitectura

| Componente | Tecnología | Archivo |
|------------|------------|---------|
| Frontend | HTML + CSS + JavaScript | `index.html` |
| Backend API | PHP | `api.php` |
| Base de Datos | MySQL | `config.php` |
| Setup inicial | PHP | `setup.php` |

---

## 📊 Modelo de Datos

```
┌─────────────────┐     ┌─────────────────┐     ┌─────────────────┐
│   SUCURSALES    │     │   MOVIMIENTOS   │     │    CONCEPTOS    │
├─────────────────┤     ├─────────────────┤     ├─────────────────┤
│ id              │◄────│ sucursal_id     │     │ id              │
│ nombre          │     │ concepto_id     │────►│ nombre          │
│ activo          │     │ fecha           │     │ activo          │
└─────────────────┘     │ tipo            │     └─────────────────┘
                        │ monto           │
                        │ saldo           │
                        │ observacion     │
                        └─────────────────┘
```

---

## 🔐 Acceso

La aplicación requiere un parámetro de acceso en la URL:
```
https://saboryaroma.com/efectivo/?acceso=sya2025
```

---

## 📱 Secciones de la App

| Sección | Descripción |
|---------|-------------|
| **Movimientos** | Registrar ingresos/egresos, ver últimos 10 movimientos |
| **Ingresos** | Reporte completo de ingresos con filtros y gráficos |
| **Egresos** | Reporte completo de egresos con filtros y gráficos |
| **Configuración** | Gestionar sucursales, conceptos y herramientas |

---

## ⚡ Características Técnicas

- ✅ Single Page Application (SPA)
- ✅ Actualizaciones dinámicas sin refresh
- ✅ Recálculo automático de saldos
- ✅ Validación de datos servidor/cliente
- ✅ Diseño responsive
- ✅ Gráficos interactivos (Chart.js)
