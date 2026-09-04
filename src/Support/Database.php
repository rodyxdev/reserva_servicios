<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Fabrica de la conexion PDO.
 *
 * Una sola conexion por proceso (patron singleton perezoso): abrir una
 * conexion nueva por cada consulta es de los errores mas caros en hosting
 * compartido, donde el limite de conexiones simultaneas es bajo.
 */
final class Database
{
    private static ?PDO $connection = null;

    /** @param array<string,mixed> $config Seccion 'db' de config/settings.php */
    public static function connect(array $config): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'],
            $config['port'],
            $config['name'],
            $config['charset'],
        );

        try {
            $pdo = new PDO($dsn, $config['user'], $config['pass'], [
                // Los errores de SQL lanzan excepciones en vez de devolver
                // false en silencio. Sin esto, un INSERT que falla parece
                // haber funcionado y el bug aparece tres pantallas despues.
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,

                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,

                // Prepared statements REALES, no emulados.
                //
                // Con emulacion (el valor por defecto de PHP), el driver
                // interpola los parametros en la cadena SQL antes de
                // mandarla al servidor. Funciona, pero la proteccion contra
                // inyeccion depende entonces del escapado del cliente, y en
                // combinaciones concretas de charset se ha demostrado
                // evadible. Con false, la sentencia y los datos viajan por
                // canales separados: el servidor nunca puede confundir un
                // valor con sintaxis.
                //
                // Efecto secundario util: los enteros llegan como enteros,
                // no como cadenas, y LIMIT ? acepta un parametro sin trucos.
                PDO::ATTR_EMULATE_PREPARES   => false,

                // Un statement reutilizado no arrastra el resultado anterior.
                PDO::ATTR_STRINGIFY_FETCHES  => false,

                // La sesion arranca en UTC.
                //
                // ---------------------------------------------------------
                //  POR QUE, SI schema.sql YA HACE "SET time_zone = '+00:00'"
                // ---------------------------------------------------------
                //  Porque aquello fue una variable de SESION, y la sesion
                //  murio cuando termino la importacion. time_zone tiene dos
                //  ambitos: GLOBAL (el del servidor) y SESSION (el de cada
                //  conexion). Fijar la de sesion en un script de importacion
                //  no cambia nada para las conexiones que vengan despues:
                //  cada conexion nueva hereda el valor GLOBAL del servidor,
                //  que en XAMPP es SYSTEM (la hora local de Windows) y en un
                //  hosting compartido puede ser cualquier cosa.
                //
                //  Sin esta linea, NOW() y los DEFAULT CURRENT_TIMESTAMP
                //  escribirian en hora local del servidor mientras el resto
                //  del sistema calcula en UTC. El sintoma seria brutal y
                //  dificil de rastrear: citas creadas con created_at
                //  desplazado, locked_until que expira antes o despues de lo
                //  debido, y recordatorios enviados con horas de desfase,
                //  todo dependiendo de en que maquina corra la aplicacion.
                //
                //  Se hace con INIT_COMMAND para que se ejecute tambien en
                //  las reconexiones automaticas del driver, no solo en la
                //  primera conexion.
                // ---------------------------------------------------------
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00', "
                    // sql_mode explicito: no se hereda lo que traiga el
                    // servidor. STRICT_ALL_TABLES convierte en error los
                    // truncamientos silenciosos de datos, que es justo lo
                    // que uno quiere en una tabla de citas.
                    . "sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,"
                    . "NO_ENGINE_SUBSTITUTION'",
            ]);
        } catch (PDOException $e) {
            // El mensaje original lleva usuario y host de la base. No debe
            // llegar nunca al navegador: se registra y se lanza uno neutro.
            error_log('[db] fallo de conexion: ' . $e->getMessage());

            throw new RuntimeException(
                'No se pudo conectar con la base de datos.',
                previous: $e,
            );
        }

        return self::$connection = $pdo;
    }

    /** Para los tests: permite inyectar una conexion propia (SQLite, mock). */
    public static function swap(?PDO $pdo): void
    {
        self::$connection = $pdo;
    }
}
