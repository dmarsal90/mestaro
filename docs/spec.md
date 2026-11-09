# MESTARO — Plataforma SaaS de gestión integral para restaurantes

Especificación maestra · Implementación de referencia en Laravel

## 0. Cómo trabajar con esta especificación (leer primero)

Este documento es la especificación completa del producto. Guárdalo en el repositorio como "docs/spec.md" y trátalo como la fuente de verdad. NO implementes todo el documento de una vez.

Protocolo de trabajo por fases:

1. El desarrollo se hace fase a fase (ver apartado 19), en colaboración con el desarrollador humano.
2. Cada sesión empezará con una instrucción del tipo: "Implementa la Fase N según docs/spec.md".
3. Al empezar una fase:
   - Lee "docs/spec.md", "docs/progress.md" y "docs/decisions.md".
   - Revisa el código existente antes de crear nada nuevo.
   - Presenta un plan breve de la fase (archivos, migraciones, decisiones) y espera confirmación antes de escribir código si la fase incluye decisiones de arquitectura no resueltas.
4. Implementa SOLO la fase solicitada. Si detectas algo necesario de otra fase, anótalo en "docs/progress.md" en lugar de implementarlo.
5. Al terminar una fase:
   - Ejecuta migraciones, tests (Pest), análisis estático (Larastan nivel 6 o superior) y Pint.
   - Actualiza "docs/progress.md" (qué se hizo, qué quedó pendiente, deuda técnica conocida).
   - Registra cada decisión técnica relevante en "docs/decisions.md" (formato ADR corto: contexto, opciones, decisión, consecuencias).
   - Actualiza "docs/api.md", "docs/realtime.md" y "docs/openapi.yaml" si cambió algún contrato.
   - Entrega un resumen final y DETENTE. No empieces la fase siguiente sin instrucción expresa.
6. Si una instrucción de la sesión contradice este documento, señálalo antes de continuar.

Existirá una réplica del backend en Java (Spring Boot) que consumirá estos mismos contratos. Por eso los contratos de API y de tiempo real son tan importantes como el propio código (ver apartado 10).

## 1. Identidad del proyecto

Desarrolla Mestaro, una plataforma SaaS profesional para la gestión integral de restaurantes, diseñada para centralizar las operaciones de un establecimiento desde un único sistema.

Mestaro no debe ser únicamente un sistema de pedidos mediante códigos QR. Debe ser una solución completa de gestión y operación de restaurantes que incluya POS, pedidos, mesas, clientes, cocina, barra, personal, pagos, inventario, informes y administración.

El sistema debe estar preparado para funcionar con múltiples restaurantes, permitiendo que cada establecimiento gestione su propia operación de manera independiente y segura.

Nombre comercial

Mestaro

Posicionamiento

Restaurant Management System / Restaurant POS & Management Platform.

Personalidad de marca

- Tecnológica.
- Profesional.
- Elegante.
- Moderna.
- Fiable.
- Premium.

Objetivo del producto

Construir un software comercializable mediante suscripción SaaS, escalable, mantenible y preparado para incorporar nuevas funcionalidades e integraciones sin tener que modificar sustancialmente su arquitectura.

---

## 2. Identidad visual y branding

Toda la interfaz debe seguir una identidad visual consistente basada en la combinación Black, Graphite & Champagne Gold.

### 2.1 Paleta oficial

| Color | Código | Uso |
|---|---|---|
| Obsidian Black | #171717 | Identidad, navegación y superficies principales |
| Graphite | #292929 | Paneles, elementos secundarios y superficies oscuras |
| Silver Gray | #A6A6A6 | Iconos, textos secundarios y elementos neutros |
| Soft Ivory | #F5F3EE | Fondos principales y superficies claras |
| Champagne Gold | #D6B36A | Acentos de marca y acciones destacadas |
| White | #FFFFFF | Tarjetas y superficies de contenido |
| Text Gray | #6B6B6B | Texto secundario en modo claro (4,8:1 sobre Soft Ivory) |

Reglas de contraste (obligatorias, verificadas contra WCAG 2.2 AA)

- Silver Gray (#A6A6A6) NO debe usarse como texto sobre Soft Ivory (2,2:1) ni sobre White (2,4:1). En modo claro, el texto secundario usa Text Gray (#6B6B6B). En modo oscuro Silver Gray sí es válido (7,4:1 sobre Obsidian, 6:1 sobre Graphite).
- Champagne Gold (#D6B36A) NO debe usarse como color de texto ni de icono informativo sobre fondos claros (1,8:1 sobre Soft Ivory). Sobre Obsidian es válido (9:1).
- Botones principales: fondo Champagne Gold con texto Obsidian Black (9:1).
- Todo par texto/fondo nuevo debe verificarse con un mínimo de 4,5:1 (3:1 para texto grande y componentes de interfaz). Añade un test automatizado o script que valide los pares definidos en los tokens.

Reglas de utilización

- El negro y el grafito deben proporcionar la identidad visual principal.
- El Ivory y el blanco deben aportar claridad y legibilidad.
- El dorado debe utilizarse como color de acento, no como color dominante.
- Los botones principales pueden utilizar Champagne Gold con texto Obsidian Black.
- No utilizar dorado para representar todos los estados de la aplicación.
- Verde para éxito, rojo para errores y ámbar para advertencias.
- Mantener suficiente contraste entre texto, fondos, controles y estados.

Proporción visual orientativa:

- 60 % fondos y superficies claras.
- 25 % negro y grafito.
- 10 % neutros.
- 5 % dorado.

### 2.2 Modos de visualización

Implementar dos modos completos:

Modo claro

- Fondo Soft Ivory.
- Tarjetas White.
- Navegación Obsidian o Graphite.
- Texto principal Obsidian.
- Acciones destacadas Champagne Gold.

Modo oscuro

- Fondo Obsidian.
- Superficies Graphite.
- Texto principal White o Soft Ivory.
- Texto secundario Silver Gray.
- Acentos Champagne Gold.

La selección del modo debe persistir entre sesiones y respetar la preferencia del usuario.

### 2.3 Logotipo e iconografía de marca

El símbolo oficial de Mestaro será un monograma geométrico de la letra M.

Características obligatorias:

- M angular, geométrica y simétrica visualmente.
- Construcción mediante trazos sólidos y formas poligonales.
- Estética elegante, tecnológica y premium.
- Color principal Champagne Gold (#D6B36A).
- Versión principal sobre fondo Obsidian Black (#171717).
- Sin degradados, sombras, texturas ni efectos 3D en los archivos finales.
- Debe conservar su reconocimiento incluso en tamaños reducidos.

El logotipo completo estará compuesto por:

- Símbolo M.
- Nombre "mestaro" en minúsculas.
- Tipografía sans serif geométrica, moderna y elegante.

Preparar las siguientes variantes:

- Símbolo dorado sobre fondo negro.
- Símbolo negro sobre fondo claro.
- Logotipo completo para fondos oscuros.
- Logotipo completo para fondos claros.
- Versión monocromática.

Recursos necesarios:

- "mestaro-symbol.svg"
- "mestaro-logo-dark.svg"
- "mestaro-logo-light.svg"
- "favicon.ico"
- "favicon-16x16.png"
- "favicon-32x32.png"
- "apple-touch-icon.png"
- "android-chrome-192x192.png"
- "android-chrome-512x512.png"

El SVG debe ser vectorial, limpio, optimizado y escalable. No utilizar imágenes rasterizadas como sustituto del logotipo vectorial.

El símbolo debe ser reconocible en un favicon de 16 × 16 píxeles.

Origen de los recursos de marca

El logotipo y el favicon los aporta el equipo (boceto conceptual ya existente, que se vectorizará aparte). NO diseñes ni inventes el logotipo. Si los archivos finales todavía no están en "resources/brand/", usa un marcador temporal neutro (la letra "M" en un cuadrado Obsidian con texto Champagne Gold), regístralo como pendiente en "docs/progress.md" y deja la integración preparada para sustituirlo copiando los archivos con los nombres indicados arriba.

### 2.4 Sistema de diseño

Crear un sistema de diseño reutilizable con:

- Tokens de color.
- Tipografía y escalas tipográficas.
- Espaciados.
- Bordes y radios.
- Sombras discretas.
- Botones.
- Inputs y formularios.
- Tablas.
- Modales.
- Menús y navegación.
- Alertas y notificaciones.
- Estados de carga.
- Estados vacíos.
- Componentes responsivos.

Evitar estilos visuales duplicados o definidos de manera inconsistente en diferentes módulos.

Toda la interfaz debe mantener coherencia visual.

---

## 3. Stack tecnológico

Utilizar las siguientes tecnologías:

Backend

- PHP 8.3.
- Laravel 12.
- MySQL 8 o PostgreSQL.
- Redis.
- Laravel Reverb.
- Laravel Echo.
- Laravel Sanctum.
- Filament (versión estable compatible con Laravel 12; comprobar si corresponde la 3.x o la 4.x y documentar la elección en docs/decisions.md).
- Larastan y Laravel Pint para calidad de código.
- API REST versionada.

Frontend

- Vue 3.
- Vite.
- TypeScript.
- Pinia.
- Tailwind CSS.

Testing

- Pest.
- PHPUnit cuando sea necesario para compatibilidad con herramientas existentes.

Infraestructura

- Docker.
- Docker Compose.
- Nginx.
- PHP-FPM.
- Redis.
- Base de datos independiente.
- Configuración mediante variables de entorno.

Antes de implementar, verificar la compatibilidad real entre las versiones seleccionadas y sus dependencias. No forzar versiones incompatibles. Documentar cualquier ajuste técnico necesario.

---

## 4. Arquitectura y organización del código

Utilizar una arquitectura modular, mantenible y orientada al dominio.

Separar claramente:

- Presentación.
- Aplicación.
- Dominio.
- Infraestructura.

Aplicar principios SOLID, separación de responsabilidades y bajo acoplamiento.

Reglas de arquitectura

1. Los controladores deben ser delgados.
2. La lógica de negocio debe residir en Actions, Services o casos de uso.
3. No implementar lógica de negocio compleja directamente en controladores.
4. Utilizar Form Requests para validar las entradas HTTP.
5. Utilizar Policies y Gates para la autorización.
6. Utilizar Enums para estados y valores controlados.
7. Utilizar DTOs para transportar información entre capas cuando resulte apropiado.
8. Separar las operaciones de lectura y escritura cuando aporte claridad y mantenibilidad.
9. Evitar servicios gigantes y clases con demasiadas responsabilidades.
10. Evitar duplicación de lógica.
11. Aplicar transacciones de base de datos en operaciones críticas.
12. Utilizar eventos y listeners para desacoplar procesos secundarios.
13. Utilizar Jobs y colas para procesos que no deban bloquear peticiones HTTP.
14. Centralizar reglas de negocio que afecten a precios, impuestos, descuentos y totales.

Organizar los módulos de manera que puedan evolucionar independientemente sin perder coherencia con el dominio general.

No introducir complejidad arquitectónica innecesaria.

---

## 5. Modelo SaaS multi-restaurante

El sistema debe soportar múltiples restaurantes dentro de una misma instalación.

Cada restaurante debe disponer de:

- Información comercial.
- Configuración general.
- Horarios.
- Moneda.
- Impuestos.
- Zonas y mesas.
- Categorías y productos.
- Personal.
- Pedidos.
- Clientes.
- Pagos.
- Inventario.
- Informes.
- Configuración de impresión.
- Preferencias visuales.

Aislamiento multi-tenant

Cada restaurante constituye un tenant independiente.

Implementar aislamiento estricto de los datos:

- Las consultas deben limitarse al tenant autorizado.
- Las operaciones de escritura deben verificar la pertenencia de los recursos.
- Los identificadores enviados por el cliente nunca deben considerarse prueba de autorización.
- Las relaciones entre entidades deben comprobar que pertenecen al mismo restaurante.
- Los usuarios no pueden acceder a información de otros restaurantes.
- Los trabajos en segundo plano deben conservar el contexto del tenant.
- Los eventos de Reverb deben emitirse exclusivamente a canales autorizados.
- Las exportaciones y descargas deben respetar el aislamiento.
- Los procesos administrativos deben comprobar expresamente los permisos correspondientes.

No confiar únicamente en filtros visuales del frontend para garantizar el aislamiento.

Implementar pruebas automatizadas que intenten acceder a recursos de otro restaurante y comprueben que el acceso es rechazado.

Estrategia multi-tenant decidida

- Una única base de datos compartida con columna "restaurant_id" en todas las tablas de negocio, índices compuestos que empiecen por "restaurant_id" y claves foráneas que lo respeten.
- Un trait/global scope (por ejemplo "BelongsToRestaurant") que filtre automáticamente por el tenant activo y rellene "restaurant_id" al crear. Las consultas que deban saltarse el scope tienen que hacerlo de forma explícita y justificada.
- Resolución del tenant activo mediante un servicio "CurrentRestaurant" alimentado por middleware (usuario autenticado + restaurante seleccionado, o sesión de mesa en el flujo QR).
- Usar la multi-tenancy nativa de Filament para el panel administrativo.
- Los Jobs serializan el "restaurant_id" y restauran el contexto antes de ejecutarse.
- No usar paquetes de tenancy con base de datos por tenant (como stancl/tenancy) salvo que se justifique en docs/decisions.md.

Preparar la arquitectura para un futuro sistema de planes, suscripciones y límites por restaurante.

---

## 6. Roles y permisos

Implementar un sistema de autorización basado en roles y permisos.

Roles iniciales:

Cliente

- Acceder al menú mediante QR.
- Consultar productos y precios.
- Crear pedidos.
- Consultar sus pedidos.
- Llamar al camarero.
- Solicitar la cuenta.
- Consultar información de su sesión.

Camarero

- Consultar mesas.
- Consultar pedidos.
- Recibir llamadas.
- Aceptar y gestionar solicitudes.
- Registrar pedidos manuales.
- Actualizar estados autorizados.
- Gestionar solicitudes de cuenta.

Cocina

- Consultar pedidos asignados a cocina.
- Visualizar los artículos pendientes.
- Iniciar la preparación de artículos ("en marcha").
- Actualizar estados de preparación.
- Marcar artículos como listos.

Barra

- Consultar pedidos asignados a barra.
- Gestionar preparación de bebidas.
- Actualizar estados.
- Marcar artículos como listos.

Administrador del restaurante

- Gestionar el restaurante.
- Gestionar usuarios y permisos.
- Gestionar mesas.
- Gestionar categorías y productos.
- Consultar pedidos.
- Gestionar inventario.
- Consultar informes.
- Gestionar configuraciones.

Los permisos deben ser granulares y estar vinculados al restaurante correspondiente.

Preparar la arquitectura para futuros roles personalizados.

---

## 7. Módulos funcionales

### 7.1 Gestión de restaurantes

Crear el módulo para registrar y administrar establecimientos.

Campos iniciales:

- Nombre.
- Identificador único.
- Razón social.
- Dirección.
- Teléfono.
- Correo electrónico.
- Logotipo.
- Moneda.
- Zona horaria.
- Impuestos.
- Horarios.
- Estado.
- Configuración general.

### 7.2 Gestión de usuarios y empleados

- Crear usuarios.
- Editar usuarios.
- Activar y desactivar usuarios.
- Asignar roles.
- Asignar permisos.
- Asociar usuarios a restaurantes.
- Gestionar sesiones.
- Registrar actividad relevante.

Un usuario puede tener acceso a uno o varios restaurantes cuando tenga autorización expresa.

### 7.3 Gestión de mesas y zonas

- Crear zonas.
- Crear mesas.
- Asignar identificadores.
- Configurar capacidad.
- Generar códigos QR.
- Activar y desactivar mesas.
- Consultar ocupación.
- Consultar pedidos asociados.
- Gestionar sesiones abiertas.

Cada mesa debe tener un código QR único y no predecible.

Los QR no deben contener credenciales administrativas ni secretos permanentes.

### 7.4 Gestión del menú

- Categorías.
- Productos.
- Descripciones.
- Imágenes.
- Precios.
- Impuestos.
- Variantes.
- Complementos.
- Ingredientes.
- Alérgenos.
- Disponibilidad.
- Horarios de disponibilidad.
- Menús especiales.
- Menú del día.
- Productos agotados.

Los productos deben poder asignarse a diferentes destinos operativos:

- Cocina.
- Barra.
- Otros destinos configurables.

### 7.5 Pedidos mediante QR

Flujo principal:

1. El cliente escanea el QR de una mesa.
2. Accede al menú del restaurante.
3. El sistema identifica la mesa.
4. Se crea o recupera una sesión válida.
5. El cliente consulta categorías y productos.
6. Añade productos al carrito.
7. Selecciona variantes y complementos.
8. Confirma el pedido.
9. El backend valida disponibilidad y precios.
10. Se registra el pedido.
11. Se distribuyen los artículos a sus destinos correspondientes.
12. Se notifica a cocina, barra o personal mediante eventos en tiempo real.
13. El cliente puede consultar el estado de su pedido.

### 7.6 Sesiones compartidas

Permitir que varios clientes compartan una misma mesa.

Implementar:

- Sesiones por mesa.
- Código de incorporación ("join_code").
- Identificadores de participantes.
- Pedidos asociados a la sesión.
- Control de acceso.
- Estado de la sesión.
- Expiración y cierre.

Los códigos de incorporación deben tener suficiente entropía, caducidad, limitación de intentos y protección contra reutilización indebida.

Especificación concreta del join_code (equilibrio entre seguridad y que el cliente pueda teclearlo):

- 6 caracteres de un alfabeto sin caracteres ambiguos (sin 0/O, 1/I/L), generados con un generador criptográficamente seguro.
- Único entre las sesiones abiertas del restaurante.
- Válido solo mientras la sesión está abierta; al cerrarla queda invalidado y no se reutiliza en un periodo configurable.
- Rate limiting por IP y por mesa (por ejemplo, 5 intentos fallidos en 10 minutos) con bloqueo temporal.
- Incorporarse con el código devuelve un token de participante (opaco, aleatorio, de un solo uso para emitir la credencial de sesión) que es lo que autoriza las operaciones posteriores; el join_code nunca se usa como credencial en cada petición.

Identificación vs autorización en el flujo QR:

- El QR de la mesa contiene solo un "qr_token" opaco y no predecible que identifica la mesa; no autoriza nada por sí mismo.
- Al escanearlo se crea o recupera la sesión y se emite una credencial de participante ligada a esa sesión.
- Configurable por restaurante: la primera apertura de sesión puede requerir confirmación del camarero, para evitar pedidos desde fuera del local con una foto del QR.

No permitir que un participante acceda a sesiones arbitrarias mediante la modificación de identificadores.

### 7.7 Gestión de pedidos

Modelo de estados (decidido)

Los estados se gestionan a dos niveles, porque un mismo pedido puede tener artículos en cocina y en barra que avanzan a ritmos distintos.

Estado del pedido (orders.status) — controla la aceptación:

- pending → accepted | rejected
- pending | accepted → cancelled (con permiso y motivo)

Estado de cada artículo (order_items.status) — controla la preparación por destino:

- queued (pedido aceptado, esperando en su destino) → preparing → ready → delivered
- queued | preparing → cancelled (con permiso y motivo)

Estado de progreso del pedido (derivado, nunca almacenado como fuente de verdad): se calcula a partir de sus artículos (por ejemplo "parcialmente listo", "todo entregado") y se expone en la API como campo calculado.

¿Quién acepta? Por defecto el camarero. Configurable por restaurante: aceptación automática (los pedidos pasan directamente a accepted). Cocina y barra NO aceptan pedidos; solo gestionan la preparación de sus artículos.

Marcas de tiempo obligatorias para métricas:

- orders: created_at, accepted_at, rejected_at, accepted_by.
- order_items: queued_at, preparing_at, ready_at, delivered_at, cancelled_at, y el usuario que realizó cada cambio.
- Tabla de historial (order_status_history) con cada transición, quién la hizo y cuándo.

Las transiciones válidas se definen en un único lugar (máquina de estados en el dominio) y se prueban con tests unitarios.

Implementar:

- Aceptación y rechazo.
- Actualización de estados.
- Historial de cambios.
- Observaciones.
- Gestión de artículos individuales.
- Cancelaciones autorizadas.
- Motivos de rechazo.
- Control de concurrencia.

Los estados deben validarse en el backend y seguir transiciones permitidas.

### 7.8 Kitchen Display System (KDS)

Crear una interfaz específica para cocina y barra.

Características:

- Pedidos en tiempo real.
- Separación por destino.
- Indicadores de tiempo.
- Priorización.
- Estados visuales.
- Actualización sin recargar.
- Notificaciones.
- Gestión de pedidos pendientes.
- Visualización de observaciones y modificaciones.

Debe ser responsive y estar optimizada para tablets y pantallas táctiles.

### 7.9 Inventario y disponibilidad

Implementar una primera versión de gestión de existencias:

- Inventario de productos e ingredientes.
- Entradas y salidas.
- Ajustes manuales.
- Stock mínimo.
- Historial de movimientos.
- Productos agotados.
- Descuento de existencias cuando corresponda.
- Alertas de stock.

Preparar el modelo para incorporar posteriormente recetas, escandallos, proveedores y compras.

Los cambios de disponibilidad deben propagarse en tiempo real a las interfaces autorizadas.

### 7.10 Llamadas al camarero

El cliente podrá:

- Solicitar asistencia.
- Solicitar determinados servicios.
- Enviar una observación.

El personal recibirá notificaciones en tiempo real.

Registrar las solicitudes, su estado, su asignación y su resolución.

### 7.11 Cuenta y pagos

Permitir:

- Solicitar la cuenta.
- Consultar el total.
- Dividir la cuenta.
- Pagar individualmente.
- Registrar pagos parciales.
- Registrar propinas cuando proceda.
- Consultar pagos.
- Cerrar la sesión de mesa.

El backend será responsable de calcular todos los importes.

No confiar en los importes enviados desde el frontend.

No almacenar números completos de tarjetas, códigos CVV ni credenciales de pago.

Preparar una arquitectura de integración con pasarelas de pago externas, utilizando sus mecanismos seguros de tokenización y confirmación.

Los webhooks de pago deben verificar firmas, evitar duplicidades y gestionar reintentos de forma idempotente.

Modos de división de la cuenta (calculados en backend):

- A partes iguales entre N personas.
- Por participante: cada participante paga lo que pidió (según la sesión de participante que creó cada artículo).
- Por artículos: se seleccionan artículos concretos para un pago.
- Importe libre: un pago parcial de un importe determinado hasta saldar el total.
- Propina opcional en porcentaje o importe fijo.

El cliente puede ver una calculadora previa desde la mesa con el desglose de cada modo; el cálculo lo devuelve la API, no el frontend. Los redondeos se reparten de forma determinista para que la suma de las partes sea exactamente el total.

La sesión no puede cerrarse con saldo pendiente salvo acción autorizada y registrada (por ejemplo, invitación de la casa).

### 7.12 Informes y métricas operativas

Además de los informes de ventas, el producto debe destacar en métricas de servicio, calculadas a partir de las marcas de tiempo del apartado 7.7:

- Tiempo medio de aceptación (pedido creado → aceptado).
- Tiempo medio de preparación por destino (preparing → ready), por producto y por franja horaria.
- Tiempo medio de entrega (ready → delivered).
- Tiempo de respuesta a llamadas al camarero (creada → atendida).
- Duración media de la sesión de mesa y rotación de mesas.
- Rendimiento por camarero (mesas atendidas, tiempos de respuesta, ventas).
- Ventas del día, ticket medio, productos más vendidos, comparativas por periodo.
- Pedidos que superan un umbral de tiempo configurable (alertas en el KDS y en el panel).

Filtros por fecha, franja horaria, zona, destino y empleado. Las consultas pesadas deben optimizarse (índices, agregados precalculados o jobs programados) y nunca bloquear la operación en tiempo real.

---

## 8. Panel administrativo con Filament

Implementar un panel administrativo profesional para gestionar:

- Restaurantes.
- Usuarios.
- Roles y permisos.
- Zonas.
- Mesas.
- Códigos QR.
- Categorías.
- Productos.
- Variantes.
- Complementos.
- Pedidos.
- Clientes.
- Inventario.
- Pagos.
- Configuración.
- Informes.

Incluir:

- CRUD completos.
- Filtros.
- Búsqueda.
- Paginación.
- Acciones masivas autorizadas.
- Validaciones.
- Relaciones.
- Indicadores.
- Widgets.
- Exportaciones controladas.

Crear una sección para gestionar el salón en tiempo real, visualizar el estado de las mesas y consultar sus pedidos y sesiones.

Permitir generar códigos QR y descargarlos en PDF para su impresión.

El panel administrativo debe respetar la identidad visual de Mestaro y las restricciones multi-tenant.

---

## 9. Comunicación en tiempo real

Utilizar Laravel Reverb y Laravel Echo.

Implementar eventos para:

- Nuevos pedidos.
- Cambios de estado.
- Pedidos aceptados.
- Pedidos rechazados.
- Artículos listos.
- Productos agotados.
- Llamadas al camarero.
- Solicitudes de cuenta.
- Cambios de sesión.

Los canales privados deben comprobar la autorización del usuario y su pertenencia al restaurante.

No exponer canales privados mediante nombres predecibles sin autorización.

La interfaz debe gestionar reconexiones, duplicación de eventos y pérdida temporal de conectividad.

Los eventos no deben ser la única fuente de verdad: el estado definitivo debe permanecer en la base de datos.

Contrato de tiempo real (independiente de Laravel)

- Documentar en "docs/realtime.md" cada canal (nombre, tipo, quién puede suscribirse, cómo se autoriza) y cada evento (nombre y payload JSON exacto).
- Nombres de evento estables en PascalCase (OrderPlaced, OrderAccepted, OrderRejected, OrderItemStatusChanged, ProductAvailabilityChanged, WaiterCalled, WaiterCallResolved, BillRequested, PaymentRegistered, SessionStatusChanged). Usar "broadcastAs" para que no dependan del namespace PHP.
- Cada payload incluye: "event_id" (UUID para deduplicar), "occurred_at" (ISO 8601 UTC), "restaurant_id" y "version" del recurso para que el cliente descarte eventos antiguos.
- Emitir los eventos solo después del commit de la transacción ("ShouldDispatchAfterCommit").
- Al reconectar, el cliente vuelve a pedir el estado actual por API en lugar de confiar en los eventos perdidos.

Capa de tiempo real desacoplada en el frontend

El frontend Vue accede al tiempo real solo a través de una interfaz propia (por ejemplo "RealtimeClient" con subscribe/unsubscribe/onReconnect), con un adaptador para Laravel Echo/Reverb. Así la réplica en Java podrá aportar un adaptador STOMP sin modificar las vistas.

---

## 10. API REST

Diseñar una API versionada bajo:

"/api/v1"

Debe contemplar:

- Autenticación.
- Restaurantes.
- Usuarios.
- Mesas.
- Sesiones.
- Menús.
- Categorías.
- Productos.
- Pedidos.
- Artículos de pedido.
- Inventario.
- Llamadas.
- Pagos.
- Informes.

Establecer:

- Convenciones de nombres.
- Respuestas consistentes.
- Códigos HTTP adecuados.
- Validación.
- Paginación.
- Filtros.
- Ordenación.
- Manejo uniforme de errores.
- Versionado.
- Documentación.

Crear y mantener "docs/api.md" con los contratos de los endpoints, parámetros, respuestas, errores, autenticación y permisos.

Diseñar los contratos para permitir una futura implementación de clientes o servicios adicionales, incluso en Java, sin acoplarlos a la estructura interna de Laravel.

Convenciones de contrato obligatorias (las respetará también la réplica en Java):

- Mantener "docs/openapi.yaml" (OpenAPI 3.1) sincronizado con "docs/api.md". Es el contrato ejecutable que usará la réplica Java para sus tests de contrato.
- JSON en snake_case.
- Identificadores públicos: UUID (o ULID) en la API; nunca IDs autoincrementales expuestos.
- Importes como enteros en la unidad mínima de la moneda (céntimos) más el código ISO 4217 de la moneda. Nunca decimales en coma flotante.
- Fechas en ISO 8601 con zona UTC.
- Enums serializados en snake_case minúscula (pending, queued, preparing…).
- Envoltorio de respuesta consistente: "data" para el recurso, "meta" y "links" para la paginación.
- Formato de error único: { "error": { "code": "ORDER_PRODUCT_UNAVAILABLE", "message": "...", "details": { "campo": ["..."] } } }, con un catálogo de códigos de error documentado en docs/api.md.
- Cabecera "Idempotency-Key" obligatoria en la creación de pedidos y de pagos.
- Respuestas sin campos propios de Laravel (nada de "exception", "file", "trace").

Incluir tests de contrato que validen las respuestas reales contra "docs/openapi.yaml".

---

## 11. Seguridad y protección de la plataforma

La seguridad es un requisito transversal y prioritario.

No existe un sistema que pueda garantizarse como imposible de hackear. El objetivo es reducir la superficie de ataque, aplicar defensa en profundidad, limitar el impacto de posibles vulnerabilidades y detectar comportamientos anómalos.

Utilizar como referencias OWASP ASVS, OWASP Top 10 y buenas prácticas de seguridad de Laravel.

### 11.1 Autenticación

- Utilizar mecanismos seguros de autenticación.
- Aplicar hashing de contraseñas mediante algoritmos adecuados, como Argon2id cuando esté soportado.
- No almacenar contraseñas en texto plano.
- Implementar políticas de contraseñas razonables.
- Proteger los procesos de inicio y recuperación de sesión.
- Incorporar verificación de correo cuando corresponda.
- Preparar autenticación multifactor para cuentas administrativas.
- Revocar sesiones y tokens cuando sea necesario.
- Invalidar sesiones después de cambios críticos de credenciales.

### 11.2 Autorización

- Aplicar Policies y Gates en el backend.
- Comprobar permisos en cada operación protegida.
- Verificar la pertenencia al tenant de cada recurso.
- Prevenir IDOR y BOLA.
- Evitar confiar en identificadores, roles o permisos enviados por el cliente.
- Aplicar principio de mínimo privilegio.
- Separar permisos administrativos globales de permisos de restaurante.

### 11.3 Protección de API

- Utilizar autenticación mediante Sanctum según el tipo de cliente.
- Aplicar rate limiting por usuario, IP y operación.
- Proteger endpoints sensibles frente a fuerza bruta.
- Validar y normalizar entradas.
- Utilizar Form Requests.
- Aplicar límites de tamaño a peticiones.
- Evitar asignación masiva insegura.
- No exponer información interna en las respuestas.
- Implementar paginación y límites máximos.
- Proteger los endpoints públicos de QR contra abuso.

### 11.4 Protección contra vulnerabilidades comunes

Prevenir y probar específicamente:

- SQL Injection.
- Cross-Site Scripting (XSS).
- Cross-Site Request Forgery (CSRF).
- Server-Side Request Forgery (SSRF).
- Broken Access Control.
- Insecure Direct Object References (IDOR).
- Broken Object Level Authorization (BOLA).
- Mass Assignment.
- Path Traversal.
- Open Redirect.
- Clickjacking.
- Session Fixation.
- Exposición de información sensible.
- Ataques de fuerza bruta.
- Abuso de endpoints públicos.

Utilizar consultas parametrizadas y el ORM de forma segura.

Escapar contenido en las vistas y evitar renderizar HTML no confiable.

No desactivar protecciones del framework para resolver problemas de implementación.

### 11.5 Protección multi-tenant

Considerar el aislamiento entre restaurantes como una de las áreas de mayor riesgo.

- Aplicar scopes y controles de acceso en backend.
- Verificar relaciones entre recursos.
- Evitar consultas sin contexto de tenant.
- Aislar exportaciones y archivos.
- Aislar trabajos en colas.
- Aislar eventos en tiempo real.
- Comprobar permisos en operaciones masivas.
- Probar intentos de acceso cruzado.

No permitir que cambiar un ID en una URL, petición o payload dé acceso a información de otro restaurante.

### 11.6 Sesiones, QR y códigos temporales

- Generar tokens mediante generadores criptográficamente seguros.
- Evitar identificadores secuenciales para credenciales públicas.
- Establecer expiración.
- Limitar intentos.
- Revocar credenciales cuando corresponda.
- Evitar reutilización indebida.
- No incluir secretos administrativos en códigos QR.
- Separar la identificación de una mesa de la autorización para realizar operaciones sensibles.

### 11.7 Seguridad de pagos

- No almacenar información sensible de tarjetas.
- Utilizar proveedores de pago externos.
- Verificar firmas de webhooks.
- Validar importes y estados en el servidor.
- Aplicar idempotencia.
- Evitar procesar dos veces una misma notificación.
- Registrar operaciones relevantes.
- Proteger las credenciales de integración.

### 11.8 Seguridad de infraestructura

- Mantener secretos fuera del repositorio.
- Utilizar archivos ".env" únicamente en entornos locales o mecanismos seguros de gestión de secretos.
- No incluir credenciales en imágenes Docker.
- Ejecutar procesos con usuarios sin privilegios innecesarios.
- Restringir servicios internos.
- Configurar HTTPS obligatorio en producción.
- Aplicar cabeceras de seguridad HTTP.
- Configurar cookies Secure, HttpOnly y SameSite según corresponda.
- Deshabilitar el modo debug en producción.
- Restringir CORS a los orígenes autorizados.
- Configurar permisos mínimos de archivos.
- Limitar conexiones y accesos a la base de datos.
- Mantener Redis protegido y no exponerlo públicamente.
- Separar los entornos de desarrollo, pruebas y producción.

### 11.9 Protección de archivos

- Validar MIME real y extensión.
- Limitar tamaño.
- Generar nombres de archivo internos.
- Evitar ejecución de archivos subidos.
- Almacenar archivos privados fuera del acceso público directo.
- Comprobar permisos al descargar.
- Aplicar protección adicional a documentos y exportaciones sensibles.

### 11.10 Auditoría y monitorización

Registrar eventos relevantes:

- Inicio y cierre de sesión.
- Fallos de autenticación.
- Cambios de permisos.
- Acciones administrativas.
- Modificaciones de precios.
- Cancelaciones.
- Cambios de inventario.
- Operaciones de pago.
- Intentos de acceso no autorizado.

Los registros deben evitar contraseñas, tokens y datos sensibles.

Implementar alertas y mecanismos de detección para comportamientos anómalos.

Definir una política de retención de logs y protección frente a modificaciones indebidas.

### 11.11 Seguridad en desarrollo y mantenimiento

- Ejecutar auditorías de dependencias.
- Mantener Laravel, PHP y librerías actualizadas.
- Incorporar análisis estático.
- Incorporar análisis de vulnerabilidades.
- Ejecutar pruebas de seguridad automatizadas.
- Revisar permisos y rutas.
- Analizar configuraciones de Docker.
- No introducir secretos en commits.
- Documentar vulnerabilidades conocidas y su resolución.

No considerar una funcionalidad terminada si carece de controles de autorización y validación adecuados.

---

## 12. Base de datos y entidades principales

Diseñar un modelo relacional consistente, normalizado y preparado para crecer.

Entidades iniciales:

- restaurants
- users
- restaurant_user
- roles
- permissions
- tables
- table_sessions
- table_session_participants
- categories
- products
- product_variants
- product_addons
- orders
- order_items
- order_item_addons
- order_status_history
- zones
- restaurant_settings
- idempotency_keys
- waiter_calls
- payments
- payment_transactions
- inventory_items
- inventory_movements
- audit_logs

Definir relaciones, claves foráneas, índices y restricciones.

Utilizar tipos adecuados para importes monetarios y evitar errores de precisión mediante números de punto flotante.

Aplicar transacciones en operaciones críticas.

Diseñar restricciones de integridad para evitar estados y relaciones inconsistentes.

Incluir timestamps y campos de auditoría cuando corresponda.

No crear tablas o campos innecesarios sin justificar su propósito.

---

## 13. Reglas de negocio importantes

- Los precios siempre se calculan en el backend.
- Los impuestos se calculan de acuerdo con la configuración del restaurante.
- Los descuentos deben estar autorizados.
- Los pedidos deben conservar el precio aplicado en el momento de su creación.
- Los productos agotados no pueden añadirse a nuevos pedidos.
- Los cambios de disponibilidad deben propagarse en tiempo real.
- Los pedidos deben tener un historial de estados.
- Las operaciones de pago deben ser idempotentes.
- Las sesiones no deben cerrarse mientras existan operaciones pendientes que impidan su cierre.
- Las operaciones concurrentes deben protegerse contra duplicaciones y condiciones de carrera.
- Las cancelaciones y modificaciones deben respetar los permisos.
- Las operaciones críticas deben ejecutarse mediante transacciones.

---

## 14. Testing y calidad

Utilizar Pest para construir una batería de pruebas automatizadas.

Pruebas unitarias

Cubrir:

- Cálculos de importes.
- Impuestos.
- Descuentos.
- Transiciones de estado.
- Reglas de disponibilidad.
- Validaciones de negocio.
- Permisos.

Pruebas de integración

Cubrir:

- Persistencia.
- Relaciones.
- Transacciones.
- Eventos.
- Colas.
- Integraciones externas.
- Procesamiento de pagos.

Pruebas funcionales

Cubrir los flujos completos:

1. Creación de restaurante.
2. Creación de usuarios y asignación de permisos.
3. Creación de zonas y mesas.
4. Generación de QR.
5. Acceso de cliente.
6. Apertura de sesión.
7. Incorporación de varios participantes.
8. Creación de pedido.
9. Aceptación.
10. Distribución a cocina y barra.
11. Preparación.
12. Entrega.
13. Solicitud de cuenta.
14. División de pagos.
15. Cierre de sesión.

Pruebas de seguridad

Cubrir específicamente:

- Acceso no autorizado.
- Escalada de privilegios.
- Acceso cruzado entre tenants.
- IDOR y BOLA.
- Fuerza bruta y rate limiting.
- Validación de entradas.
- Acceso a canales privados.
- Manipulación de precios.
- Duplicación de pagos.
- Reutilización de tokens.
- Acceso a archivos privados.

No limitar las pruebas a respuestas HTTP. Comprobar también que no se producen cambios indebidos en la base de datos.

---

## 15. Datos de demostración

Crear seeders para un entorno de demostración.

Incluir:

- 1 restaurante.
- 10 mesas.
- 4 usuarios con diferentes roles.
- 6 categorías.
- Aproximadamente 30 productos.
- Variantes y complementos de ejemplo.
- Pedidos de demostración.
- Datos de inventario.

Las credenciales de demostración deben estar documentadas exclusivamente para desarrollo local.

No utilizar esas credenciales en producción.

---

## 16. Diseño UX/UI

La experiencia debe ser profesional, intuitiva y coherente.

Diseñar interfaces específicas para:

- Panel administrativo.
- POS.
- Gestión de mesas.
- Menú público.
- Pedidos QR.
- KDS de cocina.
- KDS de barra.
- Vista de camareros.
- Gestión de inventario.
- Informes.

Requisitos UX

- Diseño responsive.
- Prioridad mobile-first para clientes.
- Interfaces táctiles para personal.
- Navegación clara.
- Jerarquía visual consistente.
- Formularios accesibles.
- Estados de carga.
- Confirmaciones para operaciones críticas.
- Mensajes de error comprensibles.
- Feedback inmediato.
- Accesibilidad WCAG 2.2 AA como objetivo.
- Rendimiento adecuado en dispositivos modestos.

Evitar interfaces sobrecargadas, animaciones innecesarias y elementos decorativos que dificulten la operación.

---

## 17. Impresión

Preparar una arquitectura opcional para impresión de pedidos y tickets.

Contemplar compatibilidad futura con impresoras térmicas ESC/POS.

Permitir configurar destinos de impresión según el tipo de producto:

- Cocina.
- Barra.
- Caja.

La impresión debe poder funcionar sin bloquear el flujo principal de pedidos.

No acoplar la lógica de negocio directamente a un fabricante o modelo de impresora.

---

## 18. Documentación y entregables

Entregar:

1. Código fuente organizado.
2. Arquitectura documentada.
3. Migraciones.
4. Modelos y relaciones.
5. Seeders.
6. Controladores.
7. Actions y Services.
8. Policies.
9. Form Requests.
10. Eventos y listeners.
11. Jobs y colas.
12. API REST.
13. Panel Filament.
14. Frontend Vue.
15. Pruebas automatizadas.
16. Configuración Docker.
17. Archivo Docker Compose.
18. Archivo README.
19. Documentación de API en "docs/api.md".
20. Documentación de instalación.
21. Documentación de configuración.
22. Documentación de despliegue.
23. Documentación de seguridad.
24. Documentación del sistema de diseño.
25. Recursos de marca y logotipo.

El README debe explicar cómo levantar el entorno desde cero, ejecutar migraciones, cargar datos de demostración, iniciar colas y ejecutar pruebas.

---

## 19. Metodología de implementación

No desarrollar todo de manera desordenada ni generar grandes cantidades de código sin verificar su funcionamiento.

Trabajar por fases:

Fase 1 — Análisis y arquitectura

- Revisar requisitos.
- Definir módulos.
- Diseñar arquitectura.
- Diseñar modelo de datos.
- Definir estrategia multi-tenant.
- Identificar riesgos de seguridad.
- Crear estructura del proyecto.
- Entregables: docs/architecture.md, diagrama del modelo de datos, borrador inicial de docs/api.md, docs/realtime.md y docs/openapi.yaml, docs/decisions.md y docs/progress.md.
- Esta fase no escribe lógica de negocio: termina con la revisión y aprobación del desarrollador.

Fase 2 — Infraestructura y núcleo

- Configurar Laravel.
- Configurar Docker.
- Configurar base de datos.
- Configurar Redis.
- Implementar autenticación.
- Implementar autorización.
- Implementar aislamiento multi-tenant.
- Implementar auditoría inicial.
- Implementar los tokens del sistema de diseño (Tailwind y tema de Filament) con modo claro/oscuro y el test de contraste.
- Configurar CI: Pest, Larastan, Pint y auditoría de dependencias (composer audit, npm audit).

Fase 3 — Gestión administrativa

- Restaurantes.
- Usuarios.
- Roles.
- Mesas.
- Zonas.
- Categorías.
- Productos.

Fase 4 — Pedidos y sesiones

- QR.
- Sesiones.
- Participantes.
- Carrito.
- Pedidos.
- Estados.
- Validaciones.

Fase 5 — Operación en tiempo real

- Reverb.
- Echo.
- Cocina.
- Barra.
- Camareros.
- Notificaciones.

Fase 6 — Inventario y pagos

- Stock.
- Movimientos.
- Cuentas.
- División de pagos.
- Integración con proveedores de pago.

Fase 7 — Informes y funcionalidades adicionales

- Dashboard.
- Métricas.
- Exportaciones.
- Impresión.

Fase 8 — Seguridad y estabilización

- Pruebas completas.
- Auditorías.
- Corrección de vulnerabilidades.
- Optimización.
- Documentación.
- Preparación para producción.

Cada fase debe finalizar con pruebas satisfactorias antes de avanzar a la siguiente.

La seguridad es transversal: cada fase incluye los controles de autorización, aislamiento multi-tenant y sus tests de seguridad correspondientes (incluidos los intentos de acceso cruzado entre restaurantes). La Fase 8 consolida y audita, pero no es el momento de añadir la seguridad que faltó.

Criterio de cierre de cada fase:

- Tests en verde (unitarios, integración, funcionales y de seguridad de la fase).
- Larastan y Pint sin errores.
- Contratos (api.md, realtime.md, openapi.yaml) actualizados y tests de contrato en verde.
- docs/progress.md y docs/decisions.md actualizados.
- Resumen para el desarrollador: qué se hizo, cómo probarlo manualmente, decisiones tomadas y pendientes.

---

## 20. Criterios generales de aceptación

El proyecto se considerará funcionalmente preparado para su primera versión comercial cuando:

- Un restaurante pueda registrarse y gestionar su establecimiento.
- Los datos de cada restaurante estén aislados.
- Los usuarios solo puedan ejecutar operaciones autorizadas.
- Se puedan crear mesas y generar QR.
- Los clientes puedan realizar pedidos.
- Varios clientes puedan compartir una sesión.
- Los pedidos lleguen a sus destinos correspondientes.
- Cocina y barra puedan gestionar sus pedidos.
- El personal pueda atender solicitudes.
- Se puedan gestionar cuentas y pagos mediante integraciones seguras.
- Los cambios relevantes se reflejen en tiempo real.
- El sistema disponga de panel administrativo.
- Existan pruebas automatizadas de los flujos críticos.
- Se hayan implementado y probado los controles de seguridad.
- La documentación permita instalar y ejecutar el proyecto desde cero.

Instrucciones finales para el agente de desarrollo

Actúa como arquitecto de software senior y desarrollador full-stack especializado en Laravel, Vue, sistemas POS y plataformas SaaS multi-tenant.

Prioriza la seguridad, la integridad de los datos, la mantenibilidad y la experiencia de usuario.

No inventes funcionalidades que no estén justificadas por los requisitos.

No omitas controles de autorización por simplificar el desarrollo.

No implementes lógica crítica exclusivamente en frontend.

No utilices datos ficticios para simular funcionalidades que todavía no están implementadas.

No marques una fase como terminada sin verificarla mediante pruebas.

Cuando exista una decisión técnica importante, explica brevemente las alternativas, recomienda una solución y justifica la elección.

Si detectas incompatibilidades, riesgos de seguridad o problemas arquitectónicos, comunícalos antes de continuar.

Trabaja solo en la fase que se te indique y detente al terminarla (apartado 0).

Recuerda que este backend es la implementación de referencia: cualquier cambio de contrato afecta a la réplica en Java, así que documéntalo siempre.

Construye Mestaro como un producto SaaS comercial real, no como una demostración o un simple prototipo.
