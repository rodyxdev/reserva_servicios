-- =====================================================================
--  IMPORTACION PARA HOSTING COMPARTIDO (InfinityFree y similares)
-- =====================================================================
--  Generado desde database/schema.sql + database/seed.sql.
--  NO editar a mano: regenerar si cambian los originales.
--
--  No usa CREATE DATABASE, USE, ni CREATE TEMPORARY TABLE: ninguno de
--  los tres esta permitido con los privilegios de un hosting gratuito.
--
--  Uso: panel de control -> phpMyAdmin -> selecciona TU base
--       (if0_XXXXXXXX_reservas) -> pestana Importar -> este archivo.
--  Empieza con DROP TABLE IF EXISTS de las 14 tablas, asi que se puede
--  reimportar tantas veces como haga falta.
-- =====================================================================

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
-- CREATE DATABASE y USE eliminados a proposito.
-- En hosting compartido la base ya existe, la crea el panel con un
-- nombre con prefijo (if0_XXXXXXXX_reservas) y el usuario no tiene
-- privilegio para CREATE DATABASE. phpMyAdmin ya trabaja sobre la
-- base seleccionada, asi que estas dos lineas solo darian error.

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


-- =====================================================================
--  DATOS DE DEMOSTRACION (seed.sql)
-- =====================================================================

-- =====================================================================
--  SISTEMA DE RESERVAS — DATOS DE PRUEBA
--  Negocio de ejemplo: "Spa Aurora"
-- =====================================================================
--
--  COMO FUNCIONAN LAS FECHAS
--  --------------------------
--  Nada esta escrito con fechas fijas: todo se ancla al PROXIMO LUNES
--  calculado desde CURDATE(). Asi la demo siempre tiene citas pasadas y
--  futuras en dias laborables, la cargues hoy o dentro de seis meses.
--
--    @lunes   = proximo lunes (siempre 1..7 dias en el futuro)
--    @pasado  = ese mismo lunes menos 14 dias (siempre 7..14 dias atras)
--
--  ZONA HORARIA
--  ------------
--  La BD guarda UTC. El negocio vive en America/Mexico_City (UTC-6, sin
--  horario de verano desde 2022), asi que en este archivo:
--
--       hora UTC = hora local + 6
--
--  Se usa la variable @tz para no repetir el numero. Fijate en la cita de
--  las 18:00 local: en UTC cae al dia SIGUIENTE. Eso es correcto y es
--  justo el tipo de caso que rompe los sistemas que guardan hora local.
--
--  CREDENCIALES DEL PANEL
--  ----------------------
--    admin@spa-aurora.test     / Demo1234!   (owner)
--    recepcion@spa-aurora.test / Demo1234!   (staff)
--
--  Los hashes son bcrypt reales generados con password_hash(), prefijo $2y$.
--  Son datos de DEMO: cambialos antes de exponer nada publicamente.
--
-- =====================================================================

SET NAMES utf8mb4;

-- CRITICO: fija la sesion en UTC. Sin esto, CURDATE() y los DEFAULT
-- CURRENT_TIMESTAMP escribirian en la hora del servidor MySQL (que en un
-- hosting compartido puede ser cualquier cosa) y el seed quedaria corrido
-- respecto a los datos que si calculamos en UTC.
-- La aplicacion hace lo mismo en cada conexion PDO.
SET time_zone = '+00:00';

-- Ajusta si tu base se llama distinto (hosting compartido):
-- USE reservas;

SET FOREIGN_KEY_CHECKS = 0;
TRUNCATE TABLE appointment_status_log;
TRUNCATE TABLE appointment_reminders;
TRUNCATE TABLE appointment_slots;
TRUNCATE TABLE appointments;
TRUNCATE TABLE customers;
TRUNCATE TABLE schedule_exceptions;
TRUNCATE TABLE employee_hours;
TRUNCATE TABLE business_hours;
TRUNCATE TABLE employee_service;
TRUNCATE TABLE employees;
TRUNCATE TABLE services;
TRUNCATE TABLE users;
TRUNCATE TABLE rate_limits;
TRUNCATE TABLE businesses;
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
--  Anclas temporales
-- ---------------------------------------------------------------------
-- WEEKDAY() devuelve 0=Lunes .. 6=Domingo. El (7 - WEEKDAY) garantiza que
-- @lunes sea SIEMPRE un lunes futuro, nunca hoy.
SET @lunes  = DATE_ADD(CURDATE(), INTERVAL (7 - WEEKDAY(CURDATE())) DAY);
SET @pasado = DATE_SUB(@lunes, INTERVAL 14 DAY);
SET @tz     = 6;   -- America/Mexico_City = UTC-6

-- =====================================================================
--  NEGOCIO
-- =====================================================================
INSERT INTO businesses
  (id, name, slug, email, phone, timezone, currency,
   slot_granularity_minutes, min_advance_minutes, max_advance_days,
   default_buffer_minutes, auto_confirm, is_active)
VALUES
  (1, 'Spa Aurora', 'spa-aurora', 'contacto@spa-aurora.test', '+52 55 1234 5678',
   'America/Mexico_City', 'MXN',
   15,    -- la grilla publica avanza de 15 en 15 minutos
   120,   -- no se reserva con menos de 2 horas de anticipacion
   60,    -- se puede reservar hasta 60 dias adelante
   0,     -- cada servicio define su propio buffer
   1,     -- las reservas publicas nacen confirmadas
   1);

-- =====================================================================
--  USUARIOS DEL PANEL
-- =====================================================================
INSERT INTO users (id, business_id, name, email, password_hash, role, is_active)
VALUES
  (1, 1, 'Rodrigo (Owner)', 'admin@spa-aurora.test',
   '$2y$10$sPQXTwmmN9h1qC.fMunHZu/BScs.6L1aQLo8TQCkMZ4nN/V/83j5m', 'owner', 1),
  (2, 1, 'Recepcion', 'recepcion@spa-aurora.test',
   '$2y$10$ANrr1zujU27y9wpw.BX1COmwpVw/N1IgiAkiR/BR5BWi57Borxl1S', 'staff', 1);

-- =====================================================================
--  SERVICIOS
-- =====================================================================
-- Duraciones deliberadamente heterogeneas (30/45/60/75/90) y buffers
-- distintos: si la logica de disponibilidad solo funcionara con bloques
-- uniformes de 60 minutos, este catalogo la rompe de inmediato.
INSERT INTO services
  (id, business_id, name, description, duration_minutes, buffer_minutes,
   price, color, sort_order, is_active)
VALUES
  (1, 1, 'Masaje relajante',
      'Masaje sueco de cuerpo completo con aceites esenciales. Ideal para descargar tension acumulada.',
      60, 15, 850.00,  '#0d6efd', 10, 1),
  (2, 1, 'Masaje descontracturante',
      'Trabajo profundo sobre zonas especificas: espalda alta, cuello y hombros. Presion firme.',
      90, 15, 1250.00, '#6610f2', 20, 1),
  (3, 1, 'Facial hidratante',
      'Limpieza profunda, exfoliacion y mascarilla de acido hialuronico.',
      45, 10, 650.00,  '#20c997', 30, 1),
  (4, 1, 'Manicura spa',
      'Manicura completa con exfoliacion, masaje de manos y esmaltado.',
      30,  5, 350.00,  '#fd7e14', 40, 1),
  (5, 1, 'Ritual de piedras calientes',
      'Terapia con piedras volcanicas de basalto. Requiere preparacion previa de la cabina.',
      75, 20, 1450.00, '#d63384', 50, 1),
  (6, 1, 'Reflexologia podal',
      'Servicio descontinuado. Se conserva inactivo para no romper el historial de citas.',
      45, 10, 550.00,  '#6c757d', 60, 0);   -- <- is_active = 0: soft delete

-- =====================================================================
--  EMPLEADOS
-- =====================================================================
INSERT INTO employees (id, business_id, name, email, phone, role_title, is_active)
VALUES
  (1, 1, 'Valeria Ortiz',  'valeria@spa-aurora.test',  '+52 55 2000 1001', 'Terapeuta senior', 1),
  (2, 1, 'Daniela Ruiz',   'daniela@spa-aurora.test',  '+52 55 2000 1002', 'Cosmetologa',      1),
  (3, 1, 'Marco Salinas',  'marco@spa-aurora.test',    '+52 55 2000 1003', 'Terapeuta',        1);

-- Que puede hacer cada quien. Sin fila aqui, el servicio no se le ofrece.
INSERT INTO employee_service (employee_id, service_id) VALUES
  (1, 1), (1, 2), (1, 5),      -- Valeria: masajes y piedras calientes
  (2, 3), (2, 4),              -- Daniela: facial y manicura
  (3, 1), (3, 2), (3, 3);      -- Marco: masajes y facial

-- =====================================================================
--  HORARIOS DEL NEGOCIO  (hora LOCAL, 1=Lunes .. 7=Domingo)
-- =====================================================================
-- Jornada partida de lunes a viernes: dos filas por dia, no un truco de
-- "cerrar a las 14 y reabrir" metido en el codigo.
INSERT INTO business_hours (business_id, weekday, opens_at, closes_at) VALUES
  (1, 1, '09:00:00', '14:00:00'), (1, 1, '16:00:00', '19:00:00'),
  (1, 2, '09:00:00', '14:00:00'), (1, 2, '16:00:00', '19:00:00'),
  (1, 3, '09:00:00', '14:00:00'), (1, 3, '16:00:00', '19:00:00'),
  (1, 4, '09:00:00', '14:00:00'), (1, 4, '16:00:00', '19:00:00'),
  (1, 5, '09:00:00', '14:00:00'), (1, 5, '16:00:00', '19:00:00'),
  (1, 6, '10:00:00', '14:00:00');
  -- Domingo (7): sin filas = cerrado

-- =====================================================================
--  HORARIOS POR EMPLEADO
-- =====================================================================
-- Valeria NO tiene filas: hereda el horario completo del negocio.
-- Daniela y Marco si las tienen: su disponibilidad es la INTERSECCION
-- de su horario con el del negocio.

-- Daniela solo trabaja turno de tarde entre semana, y sabado por la manana.
INSERT INTO employee_hours (employee_id, weekday, starts_at, ends_at) VALUES
  (2, 1, '16:00:00', '19:00:00'),
  (2, 2, '16:00:00', '19:00:00'),
  (2, 3, '16:00:00', '19:00:00'),
  (2, 4, '16:00:00', '19:00:00'),
  (2, 5, '16:00:00', '19:00:00'),
  (2, 6, '10:00:00', '14:00:00');

-- Marco solo matutino de lunes a viernes. No trabaja sabados.
INSERT INTO employee_hours (employee_id, weekday, starts_at, ends_at) VALUES
  (3, 1, '09:00:00', '14:00:00'),
  (3, 2, '09:00:00', '14:00:00'),
  (3, 3, '09:00:00', '14:00:00'),
  (3, 4, '09:00:00', '14:00:00'),
  (3, 5, '09:00:00', '14:00:00');

-- =====================================================================
--  EXCEPCIONES DE CALENDARIO
-- =====================================================================
INSERT INTO schedule_exceptions
  (business_id, employee_id, exc_date, is_closed, starts_at, ends_at, reason)
VALUES
  -- Todo el negocio cerrado un jueves: capacitacion interna
  (1, NULL, DATE_ADD(@lunes, INTERVAL 3 DAY), 1, NULL, NULL,
   'Capacitacion interna: cerrado todo el dia'),

  -- Viernes con horario recortado (no cerrado): solo turno matutino
  (1, NULL, DATE_ADD(@lunes, INTERVAL 4 DAY), 0, '09:00:00', '13:00:00',
   'Horario reducido por mantenimiento'),

  -- Marco de vacaciones el martes y miercoles de la semana siguiente
  (1, 3, DATE_ADD(@lunes, INTERVAL 8 DAY), 1, NULL, NULL, 'Vacaciones'),
  (1, 3, DATE_ADD(@lunes, INTERVAL 9 DAY), 1, NULL, NULL, 'Vacaciones');

-- =====================================================================
--  CLIENTES
-- =====================================================================
INSERT INTO customers (id, business_id, name, email, phone, notes) VALUES
  (1, 1, 'Ana Beltran',      'ana.beltran@example.com',   '+52 55 3100 2201',
      'Prefiere presion media. Alergica a aceite de almendras.'),
  (2, 1, 'Luis Cabrera',     'luis.cabrera@example.com',  '+52 55 3100 2202', NULL),
  (3, 1, 'Mariana Delgado',  'mariana.d@example.com',     '+52 55 3100 2203',
      'Cliente frecuente desde 2024.'),
  (4, 1, 'Jorge Estrada',    'jorge.estrada@example.com', '+52 55 3100 2204', NULL),
  (5, 1, 'Paola Fuentes',    'paola.fuentes@example.com', '+52 55 3100 2205',
      'Cancelo dos veces con poca anticipacion.');

-- =====================================================================
--  CITAS
-- =====================================================================
-- blocked_until NO se inserta: es columna generada (ends_at + buffer).
-- price / duration_minutes / buffer_minutes son SNAPSHOTS del servicio.
--
-- Formula de cada horario:
--    TIMESTAMP(<fecha>, '<hora local>') + INTERVAL @tz HOUR
--
-- Los public_token de la demo son MD5 deterministas para que el seed sea
-- reproducible. En produccion los genera bin2hex(random_bytes(16)) y
-- scripts/reset-demo.php los regenera al azar tras cada recarga.

INSERT INTO appointments
  (id, business_id, customer_id, service_id, employee_id,
   starts_at, ends_at, duration_minutes, buffer_minutes, price,
   status, source, customer_notes, public_token,
   cancelled_at, cancelled_by, cancel_reason)
VALUES
  -- ---------- FUTURO: proximo lunes ----------
  (1, 1, 1, 1, 1,
   TIMESTAMP(@lunes,'10:00:00') + INTERVAL @tz HOUR,
   TIMESTAMP(@lunes,'11:00:00') + INTERVAL @tz HOUR,
   60, 15,  850.00, 'confirmed', 'public',
   'Por favor presion media, no fuerte.', MD5('seed-appt-1'), NULL, NULL, NULL),

  (2, 1, 2, 2, 1,
   TIMESTAMP(@lunes,'12:00:00') + INTERVAL @tz HOUR,
   TIMESTAMP(@lunes,'13:30:00') + INTERVAL @tz HOUR,
   90, 15, 1250.00, 'confirmed', 'public',
   NULL, MD5('seed-appt-2'), NULL, NULL, NULL),

  (3, 1, 3, 1, 3,
   TIMESTAMP(@lunes,'09:00:00') + INTERVAL @tz HOUR,
   TIMESTAMP(@lunes,'10:00:00') + INTERVAL @tz HOUR,
   60, 15,  850.00, 'confirmed', 'admin',
   NULL, MD5('seed-appt-3'), NULL, NULL, NULL),

  (4, 1, 4, 3, 2,
   TIMESTAMP(@lunes,'16:30:00') + INTERVAL @tz HOUR,
   TIMESTAMP(@lunes,'17:15:00') + INTERVAL @tz HOUR,
   45, 10,  650.00, 'confirmed', 'public',
   NULL, MD5('seed-appt-4'), NULL, NULL, NULL),

  -- Esta cita empieza a las 18:00 LOCAL: en UTC cae al dia siguiente (00:00).
  -- Es el caso que rompe cualquier consulta que asuma "un dia = una fecha".
  (5, 1, 5, 4, 2,
   TIMESTAMP(@lunes,'18:00:00') + INTERVAL @tz HOUR,
   TIMESTAMP(@lunes,'18:30:00') + INTERVAL @tz HOUR,
   30,  5,  350.00, 'confirmed', 'public',
   NULL, MD5('seed-appt-5'), NULL, NULL, NULL),

  -- ---------- FUTURO: martes ----------
  -- Estado pending: sirve para probar la pantalla de aprobacion aunque
  -- el negocio tenga auto_confirm = 1.
  (6, 1, 1, 5, 1,
   TIMESTAMP(DATE_ADD(@lunes, INTERVAL 1 DAY),'10:00:00') + INTERVAL @tz HOUR,
   TIMESTAMP(DATE_ADD(@lunes, INTERVAL 1 DAY),'11:15:00') + INTERVAL @tz HOUR,
   75, 20, 1450.00, 'pending', 'public',
   'Es mi primera vez, agradeceria explicacion del procedimiento.',
   MD5('seed-appt-6'), NULL, NULL, NULL),

  -- ---------- FUTURO: miercoles ----------
  (7, 1, 2, 3, 3,
   TIMESTAMP(DATE_ADD(@lunes, INTERVAL 2 DAY),'11:00:00') + INTERVAL @tz HOUR,
   TIMESTAMP(DATE_ADD(@lunes, INTERVAL 2 DAY),'11:45:00') + INTERVAL @tz HOUR,
   45, 10,  650.00, 'confirmed', 'public',
   NULL, MD5('seed-appt-7'), NULL, NULL, NULL),

  -- ---------- FUTURO: sabado (horario reducido del negocio) ----------
  (8, 1, 3, 1, 1,
   TIMESTAMP(DATE_ADD(@lunes, INTERVAL 5 DAY),'11:00:00') + INTERVAL @tz HOUR,
   TIMESTAMP(DATE_ADD(@lunes, INTERVAL 5 DAY),'12:00:00') + INTERVAL @tz HOUR,
   60, 15,  850.00, 'confirmed', 'public',
   NULL, MD5('seed-appt-8'), NULL, NULL, NULL),

  -- ---------- PASADO: para poblar reportes e historial ----------
  (9, 1, 1, 1, 1,
   TIMESTAMP(@pasado,'10:00:00') + INTERVAL @tz HOUR,
   TIMESTAMP(@pasado,'11:00:00') + INTERVAL @tz HOUR,
   60, 15,  850.00, 'completed', 'public',
   NULL, MD5('seed-appt-9'), NULL, NULL, NULL),

  (10, 1, 4, 2, 3,
   TIMESTAMP(@pasado,'09:00:00') + INTERVAL @tz HOUR,
   TIMESTAMP(@pasado,'10:30:00') + INTERVAL @tz HOUR,
   90, 15, 1250.00, 'completed', 'admin',
   NULL, MD5('seed-appt-10'), NULL, NULL, NULL),

  -- No-show: el cliente no llego. Ocupo la agenda igual.
  (11, 1, 5, 4, 2,
   TIMESTAMP(DATE_ADD(@pasado, INTERVAL 2 DAY),'16:00:00') + INTERVAL @tz HOUR,
   TIMESTAMP(DATE_ADD(@pasado, INTERVAL 2 DAY),'16:30:00') + INTERVAL @tz HOUR,
   30,  5,  350.00, 'no_show', 'public',
   NULL, MD5('seed-appt-11'), NULL, NULL, NULL),

  -- Cancelada: NO debe reservar slots. Ver el INSERT de appointment_slots.
  (12, 1, 5, 2, 1,
   TIMESTAMP(DATE_ADD(@pasado, INTERVAL 3 DAY),'12:00:00') + INTERVAL @tz HOUR,
   TIMESTAMP(DATE_ADD(@pasado, INTERVAL 3 DAY),'13:30:00') + INTERVAL @tz HOUR,
   90, 15, 1250.00, 'cancelled', 'public',
   NULL, MD5('seed-appt-12'),
   TIMESTAMP(DATE_ADD(@pasado, INTERVAL 2 DAY),'19:12:00') + INTERVAL @tz HOUR,
   'customer', 'Me surgio un imprevisto de trabajo');

-- =====================================================================
--  SLOTS OCUPADOS  (derivados, nunca escritos a mano)
-- =====================================================================
-- Se genera una fila por cada bloque de 5 minutos entre starts_at y
-- blocked_until. Las canceladas se excluyen: su horario vuelve a estar
-- libre, que es justamente el efecto de cancelar.
--
-- La serie de minutos se genera con una subconsulta en linea, no con un
-- CTE recursivo ni con una tabla temporal:
--
--   * los CTE recursivos exigen MySQL 8.0 / MariaDB 10.2;
--   * CREATE TEMPORARY TABLE exige el privilegio CREATE TEMPORARY TABLES,
--     que los hosting compartidos no conceden. InfinityFree responde
--     "#1044 Acceso denegado" y la importacion muere a media faena,
--     dejando el esquema creado y los datos incompletos.
--
-- Una tabla derivada no necesita privilegio alguno y funciona en todo lo
-- que entienda SQL-92. Verificado importando el archivo entero.

INSERT INTO appointment_slots (employee_id, slot_at, appointment_id)
SELECT a.employee_id,
       a.starts_at + INTERVAL s.n MINUTE,
       a.id
FROM appointments a
JOIN (
    -- 0, 5, 10, ... 295 minutos. De sobra para el servicio mas largo del
    -- catalogo (90 de duracion + 15 de buffer = 105).
    SELECT (d.i * 10 + u.i) * 5 AS n
    FROM (SELECT 0 AS i UNION ALL SELECT 1 UNION ALL SELECT 2
          UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5) AS d
    CROSS JOIN
         (SELECT 0 AS i UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3
          UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6
          UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) AS u
) AS s
  ON s.n < TIMESTAMPDIFF(MINUTE, a.starts_at, a.blocked_until)
WHERE a.status <> 'cancelled';

-- =====================================================================
--  RECORDATORIOS
-- =====================================================================
-- La confirmacion ya se envio en todas las citas creadas.
INSERT INTO appointment_reminders
  (appointment_id, kind, channel, scheduled_for, status, attempts, sent_at)
SELECT a.id, 'confirmation', 'email', a.created_at, 'sent', 1, a.created_at
FROM appointments a;

-- El recordatorio de 24h queda PENDIENTE para las citas futuras vivas.
-- Esto es exactamente lo que levantara scripts/send-reminders.php.
INSERT INTO appointment_reminders
  (appointment_id, kind, channel, scheduled_for, status, attempts)
SELECT a.id, 'reminder_24h', 'email',
       a.starts_at - INTERVAL 24 HOUR, 'pending', 0
FROM appointments a
WHERE a.status IN ('pending','confirmed')
  AND a.starts_at > UTC_TIMESTAMP();

-- Aviso de cancelacion de la cita 12: ya se envio.
INSERT INTO appointment_reminders
  (appointment_id, kind, channel, scheduled_for, status, attempts, sent_at)
SELECT a.id, 'cancellation', 'email', a.cancelled_at, 'sent', 1, a.cancelled_at
FROM appointments a
WHERE a.id = 12;

-- Un fallo de envio, para que la pantalla de diagnostico tenga algo que mostrar.
UPDATE appointment_reminders
SET status = 'failed',
    attempts = 3,
    last_error = 'SMTP connect() failed: Connection timed out (Mailtrap)'
WHERE appointment_id = 8 AND kind = 'reminder_24h';

-- =====================================================================
--  AUDITORIA
-- =====================================================================
INSERT INTO appointment_status_log
  (appointment_id, from_status, to_status, changed_by_user_id, note, created_at)
VALUES
  (9,  'confirmed', 'completed', 2, 'Servicio realizado sin novedad',
       TIMESTAMP(@pasado,'11:05:00') + INTERVAL @tz HOUR),
  (10, 'confirmed', 'completed', 2, NULL,
       TIMESTAMP(@pasado,'10:35:00') + INTERVAL @tz HOUR),
  (11, 'confirmed', 'no_show',   2, 'Se esperaron 20 minutos, no contesto el telefono',
       TIMESTAMP(DATE_ADD(@pasado, INTERVAL 2 DAY),'16:25:00') + INTERVAL @tz HOUR),
  -- changed_by_user_id NULL = lo hizo el cliente desde su link publico
  (12, 'confirmed', 'cancelled', NULL, 'Cancelacion del cliente via token publico',
       TIMESTAMP(DATE_ADD(@pasado, INTERVAL 2 DAY),'19:12:00') + INTERVAL @tz HOUR);

-- =====================================================================
--  VERIFICACION RAPIDA
-- =====================================================================
-- Descomenta para comprobar que el seed cargo coherente.
--
-- SELECT 'citas' AS tabla, COUNT(*) AS n FROM appointments
-- UNION ALL SELECT 'slots ocupados', COUNT(*) FROM appointment_slots
-- UNION ALL SELECT 'recordatorios pendientes', COUNT(*)
--    FROM appointment_reminders WHERE status = 'pending';
--
-- -- Ninguna cita cancelada debe conservar slots (debe devolver 0 filas):
-- SELECT a.id FROM appointments a
-- JOIN appointment_slots s ON s.appointment_id = a.id
-- WHERE a.status = 'cancelled';
--
-- -- Agenda del proximo lunes en hora local:
-- SELECT a.id, e.name AS empleado, sv.name AS servicio,
--        CONVERT_TZ(a.starts_at, '+00:00', '-06:00') AS inicio_local,
--        a.status
-- FROM appointments a
-- JOIN employees e ON e.id = a.employee_id
-- JOIN services  sv ON sv.id = a.service_id
-- ORDER BY a.starts_at;
