# Guía de Despliegue en Dokploy - EduLab LMS

Este proyecto está completamente configurado y listo para ser desplegado en tu VPS mediante **Dokploy** utilizando un contenedor monolítico de Docker optimizado con Nginx y PHP 8.2-FPM.

---

## 🛠️ Requerimientos Técnicos
* **Base de Datos**: MySQL 8.0 o superior.
* **PHP**: 8.2 (incluido en el contenedor).
* **Servidor Web**: Nginx (incluido en el contenedor).

---

## 📦 Configuración del Despliegue en Dokploy

Sigue estos pasos en la consola web de tu Dokploy:

### 1. Crear la Base de Datos
1. Ve a **Databases** -> **Create Database** -> Elige **MySQL** (versión `8.0` o `latest`).
2. Asígnale el nombre `edulab-db`.
3. Una vez creada, ve a la pestaña **Connection** de la base de datos y copia las credenciales de conexión interna (`Host`, `Username`, `Password`, `Database`).

### 2. Crear la Aplicación
1. Ve a **Projects** -> Selecciona tu proyecto o crea uno nuevo -> **Create Application**.
2. Nombre: `edulab-lms`.

### 3. Vincular el Repositorio Git
1. En la pestaña **Provider**, selecciona tu proveedor Git.
2. Especifica el repositorio, la rama (`main` o `master`).
3. **Build Path**: Si el código del proyecto está dentro de una subcarpeta en tu repositorio, configúralo correspondientemente (ej: `edulab-lms_v1.4/edulab-lms`), de lo contrario déjalo como `./`.

### 4. Configurar el Método de Construcción (Dockerfile)
1. Ve a la pestaña **Build Configuration**.
2. Cambia el **Build Pack / Builder** a **Dockerfile**.
3. El **Dockerfile Path** debe ser `./Dockerfile` (o relativo al Build Path).
4. Guarda la configuración.

### 5. Configurar Variables de Entorno (`.env`)
Ve a la pestaña **Environment Variables** en Dokploy y añade:

```ini
APP_NAME="EduLab LMS"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://tu-dominio-edulab.com
APP_KEY=base64:COPIA_AQUÍ_LA_KEY_DE_TU_ENV_LOCAL

DB_CONNECTION=mysql
DB_HOST=docker-container-host-interno-de-dokploy # Ej. database-mysql-xxxx
DB_PORT=3306
DB_DATABASE=nombre_db_creada
DB_USERNAME=usuario_db_creado
DB_PASSWORD=contraseña_db_creada

CACHE_DRIVER=file
QUEUE_CONNECTION=sync
SESSION_DRIVER=file
```

*Nota: Asegúrate de usar el **Host Interno** que proporciona Dokploy en la pestaña de conexión de la base de datos para máxima velocidad y seguridad.*

### 6. Configurar Dominio y SSL
1. Ve a la pestaña **Domains**.
2. Añade tu dominio (ej. `clases.tudominio.com`).
3. Apunta previamente ese dominio en tus DNS (Cloudflare, GoDaddy, etc.) a la **IP pública de tu VPS** (registro Tipo `A`).
4. Configura el puerto de red interna como **`80`** (es el puerto expuesto en el Dockerfile).
5. Dokploy generará el SSL automáticamente de manera instantánea.

### 7. Desplegar
Haz clic en **Deploy** en la esquina superior derecha. Dokploy compilará el frontend con Vite y Tailwind, descargará las dependencias de Composer, ejecutará las migraciones de forma automática (`php artisan migrate --force`) y levantará el sitio web.

---

## 🕒 Tareas Programadas y Colas

### 1. Cron Jobs (Tareas Programadas)
Si el sistema requiere programar correos o reportes automáticos, ve a la pestaña **Cron Jobs** de tu aplicación en Dokploy y añade:
* **Frecuencia**: `* * * * *` (Cada minuto)
* **Comando**: `php artisan schedule:run`

### 2. Colas de Trabajo (Queue Worker)
Si cambias `QUEUE_CONNECTION` a `database`, puedes crear una segunda aplicación clonada en Dokploy (sin dominio asignado) y configurar su comando de inicio personalizado a:
```bash
php artisan queue:work --verbose --tries=3 --timeout=90
```
