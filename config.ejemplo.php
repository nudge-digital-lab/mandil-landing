<?php
/**
 * Modelo de configuración del envío por mail (SMTP) de la landing.
 * Copialo como mandil-config.php UN NIVEL ARRIBA de public_html (fuera de la web)
 * y completá la clave en el servidor. Nunca lo subas al repo con la clave.
 * Casilla: crearla en Hostinger → Correos (por ejemplo web@mandilconstrucciones.com.ar).
 */
return array(
	'smtp_host'        => 'smtp.hostinger.com',
	'smtp_puerto'      => 465,                               // 465 con 'ssl'; 587 con 'tls'
	'smtp_seguridad'   => 'ssl',                             // 'ssl' (SSL implícito) o 'tls' (STARTTLS)
	'smtp_usuario'     => 'web@mandilconstrucciones.com.ar', // la casilla completa; también es el remitente
	'smtp_clave'       => '',                                // se completa en el servidor
	'remitente_nombre' => 'Web Mandil Construcciones',
	'destino'          => 'equipo.matias@gmail.com',         // a quién le llegan (varios: separados por coma)
);
