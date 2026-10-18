<?php
/** Exact plugin identities and explicit file removal for Commander. */
namespace MarrisonCustomUpdater\MaintenanceClient;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Plugin_Security {

    public static function metadata( $file, array $data ) {
        $path = WP_PLUGIN_DIR . '/' . $file;
        $entry = array(
            'file' => (string) $file,
            'name' => sanitize_text_field( isset( $data['Name'] ) ? $data['Name'] : $file ),
            'current_version' => sanitize_text_field( isset( $data['Version'] ) ? $data['Version'] : '' ),
            'author' => sanitize_text_field( isset( $data['AuthorName'] ) ? $data['AuthorName'] : ( isset( $data['Author'] ) ? wp_strip_all_tags( $data['Author'] ) : '' ) ),
            'description' => sanitize_text_field( isset( $data['Description'] ) ? $data['Description'] : '' ),
            'plugin_uri' => sanitize_text_field( isset( $data['PluginURI'] ) ? $data['PluginURI'] : '' ),
            'author_uri' => sanitize_text_field( isset( $data['AuthorURI'] ) ? $data['AuthorURI'] : '' ),
            'main_file_sha256' => is_file( $path ) && is_readable( $path ) ? (string) hash_file( 'sha256', $path ) : '',
        );
        $json = wp_json_encode( $entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        $entry['review_fingerprint'] = is_string( $json ) ? hash( 'sha256', $json ) : '';
        $entry['security_metadata_version'] = 1;
        return $entry;
    }

    private static function failure( $code, $message ) {
        return array( 'success' => false, 'error_code' => $code, 'message' => $message );
    }

    /** Called only from the authenticated action runner under the shared MCU update lock. */
    public static function remove( array $parameters ) {
        if ( is_multisite() ) { return self::failure( 'multisite_not_supported', 'Rimozione remota non disponibile su WordPress multisito.' ); }
        if ( ! wp_is_file_mod_allowed( 'mcu_remove_plugin' ) ) { return self::failure( 'file_modifications_disabled', 'Le modifiche ai file sono disabilitate sul sito.' ); }
        $file = isset( $parameters['plugin_file'] ) && is_string( $parameters['plugin_file'] ) ? $parameters['plugin_file'] : '';
        $expected = isset( $parameters['expected_fingerprint'] ) && is_string( $parameters['expected_fingerprint'] ) ? $parameters['expected_fingerprint'] : '';
        if ( ! preg_match( '#^[a-zA-Z0-9_.-]+(?:/[a-zA-Z0-9_.-]+)*\.php$#D', $file ) || in_array( '..', explode( '/', $file ), true )
            || ! preg_match( '/^[a-f0-9]{64}$/D', $expected ) ) {
            return self::failure( 'invalid_plugin_identity', 'Identita del plugin non valida.' );
        }
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        wp_clean_plugins_cache( false );
        $plugins = get_plugins();
        if ( ! isset( $plugins[ $file ] ) ) { return self::failure( 'plugin_not_installed', 'Plugin non presente: aggiorna l’inventario in Commander.' ); }
        $identity = self::metadata( $file, $plugins[ $file ] );
        if ( ! hash_equals( $identity['review_fingerprint'], $expected ) ) { return self::failure( 'plugin_identity_changed', 'Plugin cambiato dopo l’analisi: verifica e analizza nuovamente prima di rimuoverlo.' ); }

        $root = realpath( WP_PLUGIN_DIR );
        $relative = false === strpos( $file, '/' ) ? $file : explode( '/', $file )[0];
        $candidate = WP_PLUGIN_DIR . '/' . $relative;
        $target = realpath( $candidate );
        $main = realpath( WP_PLUGIN_DIR . '/' . $file );
        $mcu = realpath( MCU_PLUGIN_DIR );
        $normalize = static function ( $value ) { return rtrim( str_replace( '\\', '/', (string) $value ), '/' ); };
        $root = $normalize( $root ); $target = $normalize( $target ); $main = $normalize( $main ); $mcu = $normalize( $mcu );
        if ( '' === $root || '' === $target || '' === $main || '' === $mcu || $target === $root
            || 0 !== strpos( $target, $root . '/' ) || 0 !== strpos( $main, $root . '/' )
            || ( $main !== $target && 0 !== strpos( $main, $target . '/' ) ) || is_link( $candidate )
            || $target === $mcu || 0 === strpos( $mcu, $target . '/' ) || 0 === strpos( $target, $mcu . '/' ) ) {
            return self::failure( 'protected_plugin_path', 'Percorso non consentito o plugin MCU protetto.' );
        }
        $slug = false === strpos( $file, '/' ) ? basename( $file, '.php' ) : $relative;
        foreach ( $plugins as $other_file => $data ) {
            if ( $other_file === $file ) { continue; }
            if ( is_dir( $target ) && 0 === strpos( $other_file, $relative . '/' ) ) {
                return self::failure( 'shared_plugin_directory', 'La cartella contiene altri plugin: rimozione automatica bloccata.' );
            }
            $requires = array_map( 'trim', explode( ',', isset( $data['RequiresPlugins'] ) ? $data['RequiresPlugins'] : '' ) );
            if ( in_array( $slug, $requires, true ) ) {
                return self::failure( 'plugin_required', 'Un altro plugin installato dichiara questa dipendenza. Rimozione bloccata.' );
            }
        }
        // WP_Filesystem_Direct recursively removes directories: reject links before touching anything.
        try {
            if ( is_dir( $target ) ) {
                $entries = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $target, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::SELF_FIRST );
                $count = 0;
                foreach ( $entries as $entry ) {
                    $resolved = $normalize( $entry->getRealPath() );
                    if ( ++$count > 100000 || $entry->isLink() || 0 !== strpos( $resolved, $target . '/' ) ) {
                        return self::failure( 'unsafe_plugin_tree', 'Cartella plugin non verificabile o contenente link simbolici. Rimozione bloccata.' );
                    }
                }
            }
        } catch ( \Throwable $exception ) { return self::failure( 'plugin_tree_unreadable', 'Impossibile verificare tutti i percorsi del plugin.' ); }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        if ( 'direct' !== get_filesystem_method( array(), $target ) || ! WP_Filesystem( false, $target ) ) {
            return self::failure( 'filesystem_unavailable', 'Accesso diretto al filesystem non disponibile. Nessun file rimosso.' );
        }
        global $wp_filesystem;
        // No deactivation hooks and no uninstall.php from a potentially infected plugin.
        deactivate_plugins( $file, true );
        if ( is_plugin_active( $file ) ) { return self::failure( 'deactivation_failed', 'Disattivazione non riuscita. Nessun file rimosso.' ); }
        $deleted = $wp_filesystem->delete( $target, true );
        clearstatcache();
        wp_clean_plugins_cache( false );
        $remaining = get_plugins();
        if ( ! $deleted || file_exists( $target ) || is_link( $candidate ) || isset( $remaining[ $file ] ) ) {
            return self::failure( 'plugin_removal_not_verified', 'Rimozione non verificata o incompleta. Il plugin puo essere gia disattivato: controlla il sito.' );
        }
        return array( 'success' => true, 'removed' => true, 'plugin_file' => $file,
            'expected_fingerprint' => $expected, 'message' => 'File del plugin rimossi e assenza verificata. Dati e opzioni del plugin conservati.' );
    }
}
