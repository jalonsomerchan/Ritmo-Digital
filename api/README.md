# API MCP de playlists iBroadcast

Endpoint MCP HTTP en `api/ibroadcast_mcp.php`, compatible con PHP 7.4. Se integra como otra ruta de la API del proyecto: no arranca un proceso por consola y no necesita Node, Composer ni base de datos. Atiende clientes MCP HTTP actuales y clientes que usan el handshake MCP anterior.

La biblioteca y las playlists se leen y modifican directamente en iBroadcast. Herramientas disponibles:

- `buscar_canciones`, `listar_playlists`, `consultar_playlist`
- `crear_playlist`, `editar_playlist`
- `anadir_canciones_playlist`, `quitar_canciones_playlist`, `reordenar_playlist`
- `eliminar_playlist` (la IA debe pedir confirmación antes de borrarla)

## Requisitos y configuración del servidor

- PHP 7.4 o posterior con la extensión cURL.
- HTTPS habilitado.
- Variables de entorno configuradas en el servidor web o PHP-FPM:

```text
MCP_API_TOKEN=secreto_largo_para_autorizar_el_cliente_mcp
IBROADCAST_ACCESS_TOKEN=token_oauth_de_ibroadcast
IBROADCAST_REFRESH_TOKEN=token_oauth_de_ibroadcast
IBROADCAST_CLIENT_ID=id_de_la_aplicacion_ibroadcast
IBROADCAST_REDIRECT_URI=uri_registrada_en_ibroadcast
```

`MCP_API_TOKEN` protege el endpoint mediante `Authorization: Bearer ...`. No pongas secretos en archivos servidos por el sitio. Para renovar credenciales OAuth solicita en iBroadcast los scopes `user.library:read` y `user.library:write`. Los tokens renovados se guardan en un archivo local con permisos restringidos, por defecto en el directorio temporal del servidor; puedes cambiar la ruta con `IBROADCAST_TOKEN_FILE`. El archivo local tiene prioridad sobre las variables iniciales; bórralo si vas a vincular otra cuenta.

## Conectar el cliente MCP

Publica el archivo y configura el cliente MCP para conectarse por HTTP a:

```text
https://tu-dominio/api/ibroadcast_mcp.php
```

Ejemplo de configuración para un cliente que admite MCP HTTP:

```json
{
  "type": "http",
  "url": "https://tu-dominio/api/ibroadcast_mcp.php",
  "headers": {
    "Authorization": "Bearer TU_MCP_API_TOKEN"
  }
}
```

El endpoint MCP es stateless y responde JSON-RPC sobre HTTP POST. Todas las operaciones de listas se ejecutan contra la API oficial de iBroadcast.
