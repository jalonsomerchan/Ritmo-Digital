# Ritmo Digital

PWA en Angular para explorar el catálogo de iBroadcast guardado en MySQL y gestionar la cola compartida con Alexa. Las búsquedas usan la copia local; el backend sincroniza el catálogo con iBroadcast mediante una tarea protegida del servidor.

## Puesta en marcha

1. Instala Node.js 22 o posterior y ejecuta `npm install`.
2. En Firebase Console, activa **Authentication → Google** y registra una aplicación web.
3. Rellena las variables `VITE_FIREBASE_*` y `VITE_API_BASE` de `.env.local` y `.env.production`.
4. Inicia la app con `npm start` y accede desde `http://localhost:4200`. Este comando carga `.env.local`.

La API valida el Firebase ID token. El acceso se realiza con Google a través de Firebase Authentication. En escritorio se abre la ventana de Google; en móviles se usa redirección. Autoriza los dominios local y de producción en Firebase Authentication. En producción, publica la carpeta generada por `npm run build` en un servidor HTTPS. El service worker se activa en la compilación de producción.

Si alojas el frontend en otro dominio, el servidor de la API debe permitir CORS para ese origen. `npm run build` carga `.env.production`; los valores se generan en archivos locales ignorados por Git.

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
