# ESG — Entorno Seguro y Gestión

Sistema web simplificado para gestionar tickets de incidencias mediante PHP 8+ y MySQL.

## Características

- Dos roles: `SuperAdmin` y `Usuario`.
- Panel de usuarios en `index.php`.
- Panel exclusivo de administración en `admin.php`.
- Tickets con título, descripción y foto opcional.
- Estados `pendiente` y `resuelto`.
- SuperAdmin puede marcar tickets como hechos o eliminarlos permanentemente.
- Gestión de activación/desactivación de usuarios.
- Instalador automático.
- PDO + prepared statements.
- Protección CSRF en acciones POST.
- Protección de `/uploads`.
- Validación de imágenes JPG/JPEG/PNG de máximo 5 MB.
- Responsive con Bootstrap 5.
- SweetAlert2 y FontAwesome.

## Requisitos

- PHP 8.0 o superior.
- MySQL 5.7+ o compatible.
- Apache 2.4+ o Nginx.
- Extensión PHP PDO.
- Driver `pdo_mysql`.
- Extensión `fileinfo` para validar imágenes.
- Extensión GD recomendada.
- Permiso de escritura para `uploads/`.
- HTTPS recomendado, especialmente en una red institucional.

## IMPORTANTE: identificación de la PC

PHP ejecutado en un servidor web **no puede obtener directamente el hostname ni el usuario de Windows/Linux de la PC cliente**.

Por ejemplo:

```php
gethostname()
```

devuelve el nombre del servidor donde corre PHP, no el nombre de la PC desde la que se abrió Chrome/Edge/Firefox.

Asimismo:

```php
get_current_user()
```

identifica al usuario del sistema que ejecuta PHP en el servidor, no al usuario de Windows de la PC cliente.

Por esa limitación, esta versión de ESG no realiza detección de hardware ni pretende fingir que `gethostname()` identifica al cliente. En su lugar:

1. El servidor genera un identificador aleatorio de 32 caracteres.
2. Ese identificador se guarda en una cookie persistente del navegador.
3. Se usa ese identificador como `pc_identificador`.
4. También se guarda la IP de origen.
5. El hostname y usuario obtenidos por PHP quedan como información del servidor.
6. No se utilizan contraseñas.

Esto permite mantener la identificación simple solicitada, pero debe entenderse como **identificación por navegador**, no como identificación criptográficamente segura de una PC física.

Si se necesita identificar físicamente cada PC de una red institucional, una alternativa real es utilizar certificados cliente, un agente instalado en las PCs, autenticación integrada de Windows o un proxy que agregue una identidad de máquina.

## Instalación paso a paso

### 1. Subir archivos

Descomprimir `esg-app.zip` y subir la carpeta `esg-app` al servidor web.

Ejemplo:

```text
/var/www/html/esg-app/
```

### 2. Permisos

Asegurar que PHP pueda escribir en:

```text
esg-app/uploads/
```

En Linux, un ejemplo típico es:

```bash
sudo chown -R www-data:www-data /var/www/html/esg-app/uploads
sudo chmod 750 /var/www/html/esg-app/uploads
```

Los comandos exactos dependen de la configuración de Apache/Nginx.

### 3. Base de datos

No es obligatorio crearla manualmente. `instalar.php` intenta crearla automáticamente.

El usuario MySQL utilizado durante la instalación debe tener permisos suficientes para crear la base de datos y las tablas.

Si el hosting no permite `CREATE DATABASE`, crear manualmente la base de datos y luego ejecutar `database.sql`.

### 4. Abrir el instalador

Acceder a:

```text
https://tudominio.com/esg-app/instalar.php
```

o, si ESG está directamente en el document root:

```text
https://tudominio.com/instalar.php
```

Completar:

- Host MySQL.
- Usuario MySQL.
- Contraseña.
- Nombre de base de datos.

### 5. Ejecutar instalación

El instalador:

- crea la base de datos si tiene permisos;
- crea las tablas;
- registra el navegador desde el que se ejecuta como SuperAdmin;
- crea/verifica `uploads/`;
- comprueba que `uploads/` sea escribible.

### 6. Eliminar `instalar.php`

Después de una instalación exitosa:

```bash
rm instalar.php
```

Esto es importante para evitar que alguien vuelva a intentar ejecutar el instalador.

### 7. Entrar al sistema

Usuario común:

```text
https://tudominio.com/esg-app/index.php
```

SuperAdmin:

```text
https://tudominio.com/esg-app/admin.php
```

El navegador utilizado durante la instalación queda asociado al SuperAdmin.

## Reglas de acceso

### `index.php`

Solo usuarios comunes.

Si el usuario identificado es SuperAdmin, se redirige automáticamente a:

```text
admin.php
```

### `admin.php`

Solo SuperAdmin.

Si un usuario común intenta entrar, se redirige automáticamente a:

```text
index.php
```

## Tickets

Un usuario puede:

- crear un ticket;
- escribir título;
- escribir descripción;
- adjuntar una imagen opcional;
- consultar solamente sus propios tickets.

El SuperAdmin puede:

- consultar todos los tickets;
- marcar un ticket como resuelto;
- eliminar un ticket permanentemente.

## Usuarios

El SuperAdmin puede:

- ver los usuarios registrados;
- activar usuarios;
- desactivar usuarios.

El SuperAdmin no puede desactivarse desde el panel.

## API

Todas las acciones pasan por `api.php`.

### GET

```text
obtener_mis_tickets
obtener_todos_tickets
obtener_usuarios
obtener_estadisticas
```

### POST

```text
crear_ticket
marcar_hecho
borrar_ticket
desactivar_usuario
```

Cada endpoint comprueba el rol correspondiente antes de ejecutar la operación.

## Seguridad

La aplicación incorpora:

- PDO con prepared statements.
- Validación de entradas.
- CSRF para operaciones POST.
- Validación MIME de imágenes.
- Límite de 5 MB.
- Nombres aleatorios para imágenes.
- `.htaccess` que bloquea el acceso directo a `/uploads`.
- Separación estricta de paneles.
- Escape HTML mediante `htmlspecialchars`.

### HTTPS

Se recomienda utilizar HTTPS en producción. La aplicación no contiene contraseñas de usuarios, por lo que la protección de la sesión y las cookies depende especialmente de una conexión segura.

## Diagnóstico

Existe:

```text
verificar_db.php
```

Permite comprobar:

- conexión MySQL;
- tablas existentes;
- usuarios registrados;
- existencia del SuperAdmin.

Una vez utilizado, conviene eliminarlo o restringir su acceso en producción.

## Estructura

```text
esg-app/
├── assets/
│   ├── css/
│   │   └── estilo.css
│   └── js/
│       └── app.js
├── includes/
│   ├── config.php
│   └── auth.php
├── uploads/
│   ├── .htaccess
│   └── .gitkeep
├── index.php
├── admin.php
├── logout.php
├── api.php
├── instalar.php
├── verificar_db.php
├── README.md
└── database.sql
```

Durante una instalación exitosa también se genera:

```text
includes/config.local.php
```

Ese archivo contiene las credenciales de conexión a MySQL y **no debe publicarse en un repositorio público**.

## Problemas comunes

### Error de conexión a MySQL

Comprobar:

- host;
- usuario;
- contraseña;
- nombre de base de datos;
- permisos del usuario MySQL;
- extensión `pdo_mysql`.

### No se puede subir una foto

Comprobar:

- permisos de `uploads/`;
- límite de `upload_max_filesize`;
- límite de `post_max_size`;
- tamaño máximo de 5 MB establecido por ESG;
- formato JPG/JPEG/PNG.

### El usuario aparece como otra PC

ESG utiliza una cookie persistente como identificador. Si se borra la cookie, se cambia de navegador o se utiliza modo incógnito, el sistema puede generar un nuevo identificador.

### Se necesita identificar la PC física

Esta versión no puede hacerlo únicamente con PHP y un navegador. Para identificación física real se necesita un mecanismo adicional como:

- certificado cliente;
- agente de escritorio;
- autenticación integrada;
- proxy con identidad de máquina;
- infraestructura de gestión de dispositivos.

## Versión

ESG 1.0.0
# esg-app
