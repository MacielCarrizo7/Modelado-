<?php
require "seguridad.php";
requerirPaginaAutenticada(["admin"]);
$productoId=filter_input(INPUT_GET,"id",FILTER_VALIDATE_INT)?:0;
if($productoId<=0){http_response_code(400);exit("Producto inválido.");}
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Detalle de producto | Control Stock</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"><link href="assets/estilos.css" rel="stylesheet"></head>
<body data-producto-id="<?= $productoId ?>">
<nav class="navbar app-navbar py-3"><div class="container"><a class="navbar-brand fw-bold" href="admin.php#productos">← Volver al inventario</a><span class="navbar-text">Detalle y reporte</span></div></nav>
<main class="container py-4 py-md-5"><div id="errorDetalle" class="alert alert-danger d-none"></div>
<section class="hero-panel p-4 mb-4"><p class="etiqueta text-white-50 mb-2">Producto</p><h1 id="detalleNombre" class="h2 fw-bold mb-1">Cargando…</h1><p id="detalleResumen" class="text-white-50 mb-0"></p></section>
<section class="row g-3 mb-4" id="metricas"></section>
<section class="seccion-card mb-4"><h2 class="h5 fw-bold mb-3">Identificación y ciclo de vida</h2><div class="row g-3" id="identificacion"></div></section>
<section class="seccion-card mb-4"><h2 class="h5 fw-bold mb-3">Lotes y vencimientos</h2><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Lote</th><th>Inicial</th><th>Stock</th><th>Ingreso</th><th>Vencimiento</th><th>Estado</th></tr></thead><tbody id="lotesBody"></tbody></table></div></section>
<section id="reporte" class="seccion-card mb-4"><div class="d-flex flex-column flex-md-row justify-content-between gap-3 mb-3"><div><p class="etiqueta text-primary mb-1">Uso del producto</p><h2 class="h5 fw-bold mb-0">Reporte</h2></div><form id="filtroReporte" class="d-flex flex-wrap gap-2 align-items-end"><div><label class="form-label small" for="desde">Desde</label><input id="desde" name="desde" type="date" class="form-control"></div><div><label class="form-label small" for="hasta">Hasta</label><input id="hasta" name="hasta" type="date" class="form-control"></div><button class="btn btn-primary">Aplicar</button><button id="limpiarReporte" type="button" class="btn btn-outline-secondary">Limpiar</button></form></div><div class="row g-3" id="metricasReporte"></div></section>
<section class="seccion-card mb-4"><h2 class="h5 fw-bold mb-3">Ventas</h2><div class="table-responsive"><table class="table"><thead><tr><th>Fecha</th><th>Operación</th><th>Cantidad</th><th>Equivalencia</th><th>Total</th><th>Estado</th></tr></thead><tbody id="ventasDetalleBody"></tbody></table></div></section>
<section class="seccion-card"><h2 class="h5 fw-bold mb-3">Movimientos y auditoría</h2><div class="table-responsive"><table class="table"><thead><tr><th>Fecha</th><th>Tipo</th><th>Cambio</th><th>Stock</th><th>Lote</th><th>Motivo</th></tr></thead><tbody id="movimientosDetalleBody"></tbody></table></div><hr><div id="historialProducto" class="small"></div></section>
</main><script src="assets/producto_detalle.js"></script></body></html>
