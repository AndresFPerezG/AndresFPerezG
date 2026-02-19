# Guía amigable: mostrar reportes públicos de Power BI en Drupal

## Resumen rápido (en lenguaje simple)

Esta solución permite que **cualquier persona** vea reportes de Power BI en una página Drupal pública, pero sin usar enlaces públicos inseguros.

La idea clave es:

- El visitante ve el reporte.
- Drupal pide un permiso temporal en segundo plano.
- Ese permiso dura poco tiempo.
- Solo se muestran reportes previamente autorizados.

---

## Arquitectura visual

![Arquitectura Drupal Power BI Public Embed](./arquitectura_powerbi_public_embed.svg)

---

## ¿Qué problema resuelve?

En un portal gubernamental abierto al público:

- no puedes pedir login a todos los visitantes,
- pero tampoco debes exponer reportes con URL pública abierta.

Por eso se implementó un modelo intermedio:

1. Portal abierto para toda la ciudadanía.
2. Seguridad mínima obligatoria en backend.
3. Tokens cortos para reducir riesgo.

---

## ¿Qué se construyó en este repositorio?

Se creó un módulo en Drupal llamado:

`web/modules/custom/powerbi_public_embed`

En palabras sencillas, este módulo hace tres cosas:

1. **Entrega configuración segura al navegador** para abrir el reporte.
2. **Controla qué reportes están permitidos** (por slug).
3. **Aplica límites de uso** para evitar abuso del endpoint.

También incluye un **modo demo** (`mock_mode`) para probar el flujo sin credenciales reales.

---

## ¿Cómo funciona? (paso a paso, sin tecnicismos)

Piensa en esto como una taquilla:

1. El visitante entra a la página y pide ver un reporte.
2. Drupal revisa si ese reporte está en la lista permitida.
3. Si está permitido, Drupal solicita un "pase temporal".
4. Drupal devuelve ese pase al navegador.
5. El navegador usa el pase para mostrar el reporte.

Ese pase se vence rápido, así que no queda abierto de forma permanente.

---

## ¿Esta implementación usa Web Component?

**Respuesta corta: no, en esta versión no se usa Web Component.**

Lo que se implementó fue:

- un **bloque de Drupal** (`PowerBiPublicEmbedBlock`)
- más un archivo JavaScript (`powerbi-public-embed.js`)
- que usa la librería de Power BI para embeber el reporte.

### ¿Por qué no se usó Web Component aquí?

Porque para un MVP rápido era más directo integrarlo con el sistema nativo de bloques de Drupal.
Esto reduce tiempos y evita complejidad adicional.

### ¿Se puede migrar después a Web Component?

Sí, totalmente.  
Si su equipo decide usar Angular Elements o Web Components, se puede encapsular la capa visual sin cambiar la lógica de seguridad del backend.

---

## Cómo probarlo hoy con datos de ejemplo

### 1) Activar módulo

```bash
drush en powerbi_public_embed -y
drush cr
```

### 2) Colocar el bloque en una página

1. Ir a **Estructura > Bloques**.
2. Agregar bloque **Power BI public embed**.
3. Usar un slug de ejemplo, por ejemplo: `economia-nacional`.

### 3) Confirmar que responde el endpoint demo

```bash
curl -s "https://TU-DOMINIO/api/powerbi/embed-config/economia-nacional"
```

Si todo está bien, verás un JSON con datos de ejemplo y `isMock: true`.

---

## ¿Cómo pasar de demo a producción?

En Drupal, abrir:

`/admin/config/services/powerbi-public-embed`

y hacer estos cambios:

1. Cargar credenciales reales (`tenant_id`, `client_id`, `client_secret`).
2. Cambiar `mock_mode` a `false`.
3. Configurar reportes reales en la lista permitida.
4. Mantener activo el rate limit.
5. Limpiar caché con `drush cr`.

---

## Medidas de seguridad recomendadas (explicadas simple)

- **No usar "Publish to web"** (enlace público abierto).
- Guardar secretos fuera del repositorio.
- Usar WAF/CDN para proteger el portal público.
- Limitar cuántas veces se puede pedir token por minuto.
- Permitir solo reportes autorizados (allowlist).
- Desactivar exportaciones si no son necesarias.
- Revisar logs para detectar abuso.

---

## ¿Qué queda pendiente en una fase 2?

- Pasar librerías frontend de CDN a paquete interno.
- Mejorar monitoreo y alertas.
- Añadir políticas más finas por tipo de reporte.
- Si el negocio lo pide, mover el frontend a Web Component.

---

## Glosario breve (para no técnicos)

- **Token temporal:** permiso que dura pocos minutos.
- **Allowlist:** lista de reportes permitidos.
- **Rate limit:** límite de solicitudes para evitar abuso.
- **MVP:** primera versión funcional, rápida de implementar.
