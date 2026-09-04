<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\View;
use PHPMailer\PHPMailer\Exception as MailerException;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use RuntimeException;

/**
 * El servidor rechazo el mensaje por exceso de tasa.
 *
 * Se distingue de un fallo normal porque es TRANSITORIO y culpa del ritmo,
 * no del mensaje: el mismo correo saldra bien dentro de unos segundos.
 *
 * Importa separarlo porque un rechazo asi NO debe gastar reintentos. Sin
 * esta distincion, tres recordatorios seguidos agotan los tres intentos de
 * uno de ellos contra el limite del servidor y acaba marcado como 'failed'
 * definitivo: un correo que nunca llega, por un problema que se habria
 * resuelto solo esperando.
 */
final class MailRateLimitException extends RuntimeException
{
}

/**
 * Envio de correo por SMTP.
 *
 * Envoltura fina sobre PHPMailer: recibe una plantilla y unos datos,
 * renderiza y envia. Toda la configuracion viene de .env, sin un solo
 * valor de servidor escrito en el codigo.
 *
 * Se instancia UN PHPMailer por envio en vez de reutilizarlo. Con
 * SMTPKeepAlive un unico objeto podria mandar varios correos por la misma
 * conexion, pero tambien arrastra el estado del envio anterior
 * (destinatarios, adjuntos, cabeceras) y basta olvidar un clearAddresses()
 * para que el recordatorio de un cliente acabe en el buzon de otro. Para el
 * volumen de un negocio de servicios, la conexion extra no se nota; el
 * correo cruzado, si.
 */
final class MailService
{
    /**
     * Instante del ultimo envio, para el regulador de tasa.
     *
     * Estatico a proposito: si el proceso crea mas de un MailService, el
     * servidor SMTP los sigue viendo como el mismo cliente. El limite es
     * suyo, no nuestro, asi que el contador tambien tiene que ser unico.
     */
    private static float $ultimoEnvio = 0.0;

    /** @param array<string,mixed> $config Seccion 'mail' de config/settings.php */
    public function __construct(
        private readonly array $config,
        private readonly View $view,
        private readonly string $logPath,
    ) {
    }

    /**
     * Espera lo necesario para no exceder la tasa del servidor.
     *
     * ------------------------------------------------------------------
     *  POR QUE EXISTE ESTO
     * ------------------------------------------------------------------
     *  Lo destapo una corrida real del cron contra Mailtrap: de tres
     *  recordatorios, el primero salio y los otros dos rebotaron con
     *
     *      550 5.7.0 Too many emails per second
     *
     *  No era un fallo del codigo (la cola los devolvio a 'pending' y los
     *  habria reintentado), pero si un problema de diseno: casi todos los
     *  SMTP compartidos y gratuitos limitan la tasa, y el flujo de reserva
     *  manda DOS correos seguidos (confirmacion al cliente y aviso al
     *  negocio). Sin regulador, el segundo rebota siempre.
     *
     *  Se espera en el CLIENTE en vez de confiar en el reintento porque
     *  reintentar cuesta una conexion SMTP entera; esperar cuesta
     *  milisegundos.
     * ------------------------------------------------------------------
     */
    private function throttle(): void
    {
        $intervalo = (int) ($this->config['throttle_ms'] ?? 0);

        if ($intervalo <= 0) {
            return;
        }

        if (self::$ultimoEnvio <= 0.0) {
            return;   // primer envio del proceso: no hay nada que esperar
        }

        // El instante de referencia es el FINAL del envio anterior, no su
        // principio. Marcarlo al principio (que fue el primer intento)
        // descuenta del intervalo el tiempo que tarda el propio SMTP en
        // conectar, negociar TLS y autenticar: el hueco real entre dos
        // mensajes acababa siendo mucho menor que el configurado, y el
        // servidor seguia rechazando por exceso de tasa.
        $transcurrido = (microtime(true) - self::$ultimoEnvio) * 1000;
        $restante     = $intervalo - $transcurrido;

        if ($restante > 0) {
            usleep((int) ($restante * 1000));
        }
    }

    /** Marca el final de un envio, para que el siguiente respete el intervalo. */
    private function marcarEnvio(): void
    {
        self::$ultimoEnvio = microtime(true);
    }

    /**
     * Reconoce un rechazo por exceso de tasa en el mensaje del servidor.
     *
     * No hay un codigo SMTP estandar para esto: 421, 450 y 550 se usan
     * indistintamente segun el proveedor, y 550 tambien significa
     * "buzon inexistente", que si es definitivo. Por eso se mira el TEXTO,
     * que es donde todos coinciden en decir de que se trata.
     *
     * Los patrones cubren Mailtrap, SendGrid, Gmail, Amazon SES y Postmark.
     * Ante la duda se devuelve false: tratar un fallo real como transitorio
     * dejaria el aviso reintentandose para siempre.
     */
    private static function esLimiteDeTasa(string $mensaje): bool
    {
        $patrones = [
            'too many emails',
            'rate limit',
            'rate exceeded',
            'throttl',            // "throttled", "throttling"
            'too many messages',
            'message rate',
            'sending quota',
            'try again later',
            '4.7.0',              // codigo mejorado tipico de limitacion temporal
        ];

        $mensaje = mb_strtolower($mensaje);

        foreach ($patrones as $patron) {
            if (str_contains($mensaje, $patron)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Renderiza una plantilla y la envia.
     *
     * @param  array<string,mixed> $data Variables de la plantilla
     * @throws RuntimeException si el envio falla
     */
    public function send(
        string $toEmail,
        string $toName,
        string $subject,
        string $template,
        array $data = [],
    ): void {
        $html = $this->view->render('emails/' . $template, $data, 'emails/layout');
        $text = $this->toPlainText($html);

        // Modo simulacion: permite desarrollar y ejecutar los tests sin
        // credenciales SMTP, y sin mandarle correo a nadie por accidente.
        if ($this->config['pretend']) {
            $this->logToFile($toEmail, $toName, $subject, $text);

            return;
        }

        $this->throttle();

        $mailer = $this->makeMailer();

        try {
            $mailer->addAddress($toEmail, $toName);
            $mailer->Subject = $subject;
            $mailer->isHTML(true);
            $mailer->Body    = $html;
            // Cuerpo alternativo en texto plano. Sin el, muchos filtros
            // antispam penalizan el mensaje por ser solo-HTML.
            $mailer->AltBody = $text;

            $mailer->send();

            // Se marca DESPUES de que el servidor acepte: es el punto desde
            // el que cuenta el intervalo del proximo envio.
            $this->marcarEnvio();
        } catch (MailerException $e) {
            // Tambien tras un fallo. Un rechazo por exceso de tasa consume
            // cupo igual que un envio bueno, asi que reintentar de
            // inmediato solo provoca otro rechazo.
            $this->marcarEnvio();

            // ErrorInfo trae el dialogo SMTP, que es lo unico util para
            // diagnosticar. getMessage() suele decir solo "SMTP Error".
            $detalle = $mailer->ErrorInfo ?: $e->getMessage();

            if (self::esLimiteDeTasa($detalle)) {
                throw new MailRateLimitException(
                    'El servidor de correo esta limitando el ritmo de envio: ' . $detalle,
                    previous: $e,
                );
            }

            throw new RuntimeException(
                'Fallo el envio a ' . $toEmail . ': ' . $detalle,
                previous: $e,
            );
        }
    }

    /**
     * Comprueba que se puede conectar y autenticar contra el SMTP.
     *
     * La usa scripts/send-reminders.php antes de procesar la cola: si el
     * servidor no responde, es mejor no tocar nada y salir, en vez de
     * marcar veinte recordatorios como fallidos uno por uno.
     *
     * @return array{ok:bool, message:string}
     */
    public function testConnection(): array
    {
        if ($this->config['pretend']) {
            return ['ok' => true, 'message' => 'Modo simulacion: no se conecta a ningun SMTP.'];
        }

        $mailer = $this->makeMailer();

        try {
            if (!$mailer->smtpConnect()) {
                return ['ok' => false, 'message' => $mailer->ErrorInfo ?: 'No se pudo conectar.'];
            }

            $mailer->smtpClose();

            return ['ok' => true, 'message' => sprintf(
                'Conectado y autenticado en %s:%d',
                $this->config['host'],
                $this->config['port'],
            )];
        } catch (MailerException $e) {
            return ['ok' => false, 'message' => $mailer->ErrorInfo ?: $e->getMessage()];
        }
    }

    private function makeMailer(): PHPMailer
    {
        // true = PHPMailer lanza excepciones en vez de devolver false.
        $mailer = new PHPMailer(true);

        $mailer->isSMTP();
        $mailer->Host       = (string) $this->config['host'];
        $mailer->Port       = (int) $this->config['port'];
        $mailer->CharSet    = PHPMailer::CHARSET_UTF8;
        $mailer->Encoding   = PHPMailer::ENCODING_BASE64;
        $mailer->Timeout    = 20;

        $user = (string) $this->config['username'];

        if ($user !== '') {
            $mailer->SMTPAuth = true;
            $mailer->Username = $user;
            $mailer->Password = (string) $this->config['password'];
        }

        $mailer->SMTPSecure = match (strtolower((string) $this->config['encryption'])) {
            'tls'   => PHPMailer::ENCRYPTION_STARTTLS,
            'ssl'   => PHPMailer::ENCRYPTION_SMTPS,
            default => '',
        };

        if ($mailer->SMTPSecure === '') {
            $mailer->SMTPAutoTLS = false;
        }

        $mailer->setFrom(
            (string) $this->config['from_address'],
            (string) $this->config['from_name'],
        );

        // Nivel de traza SMTP. Solo con la variable de entorno puesta:
        // deja el dialogo completo en la salida, incluida la linea de
        // autenticacion, asi que no debe activarse en produccion.
        if (getenv('MAIL_DEBUG') === '1') {
            $mailer->SMTPDebug   = SMTP::DEBUG_CONNECTION;
            $mailer->Debugoutput = 'error_log';
        }

        return $mailer;
    }

    /**
     * Convierte el HTML del correo a texto plano legible.
     *
     * strip_tags a secas dejaria los enlaces sin destino, y en un correo de
     * confirmacion el enlace de gestion ES el contenido importante: se
     * extrae y se escribe entre parentesis detras del texto del enlace.
     */
    private function toPlainText(string $html): string
    {
        $text = preg_replace(
            '#<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is',
            '$2 ($1)',
            $html,
        ) ?? $html;

        $text = preg_replace('#<(br|/p|/div|/tr|/h[1-6])\s*/?>#i', "\n", $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Colapsa la sangria de las plantillas sin perder los saltos que
        // separan parrafos.
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n[ \t]*/', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function logToFile(string $toEmail, string $toName, string $subject, string $body): void
    {
        $entry = sprintf(
            "\n%s\n[%s] PARA: %s <%s>\nASUNTO: %s\n%s\n%s\n",
            str_repeat('=', 72),
            gmdate('Y-m-d H:i:s') . ' UTC',
            $toName,
            $toEmail,
            $subject,
            str_repeat('-', 72),
            $body,
        );

        // FILE_APPEND | LOCK_EX: varios procesos (web y cron) pueden estar
        // escribiendo a la vez, y sin el bloqueo las entradas se entrelazan.
        @file_put_contents($this->logPath, $entry, FILE_APPEND | LOCK_EX);
    }
}
