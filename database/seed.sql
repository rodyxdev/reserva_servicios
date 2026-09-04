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
-- Se usa una tabla temporal de numeros en vez de un CTE recursivo por
-- compatibilidad: los CTE requieren MySQL 8.0 / MariaDB 10.2, y este seed
-- tiene que poder importarse tambien desde el phpMyAdmin de un hosting viejo.

DROP TEMPORARY TABLE IF EXISTS seq_min;
CREATE TEMPORARY TABLE seq_min (n SMALLINT UNSIGNED PRIMARY KEY);

INSERT INTO seq_min (n)
SELECT (d.i * 10 + u.i) * 5
FROM (SELECT 0 AS i UNION ALL SELECT 1 UNION ALL SELECT 2
      UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5) AS d
CROSS JOIN
     (SELECT 0 AS i UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3
      UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6
      UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) AS u;
-- Resultado: 0, 5, 10, ... 295 minutos. De sobra para el servicio mas largo
-- del catalogo (90 + 15 de buffer = 105).

INSERT INTO appointment_slots (employee_id, slot_at, appointment_id)
SELECT a.employee_id,
       a.starts_at + INTERVAL s.n MINUTE,
       a.id
FROM appointments a
JOIN seq_min s
  ON s.n < TIMESTAMPDIFF(MINUTE, a.starts_at, a.blocked_until)
WHERE a.status <> 'cancelled';

DROP TEMPORARY TABLE seq_min;

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
