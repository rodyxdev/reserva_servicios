-- =====================================================================
--  SISTEMA DE RESERVAS — ESQUEMA DE BASE DE DATOS
--  MySQL 8.0+ / MariaDB 10.6+
-- =====================================================================
--
--  NOTAS DE COMPATIBILIDAD (leer antes de desplegar):
--
--  1) HOSTING COMPARTIDO (InfinityFree, Hostinger, etc.):
--     Estos paneles NO permiten CREATE DATABASE por SQL — la base se crea
--     desde el panel de control y se te asigna un nombre tipo "epiz_123_reservas".
--     Antes de importar por phpMyAdmin, COMENTA las dos primeras sentencias
--     (CREATE DATABASE y USE) y selecciona la base a mano en el desplegable.
--
--  2) COLUMNA GENERADA `appointments.blocked_until`:
--     Requiere MySQL 5.7+ o MariaDB 10.2+. Si tu hosting corre una versión
--     anterior, o rechaza la expresión con INTERVAL, usa la alternativa
--     comentada al pie de la tabla `appointments` (columna normal calculada
--     en PHP). El resto del sistema funciona igual.
--
--  3) RESTRICCIONES CHECK:
--     Se aplican de verdad desde MySQL 8.0.16 y MariaDB 10.2.1.
--     En versiones anteriores se ignoran en silencio (no fallan la importación),
--     por eso la validación equivalente también vive en PHP.
--
--  4) `SELECT ... FOR UPDATE SKIP LOCKED` (usado por scripts/send-reminders.php)
--     requiere MySQL 8.0+ o MariaDB 10.6+. El script detecta la versión y cae
--     a un bloqueo optimista por UPDATE si no está disponible.
--
-- =====================================================================

-- COLLATION: utf8mb4_unicode_ci y no utf8mb4_0900_ai_ci.
-- La segunda es exclusiva de MySQL 8 (el "0900" es la version 9.0 del UCA):
-- MariaDB la rechaza con "ERROR 1273 Unknown collation". Como el objetivo
-- incluye desplegar en InfinityFree, que corre MariaDB, se usa la que
-- entienden los dos motores. Verificado contra MariaDB 10.4.32.
CREATE DATABASE IF NOT EXISTS reservas
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE reservas;

SET NAMES utf8mb4;

-- Toda la aplicacion trabaja en UTC. Los DEFAULT CURRENT_TIMESTAMP de este
-- esquema usan la zona de la SESION, asi que tanto este script como cada
-- conexion PDO de la app fijan la sesion en UTC. Si no se hace, created_at
-- y locked_until quedan en la hora del servidor y las comparaciones con
-- UTC_TIMESTAMP() dejan de tener sentido.
SET time_zone = '+00:00';

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS appointment_status_log;
DROP TABLE IF EXISTS appointment_reminders;
DROP TABLE IF EXISTS appointment_slots;
DROP TABLE IF EXISTS appointments;
DROP TABLE IF EXISTS customers;
DROP TABLE IF EXISTS schedule_exceptions;
DROP TABLE IF EXISTS employee_hours;
DROP TABLE IF EXISTS business_hours;
DROP TABLE IF EXISTS employee_service;
DROP TABLE IF EXISTS employees;
DROP TABLE IF EXISTS services;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS rate_limits;
DROP TABLE IF EXISTS businesses;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
--  NEGOCIO Y CONFIGURACIÓN
-- =====================================================================
-- Todas las tablas hijas cuelgan de business_id: el sistema es mono-negocio
-- en la interfaz, pero multi-tenant en los datos. Añadir tenancy después es
-- una migración dolorosa; dejarlo preparado ahora no cuesta nada.

CREATE TABLE businesses (
  id                        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name                      VARCHAR(150)  NOT NULL,
  slug                      VARCHAR(150)  NOT NULL COMMENT 'URL publica: /reservar/{slug}',
  email                     VARCHAR(190)  NOT NULL COMMENT 'Recibe las notificaciones de nueva cita',
  phone                     VARCHAR(30)   NULL,
  timezone                  VARCHAR(64)   NOT NULL DEFAULT 'America/Mexico_City'
                                          COMMENT 'IANA. La BD guarda UTC; esto es para presentar y calcular',
  currency                  CHAR(3)       NOT NULL DEFAULT 'MXN',

  -- Reglas de reservacion: configurables, nada hardcodeado en el codigo
  slot_granularity_minutes  TINYINT UNSIGNED NOT NULL DEFAULT 15
                                          COMMENT 'Paso de la grilla que ve el cliente (15/20/30)',
  min_advance_minutes       SMALLINT UNSIGNED NOT NULL DEFAULT 120
                                          COMMENT 'No se puede reservar con menos de X minutos de anticipacion',
  max_advance_days          SMALLINT UNSIGNED NOT NULL DEFAULT 60
                                          COMMENT 'Ventana futura visible en el calendario publico',
  default_buffer_minutes    TINYINT UNSIGNED NOT NULL DEFAULT 0
                                          COMMENT 'Fallback cuando el servicio no define buffer propio',
  auto_confirm              TINYINT(1)    NOT NULL DEFAULT 1
                                          COMMENT '1 = la cita publica nace confirmed; 0 = nace pending',

  is_active                 TINYINT(1)    NOT NULL DEFAULT 1,
  created_at                DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
                                          ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_business_slug (slug)
) ENGINE=InnoDB;

-- =====================================================================
--  USUARIOS DEL PANEL
-- =====================================================================
-- failed_attempts / locked_until implementan el lockout tras 5 intentos.
-- El hash lo produce password_hash() de PHP (bcrypt, prefijo $2y$).

CREATE TABLE users (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  business_id     INT UNSIGNED NOT NULL,
  name            VARCHAR(120) NOT NULL,
  email           VARCHAR(190) NOT NULL,
  password_hash   VARCHAR(255) NOT NULL COMMENT 'password_hash(), PASSWORD_DEFAULT',
  role            ENUM('owner','staff') NOT NULL DEFAULT 'staff',
  last_login_at   DATETIME NULL,
  failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until    DATETIME NULL COMMENT 'UTC. Si > NOW(), el login se rechaza sin verificar la contrasena',
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_business (business_id),
  CONSTRAINT fk_users_business FOREIGN KEY (business_id)
    REFERENCES businesses(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =====================================================================
--  SERVICIOS Y EMPLEADOS
-- =====================================================================
-- Nunca se borran fisicamente (hay citas historicas apuntando): is_active = 0.
-- Por eso las FK de appointments son RESTRICT por defecto, no CASCADE.

CREATE TABLE services (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  business_id       INT UNSIGNED NOT NULL,
  name              VARCHAR(150) NOT NULL,
  description       TEXT NULL,
  duration_minutes  SMALLINT UNSIGNED NOT NULL COMMENT 'Duracion real del servicio',
  buffer_minutes    TINYINT UNSIGNED NOT NULL DEFAULT 0
                                     COMMENT 'Limpieza/preparacion posterior. Bloquea agenda pero no se cobra',
  price             DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  color             CHAR(7) NOT NULL DEFAULT '#0d6efd' COMMENT 'Color del evento en FullCalendar',
  sort_order        SMALLINT NOT NULL DEFAULT 0,
  is_active         TINYINT(1) NOT NULL DEFAULT 1,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                                ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_services_business_active (business_id, is_active),
  CONSTRAINT fk_services_business FOREIGN KEY (business_id)
    REFERENCES businesses(id) ON DELETE CASCADE,
  CONSTRAINT chk_services_duration CHECK (duration_minutes BETWEEN 5 AND 1440)
) ENGINE=InnoDB;

CREATE TABLE employees (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  business_id  INT UNSIGNED NOT NULL,
  name         VARCHAR(120) NOT NULL,
  email        VARCHAR(190) NULL COMMENT 'Opcional: copia de la notificacion de nueva cita',
  phone        VARCHAR(30)  NULL,
  role_title   VARCHAR(100) NULL COMMENT 'Terapeuta, Dra., Mecanico',
  is_active    TINYINT(1) NOT NULL DEFAULT 1,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_employees_business_active (business_id, is_active),
  CONSTRAINT fk_employees_business FOREIGN KEY (business_id)
    REFERENCES businesses(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Que empleado puede prestar que servicio. Sin fila aqui, no aparece como opcion.
CREATE TABLE employee_service (
  employee_id  INT UNSIGNED NOT NULL,
  service_id   INT UNSIGNED NOT NULL,
  PRIMARY KEY (employee_id, service_id),
  KEY idx_es_service (service_id),
  CONSTRAINT fk_es_employee FOREIGN KEY (employee_id)
    REFERENCES employees(id) ON DELETE CASCADE,
  CONSTRAINT fk_es_service FOREIGN KEY (service_id)
    REFERENCES services(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =====================================================================
--  HORARIOS
-- =====================================================================
-- Varias filas por dia permiten cortar la jornada (9-14 y 16-19) sin trucos.
-- Las columnas TIME son hora LOCAL del negocio; la conversion a UTC ocurre en PHP.

CREATE TABLE business_hours (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  business_id INT UNSIGNED NOT NULL,
  weekday     TINYINT UNSIGNED NOT NULL COMMENT '1=Lunes .. 7=Domingo (ISO-8601, igual que date N)',
  opens_at    TIME NOT NULL,
  closes_at   TIME NOT NULL,
  KEY idx_bh_business_weekday (business_id, weekday),
  CONSTRAINT fk_bh_business FOREIGN KEY (business_id)
    REFERENCES businesses(id) ON DELETE CASCADE,
  CONSTRAINT chk_bh_weekday CHECK (weekday BETWEEN 1 AND 7),
  CONSTRAINT chk_bh_range   CHECK (closes_at > opens_at)
) ENGINE=InnoDB;

-- Horario propio del empleado. Si NO tiene filas, hereda el del negocio.
-- Si las tiene, la disponibilidad es la INTERSECCION de ambos.
CREATE TABLE employee_hours (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id INT UNSIGNED NOT NULL,
  weekday     TINYINT UNSIGNED NOT NULL,
  starts_at   TIME NOT NULL,
  ends_at     TIME NOT NULL,
  KEY idx_eh_employee_weekday (employee_id, weekday),
  CONSTRAINT fk_eh_employee FOREIGN KEY (employee_id)
    REFERENCES employees(id) ON DELETE CASCADE,
  CONSTRAINT chk_eh_weekday CHECK (weekday BETWEEN 1 AND 7),
  CONSTRAINT chk_eh_range   CHECK (ends_at > starts_at)
) ENGINE=InnoDB;

-- Feriados, vacaciones, cierres puntuales u horario especial de un dia.
-- employee_id NULL = aplica a todo el negocio.
CREATE TABLE schedule_exceptions (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  business_id INT UNSIGNED NOT NULL,
  employee_id INT UNSIGNED NULL,
  exc_date    DATE NOT NULL COMMENT 'Fecha local del negocio',
  is_closed   TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = cerrado todo el dia; 0 = usa starts_at/ends_at',
  starts_at   TIME NULL,
  ends_at     TIME NULL,
  reason      VARCHAR(150) NULL,
  KEY idx_exc_lookup (business_id, exc_date, employee_id),
  CONSTRAINT fk_exc_business FOREIGN KEY (business_id)
    REFERENCES businesses(id) ON DELETE CASCADE,
  CONSTRAINT fk_exc_employee FOREIGN KEY (employee_id)
    REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =====================================================================
--  CLIENTES
-- =====================================================================
-- Sin registro ni contrasena: al reservar se busca por (business_id, email)
-- y se reutiliza la ficha si existe. Asi el panel acumula historial solo.

CREATE TABLE customers (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  business_id  INT UNSIGNED NOT NULL,
  name         VARCHAR(120) NOT NULL,
  email        VARCHAR(190) NOT NULL,
  phone        VARCHAR(30)  NOT NULL,
  notes        TEXT NULL COMMENT 'Notas internas del negocio, nunca visibles para el cliente',
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_customers_business_email (business_id, email),
  KEY idx_customers_phone (business_id, phone),
  CONSTRAINT fk_customers_business FOREIGN KEY (business_id)
    REFERENCES businesses(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =====================================================================
--  CITAS
-- =====================================================================
-- Todos los DATETIME van en UTC.
-- duration_minutes / buffer_minutes / price son SNAPSHOTS tomados del
-- servicio al momento de reservar: si manana cambia el catalogo, las citas
-- historicas no se reescriben solas.

CREATE TABLE appointments (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  business_id       INT UNSIGNED NOT NULL,
  customer_id       INT UNSIGNED NOT NULL,
  service_id        INT UNSIGNED NOT NULL,
  employee_id       INT UNSIGNED NOT NULL
                    COMMENT 'La opcion sin-preferencia se resuelve en PHP al confirmar; aqui siempre hay empleado',

  starts_at         DATETIME NOT NULL COMMENT 'UTC',
  ends_at           DATETIME NOT NULL COMMENT 'UTC. Fin del servicio: lo que ve el cliente',
  duration_minutes  SMALLINT UNSIGNED NOT NULL,
  buffer_minutes    TINYINT UNSIGNED NOT NULL DEFAULT 0,

  -- Columna generada: el motor garantiza que blocked_until nunca se
  -- desincronice de ends_at + buffer. Es lo que realmente bloquea la agenda.
  blocked_until     DATETIME GENERATED ALWAYS AS
                       (ends_at + INTERVAL buffer_minutes MINUTE) STORED,

  price             DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  status            ENUM('pending','confirmed','completed','cancelled','no_show')
                    NOT NULL DEFAULT 'confirmed',
  source            ENUM('public','admin') NOT NULL DEFAULT 'public',
  customer_notes    TEXT NULL COMMENT 'Lo que escribio el cliente al reservar',
  internal_notes    TEXT NULL COMMENT 'Lo que escribe el negocio en el panel',

  public_token      CHAR(32) NOT NULL COMMENT 'Consultar/cancelar por link sin login: bin2hex(random_bytes(16))',
  cancelled_at      DATETIME NULL,
  cancelled_by      ENUM('customer','staff') NULL,
  cancel_reason     VARCHAR(255) NULL,

  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                              ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_appt_token (public_token),
  KEY idx_appt_employee_time (employee_id, starts_at),
  KEY idx_appt_business_time (business_id, starts_at),
  KEY idx_appt_status_time   (business_id, status, starts_at),
  KEY idx_appt_reminders     (status, starts_at),
  KEY idx_appt_customer      (customer_id),

  CONSTRAINT fk_appt_business FOREIGN KEY (business_id) REFERENCES businesses(id),
  CONSTRAINT fk_appt_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
  CONSTRAINT fk_appt_service  FOREIGN KEY (service_id)  REFERENCES services(id),
  CONSTRAINT fk_appt_employee FOREIGN KEY (employee_id) REFERENCES employees(id),
  CONSTRAINT chk_appt_range CHECK (ends_at > starts_at)
) ENGINE=InnoDB;

-- ALTERNATIVA si tu MySQL/MariaDB rechaza la columna generada:
--   Cambia la definicion por una columna normal
--       blocked_until DATETIME NOT NULL,
--   y calculala en PHP dentro de AppointmentService::book().
--   Todo lo demas queda igual.

-- =====================================================================
--  GARANTIA ANTI-DOBLE-RESERVA
-- =====================================================================
-- MySQL no tiene constraint de exclusion por rangos (el EXCLUDE USING gist
-- de PostgreSQL). La equivalencia: materializar el tiempo ocupado en bloques
-- discretos de 5 minutos y dejar que un PRIMARY KEY haga cumplir la
-- exclusion mutua.
--
-- Al reservar, DENTRO de la misma transaccion que crea la cita, se insertan
-- todas las filas de 5 minutos entre starts_at y blocked_until. Si otro
-- request gano la carrera, el INSERT falla con error 1062 (duplicate key),
-- se hace ROLLBACK y el cliente ve "ese horario acaba de ocuparse".
--
-- La garantia la da el motor, no el codigo PHP ni el JavaScript: dos procesos
-- concurrentes no pueden crear citas solapadas ni aunque lo intenten.

CREATE TABLE appointment_slots (
  employee_id    INT UNSIGNED NOT NULL,
  slot_at        DATETIME NOT NULL COMMENT 'UTC, alineado a multiplos de 5 minutos',
  appointment_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (employee_id, slot_at),
  KEY idx_slots_appointment (appointment_id),
  CONSTRAINT fk_slots_appointment FOREIGN KEY (appointment_id)
    REFERENCES appointments(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =====================================================================
--  RECORDATORIOS (cola del cron)
-- =====================================================================
-- El UNIQUE (appointment_id, kind, channel) es lo importante: hace la cola
-- idempotente. El cron puede correr cada 5 minutos sin riesgo de enviar dos
-- veces el mismo recordatorio, incluso si una ejecucion anterior murio a
-- media faena.

CREATE TABLE appointment_reminders (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  appointment_id INT UNSIGNED NOT NULL,
  kind           ENUM('confirmation','reminder_24h','reminder_1h','cancellation')
                 NOT NULL,
  channel        ENUM('email') NOT NULL DEFAULT 'email',
  scheduled_for  DATETIME NOT NULL COMMENT 'UTC. Para reminder_24h = starts_at - 24h',

  -- 'sending' NO estaba en el esquema aprobado; se anadio al implementar
  -- el cron. Es el estado intermedio entre reclamar una fila y saber si el
  -- correo salio.
  --
  -- Hace falta porque hablar con un servidor SMTP puede tardar segundos, y
  -- mantener abierta una transaccion de base de datos durante ese rato
  -- bloquea filas y consume conexiones sin necesidad. Con 'sending' el
  -- proceso marca lo que va a enviar, cierra la transaccion, y solo despues
  -- se pone a enviar.
  --
  -- Si el proceso muere a mitad, esas filas se quedarian atascadas: el cron
  -- las devuelve a 'pending' al arrancar (NotificationService::requeueStale).
  --
  -- Si ya importaste el esquema sin este valor:
  --   ALTER TABLE appointment_reminders
  --     MODIFY status ENUM('pending','sending','sent','failed','skipped')
  --     NOT NULL DEFAULT 'pending';
  status         ENUM('pending','sending','sent','failed','skipped')
                 NOT NULL DEFAULT 'pending',
  attempts       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  sent_at        DATETIME NULL,
  last_error     VARCHAR(500) NULL,
  UNIQUE KEY uq_reminder_once (appointment_id, kind, channel),
  KEY idx_reminder_queue (status, scheduled_for),
  CONSTRAINT fk_reminder_appt FOREIGN KEY (appointment_id)
    REFERENCES appointments(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =====================================================================
--  AUDITORIA DE CAMBIOS DE ESTADO
-- =====================================================================
-- changed_by_user_id NULL = lo cambio el propio cliente desde su link publico.

CREATE TABLE appointment_status_log (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  appointment_id      INT UNSIGNED NOT NULL,
  from_status         VARCHAR(20) NULL,
  to_status           VARCHAR(20) NOT NULL,
  changed_by_user_id  INT UNSIGNED NULL,
  note                VARCHAR(255) NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_log_appointment (appointment_id, created_at),
  CONSTRAINT fk_log_appt FOREIGN KEY (appointment_id)
    REFERENCES appointments(id) ON DELETE CASCADE,
  CONSTRAINT fk_log_user FOREIGN KEY (changed_by_user_id)
    REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =====================================================================
--  ANADIDO AL ESQUEMA APROBADO: RATE LIMITING
-- =====================================================================
-- No estaba en el diseno original; lo requiere el punto de seguridad
-- "maximo 5 intentos por IP cada 10 minutos" del endpoint publico.
--
-- Se eligio tabla en vez de archivos de cache por tres razones:
--   1. Funciona con varios procesos PHP a la vez sin condiciones de carrera
--      (los archivos exigirian flock y aun asi son fragiles en NFS).
--   2. En hosting compartido el disco suele ser el recurso mas lento y con
--      cuota mas apretada; la conexion a la BD ya esta abierta.
--   3. Es inspeccionable: se puede ver quien esta siendo limitado.
--
-- La ventana es deslizante: se guarda una fila por intento y se cuentan los
-- de los ultimos N minutos. La limpieza la hace scripts/send-reminders.php
-- en cada corrida (borra lo anterior a 24h).

CREATE TABLE rate_limits (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bucket      VARCHAR(40)  NOT NULL COMMENT 'Accion limitada: booking, login, cancel',
  identifier  VARBINARY(16) NOT NULL COMMENT 'IP normalizada con inet_pton(): sirve IPv4 e IPv6',
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'UTC',
  KEY idx_rl_window (bucket, identifier, created_at),
  KEY idx_rl_cleanup (created_at)
) ENGINE=InnoDB;

-- =====================================================================
--  FIN DEL ESQUEMA
-- =====================================================================
