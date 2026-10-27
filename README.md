# INACIME Tecámac — landings

Páginas puente entre los anuncios de Meta y WhatsApp para el campus Tecámac. Cada landing pide nombre y WhatsApp (y la carrera, en la combinada), guarda el lead y abre WhatsApp con un mensaje que ya trae el folio.

| Ruta | Programa |
| --- | --- |
| `/enfermeria-tecamac` | Licenciatura en Enfermería |
| `/cirujano-dentista-tecamac` | Licenciatura Cirujano Dentista |
| `/licenciaturas-tecamac` | Las dos; la persona elige carrera |
| `/aviso-de-privacidad` | Aviso con el marcador `[TEXTO LEGAL DE INACIME]` |

El texto, los chips, el WhatsApp y el prefijo del folio viven en `app/landings.json`. La plantilla es una sola: `app/template.php`.

## Correr en local

Hace falta Docker. El sitio escucha en el puerto **8091**.

```bash
cp .env.example .env
```

En local, `ALLOWED_ORIGINS` tiene que incluir el origen del navegador. Si no, el formulario responde 400.

```bash
# .env
ALLOWED_ORIGINS=http://127.0.0.1:8091,https://informes.inacime.com
APP_ENV=local
```

```bash
docker compose up --build
```

Abre [http://127.0.0.1:8091/enfermeria-tecamac](http://127.0.0.1:8091/enfermeria-tecamac).

Con Docker los leads quedan en el volumen nombrado `inacime-storage`, montado en `/var/www/storage` dentro del contenedor. Ese volumen tiene que ser persistente: si el contenedor se recrea sin él, se pierde la base. Con `php -S` la base es el archivo `storage/leads.sqlite` del repositorio. Los logs de texto van junto a la base, en `logs/`, y no incluyen nombre ni teléfono.

Sin Docker, con PHP 8.2+ y las extensiones `pdo_sqlite`, `mbstring` y `curl`:

```bash
php -S 127.0.0.1:8091 -t public public/router.php
```

## Agregar una landing

Solo se edita `app/landings.json`. La llave es el slug y la ruta (`/mi-slug`). Copia una entrada que ya exista y cambia:

- `programa`, `plantel`, `titulo`, `subtitulo`, `burbujas`, `chips`, `direccion`
- `whatsapp` (dígitos, con lada 52, sin `+`)
- `folio_prefix` (el folio queda `{prefijo}-` y cuatro dígitos, por ejemplo `ENF-TEC-0042`)
- `mensaje`, con `{folio}` y, si hace falta, `{programa}`

Si la landing ofrece varias carreras, pon `"selector": true`, `opciones` con el texto de cada botón y `programas` con el nombre completo que va al CRM y al mensaje de WhatsApp. En la combinada el botón dice «Enfermería» y el mensaje usa «Licenciatura en Enfermería», que es el texto del diseño.

`promo: true` muestra la franja «0% de inscripción en noviembre y diciembre». Viene apagada.

Hay tres marcadores por sustituir cuando existan: el RVOE en `chips` (`RVOE SEP [NÚMERO]`), la dirección (`[DIRECCIÓN CONFIRMADA]`) y el párrafo `[TEXTO LEGAL DE INACIME]` dentro de `render_privacy()` en `app/template.php`.

No hace falta tocar PHP, CSS ni JavaScript. Después del cambio, vuelve a desplegar.

## Probar la Conversions API

1. En el Administrador de eventos de Meta, abre el pixel `1834881177516177` → **Probar eventos** y copia el código (`TEST…`).
2. Ponlo en `META_TEST_EVENT_CODE` y el token en `META_CAPI_TOKEN`. `META_API_VERSION` queda en `v25.0`.
3. Envía una landing de prueba. El navegador dispara `PageView` al cargar y `Lead` al recibir la respuesta, con el mismo `event_id` que el servidor manda a `graph.facebook.com`. Así el evento se deduplica.
4. El lead debe aparecer en Probar eventos en menos de un minuto, con `content_name` = programa y `content_category` = plantel.
5. Borra `META_TEST_EVENT_CODE` antes de salir a producción. Si se queda puesto, los eventos reales siguen marcados como prueba.

Si el token falta o Meta no responde en 3 segundos, el lead se guarda igual y `capi_status` queda en `pending`.

## Cron de reintentos

`app/retry.php` vuelve a enviar los leads con `crm_status` o `capi_status` en `pending`. No lo expongas por HTTP: si se llama desde la web responde 404.

Dentro del contenedor, cada cinco minutos:

```bash
*/5 * * * * php /var/www/html/app/retry.php >> /var/www/storage/logs/retry.log 2>&1
```

Desde el servidor, contra el servicio de Compose:

```bash
*/5 * * * * docker compose -f /ruta/al/proyecto/docker-compose.yml exec -T web php /var/www/html/app/retry.php
```

En EasyPanel, crea un cron del servicio con el comando `php /var/www/html/app/retry.php`. Un candado evita que dos corridas se pisen. El script no escribe nombre ni teléfono en el log.

## URL del anuncio

```
https://informes.inacime.com/{slug}?utm_source=meta&utm_campaign={{campaign.name}}&utm_content={{ad.id}}
```

`utm_content` es el id del anuncio (`ad_id` en la base). Meta agrega `fbclid` solo; la página crea la cookie `_fbc` (`fb.1.{timestamp}.{fbclid}`, 90 días, dominio raíz) si todavía no existe. Los UTM se guardan en `sessionStorage` para no perderlos si la persona recarga.

Ejemplo de Enfermería:

```
https://informes.inacime.com/enfermeria-tecamac?utm_source=meta&utm_campaign={{campaign.name}}&utm_content={{ad.id}}
```

## Variables de entorno

Definidas en `.env.example`. En EasyPanel se cargan en el servicio; no van en la imagen.

| Variable | Uso |
| --- | --- |
| `META_PIXEL_ID` | Pixel del navegador. Vacío = no se carga el pixel |
| `META_CAPI_TOKEN` | Token de la Conversions API |
| `META_API_VERSION` | Versión de Graph. Default `v25.0` |
| `META_TEST_EVENT_CODE` | Código de Probar eventos. Vacío en producción |
| `CRM_WEBHOOK_URL` | POST del lead. Vacío = queda `pending` |
| `CRM_WEBHOOK_TOKEN` | `Authorization: Bearer …` |
| `ALLOWED_ORIGINS` | Orígenes permitidos, separados por coma |
| `APP_ENV` | `production` o `local` |

El origen de producción es `https://informes.inacime.com`. La IP del visitante sale de `CF-Connecting-IP` solo cuando `REMOTE_ADDR` cae en los rangos de `app/config/cloudflare_ips.php` (los publicados en cloudflare.com/ips-v4 e ips-v6). Si el origen no es Cloudflare, se usa `REMOTE_ADDR` y el encabezado se ignora. Esa IP es la del límite y la de `client_ip_address` en la Conversions API.

El límite es de 30 envíos por IP cada 10 minutos y 3 por teléfono normalizado (`52` + 10 dígitos) cada hora.

## Qué hace el envío

1. `POST /api/lead.php` solo acepta JSON. Revisa `Origin` o `Referer`, el límite por IP, el honeypot `empresa` y, ya con el teléfono normalizado, el límite por teléfono.
2. Normaliza el WhatsApp a `52` + 10 dígitos, recorta el nombre a 60 caracteres y comprueba slug y carrera.
3. Genera un folio único y guarda el lead en SQLite antes de hablar con servicios externos.
4. Manda el lead al CRM (3 s). Si falla, `crm_status = pending`.
5. Manda el evento `Lead` a la Conversions API (3 s), con el `event_id` del navegador. Si falla, `capi_status = pending`.
6. Responde `{ "ok": true, "folio", "whatsapp_url" }`. El texto sale de `mensaje`, con el folio ya sustituido y `rawurlencode`.

Si la API no responde en 4 segundos, el navegador abre WhatsApp con el mismo mensaje **sin folio** y deja el error en la consola. WhatsApp no se abre al cargar la página.

## Pruebas manuales

Levanta el sitio con `ALLOWED_ORIGINS=http://127.0.0.1:8091`. Para simular el CRM caído:

```bash
# .env  (no se commitea; el token real solo vive aquí)
CRM_WEBHOOK_URL=http://127.0.0.1:9/crm
CRM_WEBHOOK_TOKEN=
```

El puerto 9 rechaza la conexión. Entre escenarios borra `storage/leads.sqlite` (y los `-wal` / `-shm`) para no arrastrar los límites: 30 envíos por IP cada 10 minutos y 3 por teléfono cada hora. Con Docker el archivo está dentro del volumen `inacime-storage`, no en el repositorio.

Sustituye el origen si no usas el 8091. Todas las llamadas llevan `Origin` y `Content-Type: application/json`.

### Envío válido

```bash
curl -sS -D - http://127.0.0.1:8091/api/lead.php \
  -H 'Origin: http://127.0.0.1:8091' \
  -H 'Content-Type: application/json' \
  -d '{"slug":"enfermeria-tecamac","nombre":"Ana López","telefono":"5512345678","programa":"Licenciatura en Enfermería","utm_source":"meta","utm_campaign":"tec-enf","utm_content":"1200000001","fbc":"fb.1.1710000000000.IwAR_test","fbp":"fb.1.1710000000000.1234567890","event_id":"11111111-1111-4111-8111-111111111111","page_url":"http://127.0.0.1:8091/enfermeria-tecamac?utm_source=meta","honeypot":""}'
```

Esperado: `200` y JSON `ok: true`, un folio `ENF-TEC-` más cuatro dígitos, y `whatsapp_url` hacia `https://wa.me/525640031661` con el folio dentro del texto. En SQLite, `telefono` es `525512345678`, `ad_id` es `1200000001`, `crm_status` y `capi_status` son `pending` (CRM cerrado y sin token de Meta). `storage/logs/app.log` no contiene `Ana` ni `5512345678`.

### Teléfono inválido

```bash
curl -sS -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8091/api/lead.php \
  -H 'Origin: http://127.0.0.1:8091' \
  -H 'Content-Type: application/json' \
  -d '{"slug":"enfermeria-tecamac","nombre":"Ana López","telefono":"551234","programa":"Licenciatura en Enfermería","event_id":"22222222-2222-4222-8222-222222222222","honeypot":""}'
```

Esperado: `400` y el mensaje de WhatsApp a 10 dígitos. No se inserta el lead.

### Honeypot

```bash
curl -sS -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8091/api/lead.php \
  -H 'Origin: http://127.0.0.1:8091' \
  -H 'Content-Type: application/json' \
  -d '{"slug":"enfermeria-tecamac","nombre":"Ana López","telefono":"5512345678","programa":"Licenciatura en Enfermería","event_id":"33333333-3333-4333-8333-333333333333","honeypot":"http://spam.test"}'
```

Esperado: `400` con «Solicitud inválida.» y sin fila nueva.

### Límite por teléfono

Con la base limpia, manda tres envíos válidos con el mismo WhatsApp y un `event_id` distinto en cada uno. El cuarto:

```bash
curl -sS -D - http://127.0.0.1:8091/api/lead.php \
  -H 'Origin: http://127.0.0.1:8091' \
  -H 'Content-Type: application/json' \
  -d '{"slug":"enfermeria-tecamac","nombre":"Ana López","telefono":"5512345678","programa":"Licenciatura en Enfermería","event_id":"44444444-4444-4444-8444-444444444444","honeypot":""}'
```

Esperado: `429`, `Retry-After: 3600` y «Ya recibimos tu solicitud; te escribimos en breve.» No se inserta un cuarto lead. En el navegador, ese fallo igual abre WhatsApp, sin folio.

### Límite por IP

Borra la base y manda 30 envíos válidos desde la misma IP, cada uno con un teléfono distinto (cambia también `event_id`). El 31:

Esperado: `429`, `Retry-After: 600` y «Demasiados envíos…». Un `CF-Connecting-IP` enviado desde una IP que no es de Cloudflare no cambia la IP con la que se cuenta el límite.

### CRM caído

Con `CRM_WEBHOOK_URL` apuntando a un puerto cerrado, repite el envío válido (otra base limpia, otro `event_id`).

Esperado: la API responde `200` en menos de 4 segundos, con folio y `whatsapp_url`. El navegador abre ese WhatsApp. En SQLite, `crm_status` sigue en `pending`. `php app/retry.php` lo intenta de nuevo y lo deja en `pending` si el CRM sigue caído.

La combinada rechaza el POST si `programa` no es «Cirujano Dentista», «Enfermería» o el nombre completo de `programas`.

## Despliegue en EasyPanel

EasyPanel no usa `docker-compose` para un servicio de tipo App: construye el `Dockerfile` y arranca el contenedor. La imagen es `php:8.2-apache`, escucha en el puerto **80** y crea `/var/www/storage` con dueño `www-data`. El `.env` no entra en la imagen; los secretos se cargan en el panel.

| Dato | Valor |
| --- | --- |
| Tipo de servicio | App |
| Origen | GitHub, repositorio `inacime-landings` |
| Rama | `main` |
| Build | Dockerfile |
| Puerto | `80` |
| Volumen persistente | `/var/www/storage` |
| Dominio | `informes.inacime.com` |

Sin ese volumen, un redespliegue borra `leads.sqlite` y los leads que siguen en `pending`.

Variables de entorno, las mismas de `.env.example`:

| Variable | Valor en producción |
| --- | --- |
| `META_PIXEL_ID` | `1834881177516177` |
| `META_CAPI_TOKEN` | Token de la Conversions API. No lo pongas en el repositorio |
| `META_API_VERSION` | `v25.0` |
| `META_TEST_EVENT_CODE` | Vacío en producción. Solo para Probar eventos |
| `CRM_WEBHOOK_URL` | URL del webhook del CRM |
| `CRM_WEBHOOK_TOKEN` | Bearer del webhook |
| `ALLOWED_ORIGINS` | `https://informes.inacime.com` |
| `APP_ENV` | `production` |

El dominio va detrás de Cloudflare. `ALLOWED_ORIGINS` tiene que ser exactamente el origen público, con `https`.

Cron del servicio, cada 5 minutos:

```bash
php /var/www/html/app/retry.php
```

Reintenta los leads con `crm_status` o `capi_status` en `pending`. El script no escribe nombre ni teléfono en el log.

Las landings responden `Cache-Control: public, max-age=300`. `/api/*` responde `Cache-Control: no-store`. Cada página también envía `Content-Security-Policy` (scripts y conexiones del pixel solo a `connect.facebook.net` y `facebook.com`), `X-Content-Type-Options: nosniff` y `Referrer-Policy: strict-origin-when-cross-origin`.
