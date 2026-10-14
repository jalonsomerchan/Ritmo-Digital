# Ritmo Digital

PWA en Angular para explorar el catálogo de iBroadcast guardado en MySQL y gestionar la cola compartida con Alexa. Las búsquedas usan la copia local; el backend sincroniza el catálogo con iBroadcast mediante una tarea protegida del servidor.

## Puesta en marcha

1. Instala Node.js 22 o posterior y ejecuta `npm install`.
2. En Firebase Console, activa **Authentication → Google** y registra una aplicación web.
3. Rellena las variables `VITE_FIREBASE_*` y `VITE_API_BASE` de `.env.local` y `.env.production`.
4. Inicia la app con `npm start` y accede desde `http://localhost:4200`. Este comando carga `.env.local`.

La API valida el Firebase ID token. El acceso se realiza con Google a través de Firebase Authentication. En escritorio se abre la ventana de Google; en móviles se usa redirección. Autoriza los dominios local y de producción en Firebase Authentication. El service worker se activa en la compilación de producción.

Si alojas el frontend en otro dominio, el servidor de la API debe permitir CORS para ese origen. `npm run build` carga `.env.production`; los valores se generan en archivos locales ignorados por Git.

## Despliegue en GitHub Pages

El workflow [`.github/workflows/deploy-pages.yml`](.github/workflows/deploy-pages.yml) publica automáticamente en GitHub Pages al actualizar `main` y también se puede ejecutar manualmente desde **Actions**. Genera la compilación de producción en la raíz del sitio, adecuada para el dominio `ritmodigital.alon.one`.

Antes del primer despliegue:

1. En **Settings → Secrets and variables → Actions → Variables** del repositorio, define `VITE_API_BASE`, `VITE_FIREBASE_API_KEY`, `VITE_FIREBASE_AUTH_DOMAIN`, `VITE_FIREBASE_PROJECT_ID` y `VITE_FIREBASE_APP_ID` con los valores de `.env.production`. Añade las variables opcionales de Firebase si las usas: `VITE_FIREBASE_STORAGE_BUCKET`, `VITE_FIREBASE_MESSAGING_SENDER_ID` y `VITE_FIREBASE_MEASUREMENT_ID`. Esta configuración forma parte del código del cliente al compilarse.
2. En **Settings → Pages**, selecciona **GitHub Actions** como fuente y establece `ritmodigital.alon.one` como dominio personalizado.
3. En el proveedor DNS, crea un registro `CNAME` para `ritmodigital` que apunte a `jalonsomerchan.github.io`.
4. Añade `ritmodigital.alon.one` a los dominios autorizados de Firebase Authentication y permite ese origen en la configuración CORS de la API.

El archivo `public/CNAME` queda incluido en la salida como referencia del dominio. GitHub Pages requiere configurar el dominio personalizado también en **Settings → Pages** cuando se publica con un workflow.

## Funciones

- Inicio de sesión con Firebase Authentication.
- Emparejamiento de la cuenta Firebase con Alexa mediante código temporal.
- Búsqueda de canciones, artistas, álbumes, playlists y géneros.
- Añadir canciones, quitar pendientes, vaciar la parte editable y reordenarla con controles o arrastrando.
- La app marca como protegidas las canciones que Alexa ya ha recibido. La API conserva esos límites al editar.
- Diseño adaptable a móvil y escritorio, accesible con teclado y compatible con la preferencia de movimiento reducido.

## API

La API de la app valida `Authorization: Bearer <Firebase ID token>` y expone las rutas documentadas en `GUIA_MIMUSICA_ALEXA.md`: `queue`, `library_search`, `library_artists`, `library_albums`, `queue_add`, `queue_remove`, `queue_reorder`, `queue_clear`, `queue_pair_start`, `queue_pair_status` y `queue_unpair`. La guía del backend incluye la migración de la copia local y la ruta protegida para sincronizarla.

El endpoint MCP HTTP para crear y gestionar playlists está en [`api/ibroadcast_mcp.php`](api/ibroadcast_mcp.php), con instrucciones en [`api/README.md`](api/README.md). Está escrito para PHP 7.4, se integra en la carpeta `api` y se conecta directamente a iBroadcast sin una base de datos propia.

La guía de instalación del backend y la skill Alexa está en el archivo entregado junto al proyecto. Este frontend no contiene claves privadas ni secretos de configuración del servidor.
