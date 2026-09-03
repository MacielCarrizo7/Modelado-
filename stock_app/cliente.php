<?php
require "seguridad.php";
requerirPaginaAutenticada(["cliente"]);

$nombreUsuario = trim(($_SESSION["usuario_nombre"] ?? "Cliente") . " " . ($_SESSION["usuario_apellido"] ?? ""));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Catálogo de productos disponibles">
    <title>Mi cuenta | Control Stock</title>
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >
    <style>
        :root {
            --color-primary: #2563eb;
            --color-primary-dark: #1d4ed8;
            --color-ink: #172033;
            --color-muted: #68738a;
            --color-surface: #ffffff;
            --color-background: #f4f7fb;
            --color-border: #e5eaf2;
        }

        * {
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            margin: 0;
            color: var(--color-ink);
            background:
                radial-gradient(circle at top right, rgba(37, 99, 235, .10), transparent 34rem),
                var(--color-background);
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
        }

        .navbar-cliente {
            background: rgba(255, 255, 255, .94);
            border-bottom: 1px solid rgba(229, 234, 242, .9);
            backdrop-filter: blur(12px);
        }

        .marca-icono {
            display: grid;
            width: 2.35rem;
            height: 2.35rem;
            place-items: center;
            color: #fff;
            background: linear-gradient(135deg, var(--color-primary), #60a5fa);
            border-radius: .75rem;
            box-shadow: 0 .45rem 1rem rgba(37, 99, 235, .23);
        }

        .hero {
            overflow: hidden;
            position: relative;
            color: #fff;
            background: linear-gradient(125deg, #172554, #1d4ed8 58%, #3b82f6);
            border-radius: 1.35rem;
            box-shadow: 0 1rem 2.5rem rgba(30, 64, 175, .18);
        }

        .hero::after {
            content: "";
            position: absolute;
            width: 18rem;
            height: 18rem;
            right: -7rem;
            bottom: -10rem;
            border: 3rem solid rgba(255, 255, 255, .08);
            border-radius: 50%;
        }

        .hero-contenido {
            position: relative;
            z-index: 1;
        }

        .hero-etiqueta {
            color: #bfdbfe;
            font-size: .78rem;
            font-weight: 700;
            letter-spacing: .09em;
            text-transform: uppercase;
        }

        .texto-secundario {
            color: var(--color-muted);
        }

        .producto-card {
            height: 100%;
            background: var(--color-surface);
            border: 1px solid var(--color-border);
            border-radius: 1rem;
            box-shadow: 0 .5rem 1.5rem rgba(23, 32, 51, .06);
            transition: transform .2s ease, box-shadow .2s ease;
        }

        .producto-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 .85rem 2rem rgba(23, 32, 51, .10);
        }

        .producto-icono {
            display: grid;
            width: 2.8rem;
            height: 2.8rem;
            place-items: center;
            color: var(--color-primary);
            background: #eff6ff;
            border-radius: .85rem;
            font-size: 1.2rem;
            font-weight: 800;
        }

        .producto-precio {
            color: var(--color-primary-dark);
            font-size: 1.45rem;
            font-weight: 750;
        }

        .estado-disponible {
            color: #047857;
            background: #ecfdf5;
        }

        .estado-agotado {
            color: #b42318;
            background: #fef3f2;
        }

        .estado-stock {
            display: inline-flex;
            align-items: center;
            padding: .38rem .65rem;
            border-radius: 999px;
            font-size: .78rem;
            font-weight: 700;
        }

        .estado-stock::before {
            content: "";
            width: .45rem;
            height: .45rem;
            margin-right: .42rem;
            background: currentColor;
            border-radius: 50%;
        }

        .panel-estado {
            padding: 2.75rem 1.25rem;
            text-align: center;
            background: #fff;
            border: 1px dashed #cfd7e5;
            border-radius: 1rem;
        }

        .spinner-border {
            color: var(--color-primary);
        }

        .btn-salir {
            color: #334155;
            background: #fff;
            border-color: #cbd5e1;
        }

        .btn-salir:hover,
        .btn-salir:focus {
            color: #fff;
            background: var(--color-primary-dark);
            border-color: var(--color-primary-dark);
        }

        @media (max-width: 575.98px) {
            .hero {
                border-radius: 1rem;
            }

            .hero h1 {
                font-size: 1.75rem;
            }

            .producto-card:hover {
                transform: none;
            }
        }
    </style>
</head>
<body data-csrf="<?= htmlspecialchars(tokenCsrf(), ENT_QUOTES, "UTF-8") ?>">
    <nav class="navbar navbar-expand-sm navbar-cliente sticky-top py-3" aria-label="Navegación principal">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center gap-2 m-0" href="#inicio">
                <span class="marca-icono" aria-hidden="true">CS</span>
                <span class="fw-bold">Control Stock</span>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#menuCliente" aria-controls="menuCliente" aria-expanded="false" aria-label="Abrir menú"><span class="navbar-toggler-icon"></span></button>
            <div class="collapse navbar-collapse" id="menuCliente">
                <div class="navbar-nav ms-auto align-items-sm-center gap-sm-2 pt-3 pt-sm-0">
                    <a class="nav-link" href="#inicio">Inicio</a>
                    <a class="nav-link" href="#productos">Productos</a>
                    <a class="nav-link" href="#compras">Mis compras</a>
                    <a href="logout.php" class="btn btn-salir btn-sm px-3">Cerrar sesión</a>
                </div>
            </div>
        </div>
    </nav>

    <main class="container py-4 py-md-5">
        <?php if (isset($_GET["mfa_configurado"])): ?>
            <div class="alert alert-success" role="status">Tu autenticador se configuró correctamente.</div>
        <?php endif; ?>
        <section id="inicio" class="hero p-4 p-md-5 mb-5" aria-labelledby="saludo-cliente">
            <div class="hero-contenido">
                <p class="hero-etiqueta mb-2">Mi cuenta</p>
                <h1 id="saludo-cliente" class="display-6 fw-bold mb-2">
                    Hola, <?= htmlspecialchars($nombreUsuario, ENT_QUOTES, "UTF-8") ?>
                </h1>
                <p class="lead mb-0 text-white-50">Bienvenido. Consultá nuestro catálogo mayorista disponible.</p>
            </div>
        </section>

        <section id="productos" aria-labelledby="titulo-productos">
            <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-end gap-2 mb-4">
                <div>
                    <p class="text-primary fw-semibold mb-1">Catálogo</p>
                    <h2 id="titulo-productos" class="h3 fw-bold mb-1">Productos disponibles</h2>
                    <p class="texto-secundario mb-0">Encontrá la opción que mejor se adapta a vos.</p>
                </div>
            </div>

            <div id="estadoCarga" class="panel-estado" role="status">
                <div class="spinner-border spinner-border-sm mb-3" aria-hidden="true"></div>
                <p class="texto-secundario mb-0">Cargando productos...</p>
            </div>

            <div id="listadoProductos" class="row g-3 g-lg-4" aria-live="polite"></div>
        </section>

        <section id="compras" class="mt-5 pt-2" aria-labelledby="titulo-compras">
            <div class="mb-4">
                <p class="text-primary fw-semibold mb-1">Mi actividad</p>
                <h2 id="titulo-compras" class="h3 fw-bold mb-1">Mis compras</h2>
                <p class="texto-secundario mb-0">Consultá el estado y gestioná las compras que todavía no fueron canceladas.</p>
            </div>
            <div class="producto-card p-3 p-md-4">
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead><tr><th>Fecha</th><th>Producto</th><th>Cantidad / unidad</th><th>Precio</th><th>Margen</th><th>Bonificación</th><th>Precio final</th><th>Total</th><th>Estado</th><th>Acciones</th></tr></thead>
                        <tbody id="comprasBody"><tr><td colspan="10" class="text-center texto-secundario py-4">Cargando compras...</td></tr></tbody>
                    </table>
                </div>
            </div>
        </section>
    </main>

    <div class="modal fade" id="modalClienteCantidad" tabindex="-1" aria-labelledby="tituloClienteCantidad" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form id="formClienteCantidad"><div class="modal-header"><h2 id="tituloClienteCantidad" class="modal-title fs-5">Modificar cantidad</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div><div class="modal-body"><div id="errorClienteCantidad" class="alert alert-danger d-none"></div><input type="hidden" name="venta_id" id="clienteVentaCantidadId"><input type="hidden" name="accion" value="modificar_cantidad"><label for="clienteNuevaCantidad" class="form-label">Nueva cantidad</label><input type="number" min="1" id="clienteNuevaCantidad" name="cantidad" class="form-control" required><label for="clienteMotivoCantidad" class="form-label mt-3">Motivo (opcional)</label><textarea id="clienteMotivoCantidad" name="motivo" class="form-control" maxlength="500" rows="3"></textarea></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Volver</button><button type="submit" class="btn btn-primary">Guardar cambio</button></div></form></div></div></div>

    <div class="modal fade" id="modalClienteCancelar" tabindex="-1" aria-labelledby="tituloClienteCancelar" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form id="formClienteCancelar"><div class="modal-header"><h2 id="tituloClienteCancelar" class="modal-title fs-5">Cancelar compra</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div><div class="modal-body"><div id="errorClienteCancelar" class="alert alert-danger d-none"></div><input type="hidden" name="venta_id" id="clienteVentaCancelarId"><input type="hidden" name="accion" value="cancelar"><p class="texto-secundario">La compra seguirá visible como cancelada.</p><label for="clienteMotivoCancelar" class="form-label">Motivo (opcional)</label><textarea id="clienteMotivoCancelar" name="motivo" class="form-control" maxlength="500" rows="3"></textarea></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Volver</button><button type="submit" class="btn btn-danger">Cancelar compra</button></div></form></div></div></div>

    <footer class="container pb-4 pt-2 text-center">
        <small class="texto-secundario">Control Stock · Tu catálogo siempre disponible</small>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const estadoCarga = document.getElementById("estadoCarga");
        const listadoProductos = document.getElementById("listadoProductos");
        const formatoMoneda = new Intl.NumberFormat("es-AR", {
            style: "currency",
            currency: "ARS",
            minimumFractionDigits: 2
        });

        function crearTarjetaProducto(producto) {
            const columna = document.createElement("div");
            columna.className = "col-12 col-md-6 col-xl-4";

            const tarjeta = document.createElement("article");
            tarjeta.className = "producto-card p-4 d-flex flex-column";

            const encabezado = document.createElement("div");
            encabezado.className = "d-flex align-items-start justify-content-between gap-3 mb-4";

            const icono = document.createElement("span");
            icono.className = "producto-icono";
            icono.setAttribute("aria-hidden", "true");
            icono.textContent = "P";

            const stock = Number(producto.stock_vendible);
            const disponible = Number.isFinite(stock) && stock > 0;
            const estado = document.createElement("span");
            estado.className = `estado-stock ${disponible ? "estado-disponible" : "estado-agotado"}`;
            estado.textContent = disponible ? "Disponible" : "Sin stock";

            const nombre = document.createElement("h3");
            nombre.className = "h5 fw-bold mb-2";
            nombre.textContent = producto.nombre;

            const categoria = document.createElement("span");
            categoria.className = "badge rounded-pill text-bg-light border text-primary align-self-start mb-2";
            categoria.textContent = producto.categoria || "Producto";

            const presentacion = document.createElement("p");
            presentacion.className = "small fw-semibold mb-2";
            presentacion.textContent = producto.contenido_presentacion
                ? `${producto.unidad_singular} · ${producto.contenido_presentacion}`
                : producto.unidad_singular;

            const descripcion = document.createElement("p");
            descripcion.className = "texto-secundario small mb-4";
            descripcion.textContent = disponible
                ? `${stock} ${stock === 1 ? producto.unidad_singular : producto.unidad_plural} disponibles`
                : "Consultá próximamente por nueva disponibilidad.";

            const precio = document.createElement("p");
            precio.className = "producto-precio mt-auto mb-0";
            precio.textContent = `${formatoMoneda.format(Number(producto.precio))} / ${producto.unidad_singular}`;

            encabezado.append(icono, estado);
            tarjeta.append(encabezado, categoria, nombre, presentacion, descripcion, precio);
            columna.appendChild(tarjeta);

            return columna;
        }

        function mostrarMensaje(mensaje, esError = false) {
            listadoProductos.replaceChildren();
            estadoCarga.classList.remove("d-none");
            estadoCarga.setAttribute("role", esError ? "alert" : "status");
            estadoCarga.innerHTML = "";

            const titulo = document.createElement("p");
            titulo.className = `${esError ? "text-danger" : "texto-secundario"} fw-semibold mb-1`;
            titulo.textContent = mensaje;

            const detalle = document.createElement("small");
            detalle.className = "texto-secundario";
            detalle.textContent = esError
                ? "Actualizá la página para volver a intentarlo."
                : "Volvé a consultar más tarde.";

            estadoCarga.append(titulo, detalle);
        }

        async function cargarProductos() {
            try {
                const respuesta = await fetch("obtener_productos.php", {
                    headers: { "Accept": "application/json" }
                });

                if (respuesta.status === 401) {
                    window.location.href = "login.php";
                    return;
                }

                if (!respuesta.ok) {
                    throw new Error("No fue posible obtener el catálogo.");
                }

                const productos = await respuesta.json();
                estadoCarga.classList.add("d-none");
                listadoProductos.replaceChildren();

                if (!Array.isArray(productos) || productos.length === 0) {
                    mostrarMensaje("Todavía no hay productos disponibles.");
                    return;
                }

                productos.forEach((producto) => {
                    listadoProductos.appendChild(crearTarjetaProducto(producto));
                });
            } catch (error) {
                mostrarMensaje("No pudimos cargar los productos.", true);
            }
        }

        function fechaCompraLegible(valor) {
            if (!valor) return "—";
            const fecha = new Date(String(valor).replace(" ", "T"));
            return Number.isNaN(fecha.getTime()) ? valor : fecha.toLocaleString("es-AR");
        }

        function estadoCompra(estado) {
            const badge = document.createElement("span");
            const clases = { ACTIVA: "text-bg-success", MODIFICADA: "text-bg-warning", CANCELADA: "text-bg-danger" };
            badge.className = `badge ${clases[estado] || "text-bg-secondary"}`;
            badge.textContent = estado.charAt(0) + estado.slice(1).toLowerCase();
            return badge;
        }

        function tdCompra(texto, clase = "") {
            const td = document.createElement("td");
            td.className = clase;
            td.textContent = texto;
            return td;
        }

        async function cargarCompras() {
            const tbody = document.getElementById("comprasBody");
            try {
                const respuesta = await fetch("obtener_compras_cliente.php", { headers: { "Accept": "application/json" } });
                if (!respuesta.ok) throw new Error("No se pudieron cargar las compras.");
                const compras = await respuesta.json();
                tbody.replaceChildren();
                if (compras.length === 0) {
                    const fila = document.createElement("tr");
                    const vacio = tdCompra("Todavía no tenés compras asociadas a tu cuenta.", "text-center texto-secundario py-4");
                    vacio.colSpan = 10;
                    fila.appendChild(vacio);
                    tbody.appendChild(fila);
                    return;
                }
                compras.forEach((compra) => {
                    const fila = document.createElement("tr");
                    fila.append(tdCompra(fechaCompraLegible(compra.fecha)));
                    fila.append(tdCompra(`#${compra.id} · ${compra.producto_nombre}`, "fw-semibold"));
                    fila.append(tdCompra(`${compra.cantidad} ${Number(compra.cantidad) === 1 ? compra.unidad_venta_snapshot : compra.unidad_venta_plural_snapshot}`));
                    fila.append(tdCompra(formatoMoneda.format(Number(compra.precio_referencial_snapshot))));
                    fila.append(tdCompra(`${Number(compra.porcentaje_vendedor)}%`));
                    fila.append(tdCompra(`${Number(compra.bonificacion_porcentaje)}%`));
                    fila.append(tdCompra(formatoMoneda.format(Number(compra.precio_final_unitario))));
                    fila.append(tdCompra(formatoMoneda.format(Number(compra.total)), "fw-semibold"));
                    const estadoTd = document.createElement("td");
                    estadoTd.appendChild(estadoCompra(compra.estado));
                    fila.appendChild(estadoTd);
                    const acciones = document.createElement("td");
                    acciones.className = "text-nowrap";
                    if (compra.estado !== "CANCELADA") {
                        const cantidad = document.createElement("button");
                        cantidad.type = "button";
                        cantidad.className = "btn btn-outline-primary btn-sm me-2";
                        cantidad.textContent = "Cantidad";
                        cantidad.addEventListener("click", () => {
                            document.getElementById("clienteVentaCantidadId").value = compra.id;
                            document.getElementById("clienteNuevaCantidad").value = compra.cantidad;
                            bootstrap.Modal.getOrCreateInstance(document.getElementById("modalClienteCantidad")).show();
                        });
                        const cancelar = document.createElement("button");
                        cancelar.type = "button";
                        cancelar.className = "btn btn-outline-danger btn-sm";
                        cancelar.textContent = "Cancelar";
                        cancelar.addEventListener("click", () => {
                            document.getElementById("clienteVentaCancelarId").value = compra.id;
                            bootstrap.Modal.getOrCreateInstance(document.getElementById("modalClienteCancelar")).show();
                        });
                        acciones.append(cantidad, cancelar);
                    } else {
                        acciones.textContent = "—";
                    }
                    fila.appendChild(acciones);
                    tbody.appendChild(fila);
                });
            } catch (error) {
                tbody.innerHTML = '<tr><td colspan="10" class="text-center text-danger py-4">No pudimos cargar tus compras.</td></tr>';
            }
        }

        async function enviarCambioCliente(formulario, modalId, errorId) {
            const errorBox = document.getElementById(errorId);
            errorBox.classList.add("d-none");
            try {
                const respuesta = await fetch("modificar_venta.php", {
                    method: "POST",
                    headers: { "X-CSRF-Token": document.body.dataset.csrf },
                    body: new FormData(formulario)
                });
                const datos = await respuesta.json();
                if (!respuesta.ok) throw new Error(datos.error || "No se pudo actualizar la compra.");
                bootstrap.Modal.getInstance(document.getElementById(modalId)).hide();
                formulario.reset();
                await Promise.all([cargarProductos(), cargarCompras()]);
            } catch (error) {
                errorBox.textContent = error.message;
                errorBox.classList.remove("d-none");
            }
        }

        document.getElementById("formClienteCantidad").addEventListener("submit", (evento) => {
            evento.preventDefault();
            enviarCambioCliente(evento.currentTarget, "modalClienteCantidad", "errorClienteCantidad");
        });
        document.getElementById("formClienteCancelar").addEventListener("submit", (evento) => {
            evento.preventDefault();
            enviarCambioCliente(evento.currentTarget, "modalClienteCancelar", "errorClienteCancelar");
        });

        cargarProductos();
        cargarCompras();
    </script>
</body>
</html>
