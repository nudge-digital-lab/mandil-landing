<?php
/**
 * Mandil Construcciones: recibe las consultas de la landing y las manda por mail.
 *
 * Lo llaman el popup y el chat (fetch POST, application/x-www-form-urlencoded).
 * Responde JSON: {"ok":true} o {"ok":false,"error":"..."}.
 * Envío por SMTP autenticado con una casilla del dominio (Hostinger:
 * smtp.hostinger.com, 465 SSL), con un cliente SMTP propio, sin librerías.
 * Anti-spam: campo trampa «web» (tiene que venir vacío), tiempo mínimo desde que
 * se abrió el formulario (campo «t», en milisegundos) y tope de envíos por IP.
 * Si este archivo no corre o falla (vista previa sin PHP, SMTP caído), el JS
 * ofrece seguir por WhatsApp o abrir el mail con mailto:.
 */

// ---- Configuración ----------------------------------------------------------
// Los datos de la casilla (SMTP) NO van acá: van en mandil-config.php, un nivel
// arriba de public_html (fuera de la web). Modelo: config.ejemplo.php.
// Si no está afuera, se busca config.php en esta carpeta (protegido por .htaccess).
const MIN_MS  = 2500;   // menos que esto desde que se abrió el formulario = robot
const TOPE    = 6;      // envíos por IP...
const VENTANA = 900;    // ...cada 15 minutos
// -----------------------------------------------------------------------------

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function responder($ok, $error = '', $codigo = 200) {
	http_response_code($codigo);
	echo json_encode($ok ? array('ok' => true) : array('ok' => false, 'error' => $error), JSON_UNESCAPED_UNICODE);
	exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') responder(false, 'Método no permitido', 405);

$CFG = null;
foreach (array(dirname(__DIR__) . '/mandil-config.php', __DIR__ . '/config.php') as $f) {
	if (is_readable($f)) { $CFG = require $f; break; }
}
if (!is_array($CFG) || empty($CFG['smtp_host']) || empty($CFG['smtp_usuario']) || empty($CFG['smtp_clave']) || empty($CFG['destino'])) {
	responder(false, 'El envío por mail todavía no está configurado. Escribinos por WhatsApp.', 503);
}

// Solo desde la propia web (si el navegador manda Origin).
$origen = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origen !== '') {
	$host = parse_url($origen, PHP_URL_HOST) ?: '';
	$propio = preg_replace(array('/^www\./', '/:\d+$/'), '', strtolower($_SERVER['HTTP_HOST'] ?? ''));
	if (preg_replace('/^www\./', '', strtolower($host)) !== $propio) responder(false, 'Origen no permitido', 403);
}

// Texto de una línea: sin saltos ni caracteres de control (evita inyectar cabeceras).
function linea($k, $max) {
	$v = isset($_POST[$k]) && is_string($_POST[$k]) ? $_POST[$k] : '';
	$v = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v);
	$v = trim(preg_replace('/\s+/u', ' ', $v));
	return mb_substr($v, 0, $max, 'UTF-8');
}
// Texto largo: conserva los saltos de línea, nada más.
function texto($k, $max) {
	$v = isset($_POST[$k]) && is_string($_POST[$k]) ? $_POST[$k] : '';
	$v = str_replace(array("\r\n", "\r"), "\n", $v);
	$v = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]+/u', '', $v);
	return trim(mb_substr($v, 0, $max, 'UTF-8'));
}

// Trampas para robots: se responde "ok" para no darles pistas.
if (linea('web', 200) !== '') responder(true);
$t = (int) ($_POST['t'] ?? 0);
if ($t > 0 && $t < MIN_MS) responder(true);

$nombre   = linea('nombre', 80);
$telefono = linea('telefono', 40);
$email    = linea('email', 120);
$trabajo  = linea('trabajo', 120);
$zona     = linea('zona', 60);
$espacio  = linea('espacio', 60);
$plazo    = linea('plazo', 60);
$cuotas   = linea('cuotas', 60);
$desde    = linea('origen', 80);
$canal    = linea('canal', 20) === 'whatsapp' ? 'whatsapp' : 'mail';
$mensaje  = texto('mensaje', 1500);

if ($nombre === '') responder(false, 'Falta el nombre', 422);
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) responder(false, 'El mail no es válido', 422);
if ($telefono === '' && $email === '') responder(false, 'Dejanos un WhatsApp o un mail para responderte', 422);
if ($telefono !== '' && !preg_match('/^[0-9+()\s.\-]{6,40}$/', $telefono)) responder(false, 'El teléfono no es válido', 422);

// Tope por IP (archivo temporal, sin base de datos).
$ip = $_SERVER['REMOTE_ADDR'] ?? '0';
$archivo = sys_get_temp_dir() . '/mandil_form_' . hash('sha256', $ip . __DIR__);
$ahora = time();
$marcas = array();
if (is_readable($archivo)) {
	$marcas = array_filter(array_map('intval', explode(',', (string) @file_get_contents($archivo))), function ($m) use ($ahora) { return $m > $ahora - VENTANA; });
}
if (count($marcas) >= TOPE) responder(false, 'Recibimos varias consultas seguidas. Probá en unos minutos o escribinos por WhatsApp.', 429);
$marcas[] = $ahora;
@file_put_contents($archivo, implode(',', $marcas), LOCK_EX);

// Armado del mail.
$filas = array(
	'Nombre'   => $nombre,
	'WhatsApp' => $telefono,
	'Mail'     => $email,
	'Trabajo'  => $trabajo,
	'Zona'     => $zona,
	'Espacio'  => $espacio,
	'Para cuándo' => $plazo,
	'Cuotas'   => $cuotas,
	'Desde'    => $desde,
);
$cuerpo = $canal === 'whatsapp'
	? "Pedido de presupuesto desde la web. La persona eligió seguir por WhatsApp: si no te llegó el mensaje, escribile vos.\n\n"
	: "Pedido de presupuesto desde la web\n\n";
foreach ($filas as $k => $v) if ($v !== '') $cuerpo .= $k . ': ' . $v . "\n";
if ($mensaje !== '') $cuerpo .= "\nMensaje:\n" . $mensaje . "\n";
if ($telefono !== '') {
	$digitos = preg_replace('/\D+/', '', $telefono);
	if (strlen($digitos) >= 8) $cuerpo .= "\nResponder por WhatsApp: https://wa.me/" . (strpos($digitos, '54') === 0 ? $digitos : '549' . ltrim($digitos, '0')) . "\n";
}
$cuerpo .= "\n--\nEnviado desde mandilconstrucciones.com.ar\n";

$asunto = ($canal === 'whatsapp' ? '[Abrió WhatsApp] ' : '') . 'Pedido de presupuesto: ' . ($trabajo !== '' ? $trabajo : 'remodelación') . ' · ' . $nombre;
$err = smtp_enviar($CFG, $asunto, $cuerpo, $email, $nombre);
if ($err !== '') {
	error_log('mandil enviar.php SMTP: ' . $err);
	responder(false, 'No pudimos enviar el mail. Probá por WhatsApp.', 502);
}
responder(true);

/* ---- Cliente SMTP mínimo (SSL implícito en 465 o STARTTLS en 587, AUTH LOGIN) ---- */

function hdr_utf8($t) { return '=?UTF-8?B?' . base64_encode($t) . '?='; }

function smtp_enviar($c, $asunto, $cuerpo, $responder_a, $nombre_cliente) {
	$host = $c['smtp_host']; $puerto = (int) ($c['smtp_puerto'] ?? 465);
	$desde = $c['smtp_usuario']; $nombre = $c['remitente_nombre'] ?? 'Web';
	$destinos = array_filter(array_map('trim', explode(',', $c['destino'])));
	$ssl = ($c['smtp_seguridad'] ?? ($puerto === 465 ? 'ssl' : 'tls')) === 'ssl'; // ssl = SSL implícito; tls = STARTTLS
	$v = !isset($c['smtp_verificar']) || $c['smtp_verificar']; // false solo para pruebas locales
	$ctx = stream_context_create(array('ssl' => array('verify_peer' => $v, 'verify_peer_name' => $v, 'allow_self_signed' => !$v, 'SNI_enabled' => true)));
	$s = @stream_socket_client(($ssl ? 'ssl://' : 'tcp://') . $host . ':' . $puerto, $no, $txt, 15, STREAM_CLIENT_CONNECT, $ctx);
	if (!$s) return "conexión: $txt";
	stream_set_timeout($s, 15);
	$leer = function () use ($s) {
		$r = '';
		while (($l = fgets($s, 1024)) !== false) { $r .= $l; if (isset($l[3]) && $l[3] === ' ') break; if (strlen($l) < 4) break; }
		return $r;
	};
	$cmd = function ($linea, $esperado) use ($s, $leer) {
		if ($linea !== null) fwrite($s, $linea . "\r\n");
		$r = $leer();
		if ((int) substr($r, 0, 3) !== $esperado) return 'esperaba ' . $esperado . ', llegó: ' . trim(substr($r, 0, 200));
		return '';
	};
	$ehlo = preg_replace('/[^a-z0-9.\-]/i', '', $_SERVER['SERVER_NAME'] ?? 'localhost') ?: 'localhost';
	if ($e = $cmd(null, 220)) { fclose($s); return "saludo: $e"; }
	if ($e = $cmd('EHLO ' . $ehlo, 250)) { fclose($s); return "EHLO: $e"; }
	if (!$ssl) {
		if ($e = $cmd('STARTTLS', 220)) { fclose($s); return "STARTTLS: $e"; }
		if (!stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) { fclose($s); return 'TLS: no se pudo cifrar'; }
		if ($e = $cmd('EHLO ' . $ehlo, 250)) { fclose($s); return "EHLO TLS: $e"; }
	}
	if ($e = $cmd('AUTH LOGIN', 334)) { fclose($s); return "AUTH: $e"; }
	if ($e = $cmd(base64_encode($desde), 334)) { fclose($s); return "usuario: $e"; }
	if ($e = $cmd(base64_encode($c['smtp_clave']), 235)) { fclose($s); return 'clave rechazada'; }
	if ($e = $cmd('MAIL FROM:<' . $desde . '>', 250)) { fclose($s); return "MAIL FROM: $e"; }
	foreach ($destinos as $d) {
		if (!filter_var($d, FILTER_VALIDATE_EMAIL)) continue;
		if ($e = $cmd('RCPT TO:<' . $d . '>', 250)) { fclose($s); return "RCPT: $e"; }
	}
	if ($e = $cmd('DATA', 354)) { fclose($s); return "DATA: $e"; }

	$dominio = substr(strrchr($desde, '@'), 1);
	$h = array(
		'Date: ' . date('r'),
		'From: ' . hdr_utf8($nombre) . ' <' . $desde . '>',
		'To: ' . implode(', ', $destinos),
		'Subject: ' . hdr_utf8(mb_substr($asunto, 0, 150, 'UTF-8')),
		'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $dominio . '>',
		'MIME-Version: 1.0',
		'Content-Type: text/plain; charset=UTF-8',
		'Content-Transfer-Encoding: base64',
		'X-Mailer: mandil-web',
	);
	if ($responder_a !== '') $h[] = 'Reply-To: ' . hdr_utf8($nombre_cliente) . ' <' . $responder_a . '>';
	$datos = implode("\r\n", $h) . "\r\n\r\n" . rtrim(chunk_split(base64_encode($cuerpo), 76, "\r\n")) . "\r\n.";
	if ($e = $cmd($datos, 250)) { fclose($s); return "envío: $e"; }
	$cmd('QUIT', 221);
	fclose($s);
	return '';
}
