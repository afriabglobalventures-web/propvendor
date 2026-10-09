<?php
/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the installation.
 * You don't have to use the website, you can copy this file to "wp-config.php"
 * and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * Database settings
 * * Secret keys
 * * Database table prefix
 * * ABSPATH
 *
 * @link https://developer.wordpress.org/advanced-administration/wordpress/wp-config/
 *
 * @package WordPress
 */

// ** Database settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define( 'DB_NAME', 'Propvendor' );

/** Database username */
define( 'DB_USER', 'root' );

/** Database password */
define( 'DB_PASSWORD', '' );

/** Database hostname */
define( 'DB_HOST', 'localhost' );

/** Database charset to use in creating database tables. */
define( 'DB_CHARSET', 'utf8mb4' );

/** The database collate type. Don't change this if in doubt. */
define( 'DB_COLLATE', '' );

/**#@+
 * Authentication unique keys and salts.
 *
 * Change these to different unique phrases! You can generate these using
 * the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}.
 *
 * You can change these at any point in time to invalidate all existing cookies.
 * This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define( 'AUTH_KEY',         'j[PiOY< .i_,y_1BY!r.LW-aa[c9lS;DkGP8|h=]kD$qE)@La9)<QdI!N?(N1{7B' );
define( 'SECURE_AUTH_KEY',  'lXt1wj_y2ZEw55N`E~qMv/k# `Ssy1[@KKn$]Vk^6tbYnLdfbzS%W+,@O]#o*Gz8' );
define( 'LOGGED_IN_KEY',    ',$>?fbYkv3;7>=S}&Z;6e++aHRL] S8EehQq4& G&K!/mygNcN7%zG<Mz3{UZy-q' );
define( 'NONCE_KEY',        'DE&S)PaJ[8KqMel@PTg%J?GD3lSe;w1?5yRw%hl-v( L^A[1_dQ2+Qj5tOU7tMh,' );
define( 'AUTH_SALT',        'S<k*iYZ5(pvTvo<uMCPhUV!&0:xr3 [0Y-b1$w,#OT(TBlP*6:q#LGom=N5L27Hw' );
define( 'SECURE_AUTH_SALT', 'Gk(TdRvauHOb1ByS}R%K.AE%n!:G:tsWLjUbe5E.WowL(2g5 ~3K5&zk=5e#K|:Z' );
define( 'LOGGED_IN_SALT',   '49Og8tX)e,U,q;b}x_wJ_MsNN?8R7uxyqKLj4Z@0civkqi4`4CWCp]5D)^;FzrcJ' );
define( 'NONCE_SALT',       'ZM5fZ=}BN7eF?uh:b{WuX6>bx?$S8-/q0*QDn3%9]jypd*[sVFX_qD>DH,%oV=C}' );

/**#@-*/

/**
 * WordPress database table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 *
 * At the installation time, database tables are created with the specified prefix.
 * Changing this value after WordPress is installed will make your site think
 * it has not been installed.
 *
 * @link https://developer.wordpress.org/advanced-administration/wordpress/wp-config/#table-prefix
 */
$table_prefix = 'wp_';

/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the documentation.
 *
 * @link https://developer.wordpress.org/advanced-administration/debug/debug-wordpress/
 */
define( 'WP_DEBUG', false );

/* Add any custom values between this line and the "stop editing" line. */



/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';
