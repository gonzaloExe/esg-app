# ESG — Entorno Seguro y Gestión

Versión con agente Windows para registrar equipos de la red.

## Qué agrega el agente

Al detectar una PC sin registrar, ESG muestra una descarga única de `ESG-Agent.exe`. El agente, al ejecutarse, obtiene y envía al servidor datos de inventario como:

- Nombre del equipo, usuario de Windows y dominio.
- Windows, versión, compilación y arquitectura.
- CPU y núcleos/hilos.
- Memoria RAM y módulos.
- BIOS, placa madre y producto del sistema.
- Discos físicos y unidades lógicas.
- GPU y versión de controlador.
- Adaptadores de red, IP, MAC, DNS y gateway.
- Software instalado (desde los registros de desinstalación de Windows).
- Antivirus detectado por SecurityCenter2 cuando está disponible.

No se recolectan contraseñas ni claves de producto.

## Flujo

1. PC nueva abre ESG.
2. ESG muestra **Descargar agente ESG**.
3. El servidor genera un token de un solo uso y personaliza el EXE con la URL del servidor.
4. El usuario ejecuta `ESG-Agent.exe`.
5. El agente envía el inventario al endpoint de ESG.
6. El agente abre el navegador con un enlace de vinculación de un solo uso.
7. ESG vincula esa identidad del navegador con el equipo real y crea/actualiza el usuario.
8. En el panel SuperAdmin aparece **Equipos registrados** y se puede ver el inventario.

## Base de datos

Ejecute una vez:

`actualizar_agente.sql`

No modifica la tabla de tickets existente.

## Seguridad

El endpoint de registro del agente usa un token aleatorio de un solo uso generado durante la descarga y con expiración. La vinculación se valida también con la IP observada por el servidor.

En producción es recomendable servir ESG bajo HTTPS con un certificado de CA confiable en las PCs. El EXE de esta versión no está firmado digitalmente; para distribución institucional conviene firmarlo con un certificado de código.
