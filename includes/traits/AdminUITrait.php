<?php
trait MCU_Admin_UI_Trait {
    public function render_header($title, $actions = null) {
        $logo_url = plugin_dir_url(__FILE__) . '../../assets/logo.svg';
        ?>
        <!-- Invisible H1 to catch WordPress notifications and prevent them from being injected into our custom header -->
        <h1 class="wp-heading-inline" style="display:none;"></h1>
        
        <div class="mmu-header">
            <div class="mmu-header-title">
                <div class="mmu-title-text"><?php echo esc_html($title); ?></div>
            </div>
            <?php if ($actions): ?>
                <div class="mmu-header-actions">
                    <?php echo $actions; ?>
                </div>
            <?php endif; ?>
            <div class="mmu-header-logo">
                <img src="<?php echo esc_url($logo_url); ?>" alt="Marrison Logo">
                <a href="https://marrisonlab.com" target="_blank" class="marrison-link">Powered by Marrisonlab</a>
            </div>
        </div>
        <style>
            .mmu-header {
                height: 120px;
                background: linear-gradient(to top right, #3f2154, #11111e);
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 0 40px;
                margin-bottom: 20px;
                border-radius: 4px;
                box-shadow: 0 2px 5px rgba(0,0,0,0.1);
                color: #fff;
                box-sizing: border-box;
            }
            .mmu-header-title .mmu-title-text {
                color: #fff !important;
                margin: 0 !important;
                padding: 0 !important;
                font-size: 28px !important;
                font-weight: 600 !important;
                line-height: 1.2 !important;
            }
            .mmu-header-logo {
                display: flex;
                flex-direction: column;
                align-items: flex-start;
                justify-content: center;
            }
            .mmu-header-logo img {
                width: 180px;
                height: auto;
                display: block;
                margin-bottom: 2px;
            }
            .marrison-link {
                color: #fd5ec0 !important;
                font-size: 11px !important;
                text-decoration: none !important;
                font-weight: 400 !important;
                font-style: italic !important;
                transition: color 0.2s ease;
            }
            .marrison-link:hover {
                color: #fff !important;
                text-decoration: underline !important;
            }
            .mmu-btn {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                padding: 9px 14px;
                border-radius: 8px;
                font-weight: 600;
                font-size: 13px;
                border: 1px solid transparent;
                transition: all 0.15s ease-in-out;
                box-shadow: 0 1px 2px rgba(0,0,0,0.06);
                text-decoration: none;
                background: #874abd !important;
                color: #fff !important;
                border-color: #874abd !important;
            }
            .mmu-btn:hover {
                background: #fd5ec0 !important;
                border-color: #fd5ec0 !important;
                color: #fff !important;
            }
            .mmu-btn .dashicons {
                font-size: 18px;
                width: 18px;
                height: 18px;
                line-height: 1;
                position: relative;
                top: 1px;
            }
            .mmu-btn-primary {
                background: #874abd !important;
                color: #fff !important;
                border-color: #874abd !important;
            }
            .mmu-btn-primary:hover {
                background: #fd5ec0 !important;
                border-color: #fd5ec0 !important;
            }
            .mmu-btn-secondary {
                background: #874abd !important;
                color: #fff !important;
                border-color: #874abd !important;
            }
            .mmu-btn-secondary:hover {
                background: #fd5ec0 !important;
                border-color: #fd5ec0 !important;
            }
            .mmu-btn-danger {
                background: #dc3232;
                color: #fff !important;
                border-color: #b02424;
            }
            .mmu-btn-danger:hover {
                background: #c12c2c;
            }
            .button-link-delete.mmu-btn-danger {
                background: #dc3232 !important;
                border-color: #b02424 !important;
                color: #fff !important;
            }
            .button-link-delete.mmu-btn-danger .dashicons {
                color: #fff !important;
            }
            .mmu-btn-small {
                padding: 7px 10px;
                font-size: 12px;
                border-radius: 6px;
                min-width: 32px;
            }
            .mmu-btn[disabled] {
                opacity: 0.5;
                cursor: not-allowed;
                box-shadow: none;
            }
        </style>
        <?php
    }

    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'marrison-updater') === false) {
            return;
        }
        $asset_version = defined('MCU_PLUGIN_VERSION') ? MCU_PLUGIN_VERSION : '9.7.0';
        wp_enqueue_style('mcu-admin-style', plugin_dir_url(__FILE__) . '../../assets/css/admin-style.css', [], $asset_version);
        wp_enqueue_script('mcu-admin-script', plugin_dir_url(__FILE__) . '../../assets/js/admin-script.js', ['jquery'], $asset_version, true);
        wp_localize_script('mcu-admin-script', 'marrisonUpdater', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('marrison_ajax_nonce'),
            'toggle_exclusion_nonce' => wp_create_nonce('marrison_toggle_exclusion')
        ]);

        $locale = function_exists('determine_locale') ? determine_locale() : get_locale();
        if (strpos($locale, 'en_') === 0) {
            wp_add_inline_script('mcu-admin-script', 'window.mcuAdminTranslations = ' . wp_json_encode($this->get_english_admin_fallback_translations()) . ';', 'before');
            wp_add_inline_script('mcu-admin-script', <<<'JS'
(function () {
    function translateTextNodes(root) {
        var dictionary = window.mcuAdminTranslations || {};
        var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
            acceptNode: function (node) {
                if (!node.nodeValue || !node.nodeValue.trim()) {
                    return NodeFilter.FILTER_REJECT;
                }
                if (node.parentNode && /^(script|style|textarea|code)$/i.test(node.parentNode.nodeName)) {
                    return NodeFilter.FILTER_REJECT;
                }
                return NodeFilter.FILTER_ACCEPT;
            }
        });
        var nodes = [];
        while (walker.nextNode()) {
            nodes.push(walker.currentNode);
        }
        nodes.forEach(function (node) {
            var original = node.nodeValue;
            var trimmed = original.trim();
            if (dictionary[trimmed]) {
                node.nodeValue = original.replace(trimmed, dictionary[trimmed]);
            }
        });
    }

    function translateAttributes(root) {
        var dictionary = window.mcuAdminTranslations || {};
        root.querySelectorAll('[value],[title],[placeholder],[data-confirm]').forEach(function (el) {
            ['value', 'title', 'placeholder', 'data-confirm'].forEach(function (attr) {
                var value = el.getAttribute(attr);
                if (value && dictionary[value.trim()]) {
                    el.setAttribute(attr, dictionary[value.trim()]);
                }
            });
        });
    }

    function run() {
        var root = document.querySelector('.mcu-wrap') || document.body;
        translateTextNodes(root);
        translateAttributes(root);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', run);
    } else {
        run();
    }
})();
JS
            );
        }
    }

    private function get_english_admin_fallback_translations() {
        return [
            'Aggiornamenti' => 'Updates',
            'Impostazioni' => 'Settings',
            'Generale' => 'General',
            'Programmazione' => 'Scheduling',
            'Esclusioni' => 'Exclusions',
            'Log' => 'Logs',
            'Log Aggiornamenti' => 'Update Logs',
            'Log aggiornamenti puliti.' => 'Update logs cleared.',
            'Nessun log disponibile.' => 'No logs available.',
            'Pulisci Log' => 'Clear Logs',
            'Ultima Modifica' => 'Last Modified',
            'Aggiorna' => 'Update',
            'Aggiorna tutto' => 'Update all',
            'Aggiorna Tutti' => 'Update All',
            'Aggiorna Selezionati' => 'Update Selected',
            'Aggiornato' => 'Updated',
            'In attesa' => 'Pending',
            'Escluso' => 'Excluded',
            'Backup Disponibili' => 'Available Backups',
            'Ripristino in corso...' => 'Restoring...',
            'Inizializzazione...' => 'Initializing...',
            'Backup Database' => 'Database Backup',
            'Esegui Backup Database' => 'Run Database Backup',
            'Backup File' => 'File Backup',
            'Esegui Backup File' => 'Run File Backup',
            'Lista Backup' => 'Backup List',
            'Versione Backup' => 'Backup Version',
            'Data Backup' => 'Backup Date',
            'Dimensione' => 'Size',
            'Azioni' => 'Actions',
            'Scarica' => 'Download',
            'Elimina' => 'Delete',
            'Ripristina' => 'Restore',
            'Stato:' => 'Status:',
            'Messaggio:' => 'Message:',
            'Programmazione Aggiornamenti' => 'Update Scheduling',
            'Abilita Aggiornamenti Automatici' => 'Enable Automatic Updates',
            'Frequenza' => 'Frequency',
            'Giorno del mese' => 'Day of month',
            'Usato per frequenza mensile e semestrale. Nei mesi piu corti viene usato l\'ultimo giorno disponibile.' => 'Used for monthly and semiannual frequency. In shorter months, the last available day is used.',
            'giorno %d' => 'day %d',
            'Salva Programmazione' => 'Save Schedule',
            'Aggiornamenti Trovati:' => 'Updates Found:',
            'Sì' => 'Yes',
            'No' => 'No',
            'Esclusioni Plugin' => 'Plugin Exclusions',
            'Esclusioni Temi' => 'Theme Exclusions',
            'Plugin' => 'Plugin',
            'Tema' => 'Theme',
            'Versione' => 'Version',
            'Escludi' => 'Exclude',
            'Strumenti Aggiuntivi' => 'Additional Tools',
            'Aggiorna tutti i temi' => 'Update all themes',
            'Aggiorna tutte le traduzioni' => 'Update all translations',
            'Plugin con Aggiornamenti' => 'Plugins with Updates',
            'Aggiornamento in corso...' => 'Update in progress...',
            'Impostazioni salvate correttamente.' => 'Settings saved successfully.',
            'Pulisci Cache' => 'Clear Cache',
            'URL del repository ripristinato ai valori predefiniti.' => 'Repository URL restored to default values.',
            'Bulk update completato.' => 'Bulk update completed.',
            'Cache pulita con successo.' => 'Cache cleared successfully.',
            'Plugin Privati' => 'Private Plugins',
            'Plugin Pubblici' => 'Public Plugins',
            'Temi Privati' => 'Private Themes',
            'Temi Pubblici' => 'Public Themes',
            'Traduzioni' => 'Translations',
            'Plugin Repository Privato' => 'Private Plugin Repository',
            'Temi Repository Privato' => 'Private Theme Repository',
            'Repository non configurato.' => 'Repository not configured.',
            'Repository Temi non configurato.' => 'Theme repository not configured.',
            'Configura ora' => 'Configure now',
            'Tutti i plugin privati sono aggiornati.' => 'All private plugins are up to date.',
            'Tutti i plugin monitorati sono aggiornati.' => 'All monitored plugins are up to date.',
            'Nessun aggiornamento temi disponibile.' => 'No theme updates available.',
            'Escluso dagli aggiornamenti' => 'Excluded from updates',
            'Tutti i plugin sono aggiornati.' => 'All plugins are up to date.',
            'Versione Attuale' => 'Current Version',
            'Nuova Versione' => 'New Version',
            'Tipo' => 'Type',
            'Stato' => 'Status',
            'Premium' => 'Premium',
            'Strumenti Aggiuntivi' => 'Additional Tools',
            'Elaborazione in corso...' => 'Processing...',
            'Avvio...' => 'Starting...',
            'Sei sicuro di voler ripristinare questo backup? L\'attuale versione verrÃ  sovrascritta.' => 'Are you sure you want to restore this backup? The current version will be overwritten.',
            'Sei sicuro di voler ripristinare questo backup? L\'attuale versione verrà sovrascritta.' => 'Are you sure you want to restore this backup? The current version will be overwritten.',
            'Ripristino...' => 'Restoring...',
            'Ripristino backup in corso...' => 'Backup restore in progress...',
            'Preparazione ripristino...' => 'Preparing restore...',
            'Ripristinato' => 'Restored',
            'Ripristino completato!' => 'Restore completed!',
            'Backup ripristinato con successo!' => 'Backup restored successfully!',
            'Errore!' => 'Error!',
            'Errore:' => 'Error:',
            'Sconosciuto' => 'Unknown',
            'Errore di connessione' => 'Connection error',
            'Errore di connessione al server.' => 'Server connection error.',
            'Sei sicuro di voler eliminare questo backup? L\'operazione non può essere annullata.' => 'Are you sure you want to delete this backup? This action cannot be undone.',
            'Eliminazione...' => 'Deleting...',
            'Backup eliminato correttamente.' => 'Backup deleted successfully.',
            'Backup in corso...' => 'Backup in progress...',
            'Backup file completato!' => 'File backup completed!',
            'Aggiornamento' => 'Updating',
            'Scaricamento pacchetto...' => 'Downloading package...',
            'Estrazione file...' => 'Extracting files...',
            'Installazione...' => 'Installing...',
            'Completato!' => 'Completed!',
            'aggiornato con successo!' => 'updated successfully!',
            'Riprova' => 'Retry',
            'Seleziona almeno un elemento da aggiornare.' => 'Select at least one item to update.',
            'Sei sicuro di voler aggiornare' => 'Are you sure you want to update',
            'elementi?' => 'items?',
            'Aggiornamento massivo in corso...' => 'Bulk update in progress...',
            'Tutti gli aggiornamenti completati.' => 'All updates completed.',
            'Aggiornamento:' => 'Updating:',
            'Sei sicuro di voler aggiornare tutti i plugin, temi e traduzioni?' => 'Are you sure you want to update all plugins, themes, and translations?',
            'Controllo aggiornamenti in corso...' => 'Checking updates...',
            'Recupero lista aggiornamenti...' => 'Retrieving update list...',
            'Errore durante il recupero degli aggiornamenti' => 'Error while retrieving updates',
            'Plugin Privato:' => 'Private Plugin:',
            'Plugin Ufficiale:' => 'Official Plugin:',
            'Nessun aggiornamento necessario.' => 'No updates needed.',
            'Tutto aggiornato!' => 'Everything is up to date!',
            'Tutti gli aggiornamenti completati!' => 'All updates completed!',
            'Aggiornamento massivo completato.' => 'Bulk update completed.',
            'Aggiornamento in corso:' => 'Updating:',
            'Impostazione salvata:' => 'Setting saved:',
            'Incluso' => 'Included',
            'Errore nel salvataggio' => 'Error while saving',
            'Sei sicuro di voler aggiornare tutti i plugin pubblici?' => 'Are you sure you want to update all public plugins?',
            'Aggiornamento plugin pubblici...' => 'Updating public plugins...',
            'Inizio aggiornamento...' => 'Starting update...',
            'Aggiornamento completato!' => 'Update completed!',
            'Plugin pubblici aggiornati con successo' => 'Public plugins updated successfully',
            'Errore durante l\'aggiornamento' => 'Error during update',
            'Pulizia in corso...' => 'Cleaning...',
            'Sei sicuro di voler pulire la cache? Questo ricaricherà la lista degli aggiornamenti dal repository.' => 'Are you sure you want to clear the cache? This will reload the update list from the repository.',
            'Seleziona almeno un plugin da installare' => 'Select at least one plugin to install',
            'Sei sicuro di voler installare i plugin selezionati?' => 'Are you sure you want to install the selected plugins?',
            'Installazione in corso...' => 'Installation in progress...',
            'Installazione plugin...' => 'Installing plugins...',
            'Installazione completata!' => 'Installation completed!',
            'Plugin installati con successo' => 'Plugins installed successfully',
            'Errore durante l\'installazione' => 'Error during installation',
            'Installa selezionati' => 'Install selected',
            'Inserisci un indirizzo email' => 'Enter an email address',
            'Invio in corso...' => 'Sending...',
            'Invia mail di test' => 'Send test email',
            'Email di test inviata con successo' => 'Test email sent successfully',
            'Errore nell\'invio dell\'email' => 'Error sending email',
        ];
    }

    public function add_admin_menu() {
        $commander_connected = \MarrisonCustomUpdater\MaintenanceClient\Settings::repository_config_managed();

        add_menu_page(
            'WP Master Updater',
            'WPMU',
            'manage_options',
            'marrison-updater',
            $commander_connected ? [$this, 'admin_page'] : [$this, 'settings_page'],
            'dashicons-update',
            30
        );

        if ($commander_connected) {
            add_submenu_page(
                'marrison-updater',
                __('Aggiornamenti', 'marrison-custom-updater'),
                __('Aggiornamenti', 'marrison-custom-updater'),
                'manage_options',
                'marrison-updater',
                [$this, 'admin_page']
            );
        }

        if ($commander_connected) {
            add_submenu_page(
                'marrison-updater',
                __('Backup', 'marrison-custom-updater'),
                __('Backup', 'marrison-custom-updater'),
                'manage_options',
                'marrison-updater-backups',
                [$this, 'backup_page']
            );
        }
        add_submenu_page(
            'marrison-updater',
            __('Impostazioni', 'marrison-custom-updater'),
            __('Impostazioni', 'marrison-custom-updater'),
            'manage_options',
            'marrison-updater-settings',
            [$this, 'settings_page']
        );
    }

    public function settings_page() {
        $settingsUpdated = $_GET['settings-updated'] ?? '';
        $commander_connected = \MarrisonCustomUpdater\MaintenanceClient\Settings::repository_config_managed();
        $requested_tab = isset($_GET['tab']) && is_string($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';
        $active_tab = '' !== $requested_tab ? $requested_tab : ($commander_connected ? 'general' : 'client');
        $allowed_tabs = $commander_connected ? ['general', 'scheduling', 'client', 'exclusions', 'logs'] : ['client'];
        if (!in_array($active_tab, $allowed_tabs, true)) {
            $active_tab = $commander_connected ? 'general' : 'client';
        }
        ?>
        <div class="mcu-wrap">
            <?php $this->render_header(__('Impostazioni', 'marrison-custom-updater')); ?>
            <h2 class="nav-tab-wrapper" style="margin-bottom: 20px;">
                <?php if ($commander_connected): ?>
                <a href="?page=marrison-updater-settings&tab=general" class="nav-tab <?php echo $active_tab == 'general' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Generale', 'marrison-custom-updater'); ?></a>
                <a href="?page=marrison-updater-settings&tab=scheduling" class="nav-tab <?php echo $active_tab == 'scheduling' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Programmazione', 'marrison-custom-updater'); ?></a>
                <?php endif; ?>
                <a href="?page=marrison-updater-settings&tab=client" class="nav-tab <?php echo $active_tab == 'client' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Client', 'marrison-custom-updater'); ?></a>
                <?php if ($commander_connected): ?>
                <a href="?page=marrison-updater-settings&tab=exclusions" class="nav-tab <?php echo $active_tab == 'exclusions' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Esclusioni', 'marrison-custom-updater'); ?></a>
                <a href="?page=marrison-updater-settings&tab=logs" class="nav-tab <?php echo $active_tab == 'logs' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Log', 'marrison-custom-updater'); ?></a>
                <?php endif; ?>
            </h2>
            <?php if ($settingsUpdated === 'saved'): ?>
                <div class="mcu-notice mcu-notice-success"><span class="dashicons dashicons-yes"></span> <?php esc_html_e('Impostazioni salvate correttamente.', 'marrison-custom-updater'); ?></div>
            <?php elseif ($settingsUpdated === 'removed'): ?>
                <div class="mcu-notice mcu-notice-success"><span class="dashicons dashicons-yes"></span> <?php esc_html_e('URL del repository rimosso.', 'marrison-custom-updater'); ?></div>
            <?php endif; ?>
            <?php if (isset($_GET['cache_cleared'])): ?>
                <div class="mcu-notice mcu-notice-success"><span class="dashicons dashicons-yes"></span> <?php esc_html_e('Cache pulita.', 'marrison-custom-updater'); ?></div>
            <?php endif; ?>
            <?php if (isset($_GET['mcu_checked'])): ?>
                <div class="mcu-notice mcu-notice-success"><span class="dashicons dashicons-yes"></span> <?php esc_html_e('Controllo aggiornamenti MCU forzato con successo.', 'marrison-custom-updater'); ?></div>
            <?php endif; ?>
            <?php if (isset($_GET['logs_cleared'])): ?>
                <div class="mcu-notice mcu-notice-success"><span class="dashicons dashicons-yes"></span> <?php esc_html_e('Log aggiornamenti puliti.', 'marrison-custom-updater'); ?></div>
            <?php endif; ?>
            <?php if (isset($_GET['mcu_lock_cleared'])): ?>
                <div class="mcu-notice mcu-notice-success"><span class="dashicons dashicons-yes"></span> <?php esc_html_e('Aggiornamento bloccato interrotto e lock rimosso.', 'marrison-custom-updater'); ?></div>
            <?php endif; ?>
            <?php if ($active_tab == 'general'): ?>
                <div class="mcu-card">
                    <div class="mcu-card-header">
                        <h2 class="mcu-card-title"><span class="dashicons dashicons-database"></span> <?php esc_html_e('Impostazioni Repository', 'marrison-custom-updater'); ?></h2>
                    </div>
                    <div class="mcu-notice mcu-notice-info">
                        <span class="dashicons dashicons-admin-generic"></span>
                        <?php esc_html_e('Gli URL dei repository privati sono gestiti centralmente da Marrison Commander. Configurali nelle impostazioni di Commander: questo sito li riceverà tramite il collegamento MCU autenticato.', 'marrison-custom-updater'); ?>
                    </div>
                    <p class="description">
                        <?php esc_html_e('Gli URL locali vengono utilizzati solo dopo una configurazione ricevuta da Commander tramite richiesta autenticata. Un sito non autorizzato non può accedere ai repository privati.', 'marrison-custom-updater'); ?>
                    </p>
                </div>

                <?php
                $updates = $this->get_available_updates();
                $plugins = get_plugins();

                $installed_count = 0;
                $installed_list = [];
                if (!empty($updates)) {
                    foreach ($updates as $u) {
                        $file = $this->find_plugin_file($u['slug'], $u['name'] ?? '');
                        if ($file && isset($plugins[$file])) {
                            $installed_count++;
                            $installed_list[] = [
                                'slug' => $u['slug'],
                                'name' => $u['name'],
                                'file' => $file,
                                'version' => $plugins[$file]['Version'],
                                'remote_version' => $u['version'],
                                'status' => '<span class="mcu-badge mcu-badge-success">Monitorato</span>'
                            ];
                        }
                    }
                }
                ?>
                <div class="mcu-dashboard-grid" style="margin-top: 30px;">
                    <div class="mcu-card mcu-stat-card">
                        <?php $plugin_repo_status = $this->mcu_private_repo_status('plugin'); ?>
                        <div class="mcu-stat-number" title="<?php echo esc_attr($plugin_repo_status['message']); ?>">
                            <span class="dashicons dashicons-<?php echo esc_attr($plugin_repo_status['icon']); ?>" style="color:var(--mcu-<?php echo esc_attr($plugin_repo_status['class']); ?>); font-size: 36px; height: 36px; width: 36px;"></span>
                        </div>
                        <div class="mcu-stat-label"><?php esc_html_e('Stato Plugin', 'marrison-custom-updater'); ?></div>
                    </div>
                    <div class="mcu-card mcu-stat-card">
                        <div class="mcu-stat-number"><?php echo $installed_count; ?></div>
                        <div class="mcu-stat-label"><?php esc_html_e('Plugin Monitorati', 'marrison-custom-updater'); ?></div>
                    </div>
                </div>
                <?php if ($installed_count > 0): ?>
                    <div class="mcu-card">
                        <div class="mcu-card-header">
                            <h2 class="mcu-card-title"><?php esc_html_e('Plugin Monitorati su questo sito', 'marrison-custom-updater'); ?></h2>
                        </div>
                        <table class="mcu-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Plugin Installato', 'marrison-custom-updater'); ?></th>
                                    <th><?php esc_html_e('Versione Installata', 'marrison-custom-updater'); ?></th>
                                    <th><?php esc_html_e('Versione Repository', 'marrison-custom-updater'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($installed_list as $item): ?>
                                    <tr>
                                        <td><strong><?php echo esc_html($item['name']); ?></strong></td>
                                        <td><?php echo esc_html($item['version']); ?></td>
                                        <td><?php echo esc_html($item['remote_version']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                <?php
                $theme_updates = $this->get_available_theme_updates();
                $theme_repo_count = count($theme_updates);
                $theme_installed_count = 0;
                $theme_installed_list = [];
                if (!empty($theme_updates)) {
                    $installed_themes = wp_get_themes();
                    foreach ($theme_updates as $u) {
                        $slug = $u['slug'];
                        $theme = wp_get_theme($slug);
                        $is_installed = $theme->exists();
                        $detected_slug = $slug;
                        if (!$is_installed) {
                            foreach ($installed_themes as $t_slug => $t_obj) {
                                if (strcasecmp($t_obj->get('Name'), $u['name']) === 0 || $t_obj->get('TextDomain') === $slug) {
                                    $theme = $t_obj;
                                    $is_installed = true;
                                    $detected_slug = $t_slug;
                                    break;
                                }
                            }
                        }
                        if ($is_installed) {
                            $theme_installed_count++;
                            $theme_installed_list[] = [
                                'name' => $u['name'],
                                'repo_slug' => $u['slug'],
                                'slug' => $detected_slug,
                                'version' => $is_installed ? $theme->get('Version') : '-',
                                'remote_version' => $u['version'],
                                'status' => '<span class="mcu-badge mcu-badge-success">Monitorato</span>'
                            ];
                        }
                    }
                }
                ?>
                <div class="mcu-dashboard-grid" style="margin-top: 30px;">
                    <div class="mcu-card mcu-stat-card">
                        <?php $theme_repo_status = $this->mcu_private_repo_status('theme'); ?>
                        <div class="mcu-stat-number" title="<?php echo esc_attr($theme_repo_status['message']); ?>">
                            <span class="dashicons dashicons-<?php echo esc_attr($theme_repo_status['icon']); ?>" style="color:var(--mcu-<?php echo esc_attr($theme_repo_status['class']); ?>); font-size: 36px; height: 36px; width: 36px;"></span>
                        </div>
                        <div class="mcu-stat-label"><?php esc_html_e('Stato Temi', 'marrison-custom-updater'); ?></div>
                    </div>

                    <div class="mcu-card mcu-stat-card">
                        <div class="mcu-stat-number"><?php echo $theme_installed_count; ?></div>
                        <div class="mcu-stat-label"><?php esc_html_e('Temi Monitorati', 'marrison-custom-updater'); ?></div>
                    </div>
                </div>
                <?php if (!empty($theme_installed_list)): ?>
                    <div class="mcu-card">
                        <div class="mcu-card-header">
                            <h2 class="mcu-card-title"><?php esc_html_e('Temi Installati Monitorati', 'marrison-custom-updater'); ?></h2>
                        </div>
                        <table class="mcu-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Tema', 'marrison-custom-updater'); ?></th>
                                    <th><?php esc_html_e('Versione Installata', 'marrison-custom-updater'); ?></th>
                                    <th><?php esc_html_e('Versione Repository', 'marrison-custom-updater'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($theme_installed_list as $item): ?>
                                    <tr>
                                        <td><strong><?php echo esc_html($item['name']); ?></strong></td>
                                        <td><?php echo esc_html($item['version']); ?></td>
                                        <td><?php echo esc_html($item['remote_version']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            <?php elseif ($active_tab == 'scheduling'): ?>
                <div class="mcu-card">
                    <div class="mcu-card-header">
                        <h2 class="mcu-card-title"><span class="dashicons dashicons-calendar-alt"></span> <?php esc_html_e('Programmazione Aggiornamenti', 'marrison-custom-updater'); ?></h2>
                    </div>
                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                        <?php wp_nonce_field('marrison_save_scheduling'); ?>
                        <input type="hidden" name="action" value="marrison_save_scheduling">
                        <table class="form-table">
                            <tr>
                                <th scope="row"><label for="marrison_auto_update_enabled"><?php esc_html_e('Abilita Aggiornamenti Automatici', 'marrison-custom-updater'); ?></label></th>
                                <td>
                                    <label class="mcu-switch">
                                        <input type="checkbox" id="marrison_auto_update_enabled" name="marrison_auto_update_enabled" value="yes" <?php checked('yes', get_option('marrison_auto_update_enabled')); ?>>
                                        <span class="mcu-slider"></span>
                                    </label>
                                    <p class="description" style="display: inline-block; vertical-align: super; margin-left: 10px;"><?php esc_html_e('Attiva aggiornamento automatico periodico', 'marrison-custom-updater'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="marrison_auto_update_frequency"><?php esc_html_e('Frequenza', 'marrison-custom-updater'); ?></label></th>
                                <td>
                                    <select id="marrison_auto_update_frequency" name="marrison_auto_update_frequency">
                                        <option value="daily" <?php selected('daily', get_option('marrison_auto_update_frequency')); ?>><?php esc_html_e('Giornaliera', 'marrison-custom-updater'); ?></option>
                                        <option value="weekly" <?php selected('weekly', get_option('marrison_auto_update_frequency')); ?>><?php esc_html_e('Settimanale', 'marrison-custom-updater'); ?></option>
                                        <option value="monthly" <?php selected('monthly', get_option('marrison_auto_update_frequency')); ?>><?php esc_html_e('Mensile', 'marrison-custom-updater'); ?></option>
                                        <option value="biannual" <?php selected('biannual', get_option('marrison_auto_update_frequency')); ?>><?php esc_html_e('Semestrale', 'marrison-custom-updater'); ?></option>
                                    </select>
                                </td>
                            </tr>
                            <tr id="marrison_auto_update_month_day_row">
                                <th scope="row"><label for="marrison_auto_update_month_day"><?php esc_html_e('Giorno del mese', 'marrison-custom-updater'); ?></label></th>
                                <td>
                                    <?php $scheduled_month_day = max(1, min(31, absint(get_option('marrison_auto_update_month_day', current_time('j'))))); ?>
                                    <select id="marrison_auto_update_month_day" name="marrison_auto_update_month_day">
                                        <?php for ($day = 1; $day <= 31; $day++): ?>
                                            <option value="<?php echo esc_attr((string) $day); ?>" <?php selected($day, $scheduled_month_day); ?>><?php echo esc_html((string) $day); ?></option>
                                        <?php endfor; ?>
                                    </select>
                                    <p class="description"><?php esc_html_e('Usato per frequenza mensile e semestrale. Nei mesi piu corti viene usato l\'ultimo giorno disponibile.', 'marrison-custom-updater'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="marrison_auto_update_time"><?php esc_html_e('Orario (Fuso Orario Italiano)', 'marrison-custom-updater'); ?></label></th>
                                <td>
                                    <input type="time" id="marrison_auto_update_time" name="marrison_auto_update_time" value="<?php echo esc_attr(get_option('marrison_auto_update_time', '00:00')); ?>">
                                    <p class="description"><?php esc_html_e('Seleziona l\'orario di esecuzione (Europe/Rome).', 'marrison-custom-updater'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="marrison_auto_update_email"><?php esc_html_e('Email per Report', 'marrison-custom-updater'); ?></label></th>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <input type="email" id="marrison_auto_update_email" name="marrison_auto_update_email" value="<?php echo esc_attr(get_option('marrison_auto_update_email', get_option('admin_email'))); ?>" class="regular-text">
                                        <button type="button" id="marrison_test_email_btn" class="mcu-button mcu-button-secondary" data-nonce="<?php echo wp_create_nonce('marrison_test_email'); ?>"><?php esc_html_e('Invia mail di test', 'marrison-custom-updater'); ?></button>
                                        <span id="marrison_test_email_result" style="font-weight: 600;"></span>
                                    </div>
                                    <p class="description"><?php esc_html_e('Inserisci l\'indirizzo email dove inviare il report degli aggiornamenti (opzionale).', 'marrison-custom-updater'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="marrison_db_backup_with_updates"><?php esc_html_e('Backup Database', 'marrison-custom-updater'); ?></label></th>
                                <td>
                                    <label class="mcu-switch">
                                        <input type="checkbox" id="marrison_db_backup_with_updates" name="marrison_db_backup_with_updates" value="yes" <?php checked(true, get_option('marrison_db_backup_with_updates') === 'yes' || get_option('marrison_files_backup_with_updates') === 'yes'); ?>>
                                        <span class="mcu-slider"></span>
                                    </label>
                                    <p class="description" style="display: inline-block; vertical-align: super; margin-left: 10px;"><?php esc_html_e('Esegui un backup del database prima di ogni aggiornamento automatico (mantiene gli ultimi 3; obbligatorio se il backup file e attivo).', 'marrison-custom-updater'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="marrison_files_backup_with_updates"><?php esc_html_e('Backup File', 'marrison-custom-updater'); ?></label></th>
                                <td>
                                    <label class="mcu-switch">
                                        <input type="checkbox" id="marrison_files_backup_with_updates" name="marrison_files_backup_with_updates" value="yes" <?php checked('yes', get_option('marrison_files_backup_with_updates')); ?>>
                                        <span class="mcu-slider"></span>
                                    </label>
                                    <p class="description" style="display: inline-block; vertical-align: super; margin-left: 10px;"><?php esc_html_e('Esegui un backup completo dei file prima di ogni aggiornamento automatico (mantiene solo l\'ultimo set, formato tar.gz in parti).', 'marrison-custom-updater'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="marrison_files_backup_skip_large_files"><?php esc_html_e('File grandi nel backup', 'marrison-custom-updater'); ?></label></th>
                                <td>
                                    <label class="mcu-switch">
                                        <input type="checkbox" id="marrison_files_backup_skip_large_files" name="marrison_files_backup_skip_large_files" value="yes" <?php checked('yes', get_option('marrison_files_backup_skip_large_files')); ?>>
                                        <span class="mcu-slider"></span>
                                    </label>
                                    <p class="description" style="display: inline-block; vertical-align: super; margin-left: 10px;"><?php esc_html_e('Salta i file piu grandi del limite della singola parte (850 MB di default) e continua il backup.', 'marrison-custom-updater'); ?></p>
                                </td>
                            </tr>
                        </table>
                        <?php 
                        if (method_exists($this, 'mcu_ensure_automatic_update_event_scheduled')) {
                            $this->mcu_ensure_automatic_update_event_scheduled(['context' => 'admin_scheduling_tab']);
                        }
                        $next_run = $this->mcu_next_scheduled_update_event_timestamp();
                        if ($next_run): 
                            $tz = new DateTimeZone('Europe/Rome');
                            $date = new DateTime('@' . $next_run);
                            $date->setTimezone($tz);
                            
                            $freq_slug = get_option('marrison_auto_update_frequency', 'daily');
                            $freq_labels = [
                                'daily' => __('Giornaliera', 'marrison-custom-updater'),
                                'weekly' => __('Settimanale', 'marrison-custom-updater'),
                                'monthly' => __('Mensile', 'marrison-custom-updater'),
                                'biannual' => __('Semestrale', 'marrison-custom-updater')
                            ];
                            $freq_label = isset($freq_labels[$freq_slug]) ? $freq_labels[$freq_slug] : $freq_slug;
                            if (in_array($freq_slug, ['monthly', 'biannual'], true)) {
                                $freq_label .= ' - ' . sprintf(__('giorno %d', 'marrison-custom-updater'), max(1, min(31, absint(get_option('marrison_auto_update_month_day', current_time('j'))))));
                            }
                        ?>
                            <div class="mcu-notice mcu-notice-info" style="margin-top: 20px;">
                                <span class="dashicons dashicons-clock"></span> <?php esc_html_e('Prossima esecuzione programmata:', 'marrison-custom-updater'); ?> <strong><?php echo $date->format('d/m/Y H:i'); ?></strong> <small>(<?php esc_html_e('Frequenza:', 'marrison-custom-updater'); ?> <?php echo esc_html($freq_label); ?>)</small>
                            </div>
                        <?php endif; ?>
                        <?php 
                        if (method_exists($this, 'mcu_recover_stale_cron_log_if_needed')) {
                            $this->mcu_recover_stale_cron_log_if_needed(['context' => 'admin_ui']);
                        }
                        $last_log = get_option('marrison_last_cron_log');
                        $active_update_lock = get_transient('marrison_update_lock');
                        $last_log_status = is_array($last_log) ? sanitize_key((string) ($last_log['status'] ?? '')) : '';
                        $master_update_status = get_option('mcu_master_update_status', []);
                        $master_update_active = is_array($master_update_status) && in_array(sanitize_key((string) ($master_update_status['status'] ?? '')), ['queued', 'running'], true);
                        $last_log_manually_cleared = is_array($last_log) && !empty($last_log['manual_cleared']);
                        $last_log_needs_cleanup = is_array($last_log) && (
                            in_array($last_log_status, ['started', 'running'], true)
                            || (!empty($last_log['stale']) && !$last_log_manually_cleared)
                        );
                        $show_manual_unlock = is_array($active_update_lock) || $master_update_active || $last_log_needs_cleanup;
                        if ($last_log && is_array($last_log)): 
                        ?>
                            <div class="mcu-card" style="margin-top: 20px; border-left: 4px solid <?php echo ($last_log['status'] === 'completed' && (!isset($last_log['email_sent']) || $last_log['email_sent'])) ? 'var(--mcu-success)' : 'var(--mcu-danger)'; ?>;">
                                <h3 style="margin-top: 0;"><?php esc_html_e('Ultima Esecuzione', 'marrison-custom-updater'); ?></h3>
                                <p><strong><?php esc_html_e('Data:', 'marrison-custom-updater'); ?></strong> <?php echo esc_html($last_log['time']); ?></p>
                                <p><strong><?php esc_html_e('Stato:', 'marrison-custom-updater'); ?></strong> <?php echo esc_html($last_log['status']); ?></p>
                                <p><strong><?php esc_html_e('Messaggio:', 'marrison-custom-updater'); ?></strong> <?php echo esc_html($last_log['message']); ?></p>
                                <?php if (isset($last_log['updates_found'])): ?>
                                    <p><strong><?php esc_html_e('Aggiornamenti Trovati:', 'marrison-custom-updater'); ?></strong> <?php echo $last_log['updates_found'] ? esc_html__('Sì', 'marrison-custom-updater') : esc_html__('No', 'marrison-custom-updater'); ?></p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($show_manual_unlock): ?>
                            <?php
                            $lock_operation = is_array($active_update_lock) && !empty($active_update_lock['operation']) ? sanitize_key((string) $active_update_lock['operation']) : '';
                            $last_activity = is_array($active_update_lock) && method_exists($this, 'mcu_update_lock_last_activity') ? $this->mcu_update_lock_last_activity($active_update_lock) : 0;
                            $last_activity_minutes = $last_activity > 0 ? floor(max(0, time() - $last_activity) / 60) : 0;
                            $unlock_url = wp_nonce_url(admin_url('admin-post.php?action=mcu_clear_update_lock'), 'mcu_clear_update_lock');
                            ?>
                            <div class="mcu-notice mcu-notice-error" style="margin-top: 20px;">
                                <span class="dashicons dashicons-warning"></span>
                                <strong><?php esc_html_e('Cron/update MCU in corso o bloccato.', 'marrison-custom-updater'); ?></strong>
                                <?php if ($lock_operation !== ''): ?>
                                    <span><?php printf(esc_html__('Operazione: %s.', 'marrison-custom-updater'), esc_html($lock_operation)); ?></span>
                                <?php endif; ?>
                                <?php if ($master_update_active): ?>
                                    <span><?php esc_html_e('Richiesta Master ancora in attesa.', 'marrison-custom-updater'); ?></span>
                                <?php endif; ?>
                                <?php if ($last_activity_minutes > 0): ?>
                                    <span><?php printf(esc_html__('Ultimo heartbeat: %d minuti fa.', 'marrison-custom-updater'), (int) $last_activity_minutes); ?></span>
                                <?php endif; ?>
                                <a class="mcu-button mcu-button-danger mcu-button-sm" style="margin-left: 10px;" href="<?php echo esc_url($unlock_url); ?>" onclick="return confirm('<?php echo esc_js(__('Eliminare il cron/update bloccato e rimuovere lock e richieste Master pendenti? Usa questa azione solo se sei sicuro che il job non sia piu in esecuzione.', 'marrison-custom-updater')); ?>');">
                                    <span class="dashicons dashicons-dismiss"></span> <?php esc_html_e('Elimina cron bloccato', 'marrison-custom-updater'); ?>
                                </a>
                            </div>
                        <?php endif; ?>
                        <div style="margin-top: 20px;">
                            <button class="mcu-button mcu-button-primary" type="submit"><?php esc_html_e('Salva Programmazione', 'marrison-custom-updater'); ?></button>
                        </div>
                    </form>
                </div>
            <?php elseif ($active_tab == 'client'): ?>
                <?php
                if (!class_exists('\MarrisonCustomUpdater\MaintenanceClient\Admin')) {
                    require_once MCU_PLUGIN_DIR . 'includes/mcu-client/class-admin.php';
                }
                if (!class_exists('\MarrisonCustomUpdater\MaintenanceClient\Rest_Controller')) {
                    require_once MCU_PLUGIN_DIR . 'includes/mcu-client/class-rest-controller.php';
                }
                \MarrisonCustomUpdater\MaintenanceClient\Admin::render_settings_panel();
                ?>
            <?php elseif ($active_tab == 'exclusions'): ?>
                <div class="mcu-card">
                    <div class="mcu-card-header">
                        <h2 class="mcu-card-title"><span class="dashicons dashicons-hidden"></span> <?php esc_html_e('Esclusioni Plugin', 'marrison-custom-updater'); ?></h2>
                    </div>
                    <p class="description" style="margin-bottom: 15px;"><?php esc_html_e('Seleziona i plugin che vuoi escludere dagli aggiornamenti automatici e dalle notifiche (sia privati che ufficiali).', 'marrison-custom-updater'); ?></p>
                    <table class="mcu-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Plugin', 'marrison-custom-updater'); ?></th>
                                <th><?php esc_html_e('Versione', 'marrison-custom-updater'); ?></th>
                                <th style="width: 80px; text-align: center;"><?php esc_html_e('Escludi', 'marrison-custom-updater'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $all_plugins = get_plugins();
                            foreach ($all_plugins as $plugin_file => $plugin_data):
                                $slug = dirname($plugin_file);
                                if ($slug === '.' || $slug === '') $slug = basename($plugin_file, '.php');
                                $is_excluded = $this->is_item_excluded($slug, 'plugin');
                            ?>
                                <tr>
                                    <td><strong><?php echo esc_html($plugin_data['Name']); ?></strong></td>
                                    <td><?php echo esc_html($plugin_data['Version']); ?></td>
                                    <td style="text-align: center;">
                                        <label class="mcu-switch mcu-switch-sm">
                                            <input type="checkbox" class="marrison_exclude_toggle" data-slug="<?php echo esc_attr($slug); ?>" data-type="plugin" <?php checked($is_excluded); ?>>
                                            <span class="mcu-slider"></span>
                                        </label>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="mcu-card" style="margin-top: 30px;">
                    <div class="mcu-card-header">
                        <h2 class="mcu-card-title"><span class="dashicons dashicons-hidden"></span> <?php esc_html_e('Esclusioni Temi', 'marrison-custom-updater'); ?></h2>
                    </div>
                    <p class="description" style="margin-bottom: 15px;"><?php esc_html_e('Seleziona i temi che vuoi escludere dagli aggiornamenti automatici e dalle notifiche.', 'marrison-custom-updater'); ?></p>
                    <table class="mcu-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Tema', 'marrison-custom-updater'); ?></th>
                                <th><?php esc_html_e('Versione', 'marrison-custom-updater'); ?></th>
                                <th style="width: 80px; text-align: center;"><?php esc_html_e('Escludi', 'marrison-custom-updater'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $all_themes = wp_get_themes();
                            foreach ($all_themes as $theme_slug => $theme):
                                $is_excluded = $this->is_item_excluded($theme_slug, 'theme');
                            ?>
                                <tr>
                                    <td><strong><?php echo esc_html($theme->get('Name')); ?></strong></td>
                                    <td><?php echo esc_html($theme->get('Version')); ?></td>
                                    <td style="text-align: center;">
                                        <label class="mcu-switch mcu-switch-sm">
                                            <input type="checkbox" class="marrison_exclude_toggle" data-slug="<?php echo esc_attr($theme_slug); ?>" data-type="theme" <?php checked($is_excluded); ?>>
                                            <span class="mcu-slider"></span>
                                        </label>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            <?php elseif ($active_tab == 'logs'): ?>
                <div class="mcu-card">
                    <div class="mcu-card-header">
                        <h2 class="mcu-card-title"><span class="dashicons dashicons-media-text"></span> <?php esc_html_e('Log Aggiornamenti', 'marrison-custom-updater'); ?></h2>
                    </div>
                    <p class="description" style="margin-bottom: 15px;"><?php esc_html_e('I log sono divisi per mese, protetti in wp-content e puliti automaticamente con retention di 6 mesi.', 'marrison-custom-updater'); ?></p>
                    <?php $log_items = $this->mcu_get_update_log_items(); ?>
                    <?php if (empty($log_items)): ?>
                        <div class="mcu-empty-state">
                            <span class="dashicons dashicons-media-text"></span>
                            <p><?php esc_html_e('Nessun log disponibile.', 'marrison-custom-updater'); ?></p>
                        </div>
                    <?php else: ?>
                        <table class="mcu-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('File', 'marrison-custom-updater'); ?></th>
                                    <th><?php esc_html_e('Ultima Modifica', 'marrison-custom-updater'); ?></th>
                                    <th><?php esc_html_e('Dimensione', 'marrison-custom-updater'); ?></th>
                                    <th style="text-align:right;"><?php esc_html_e('Azioni', 'marrison-custom-updater'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($log_items as $item): ?>
                                    <tr>
                                        <td><code><?php echo esc_html($item['filename']); ?></code></td>
                                        <td><?php echo esc_html($item['date']); ?></td>
                                        <td><?php echo esc_html($item['size']); ?></td>
                                        <td style="text-align:right;">
                                            <a class="mcu-button mcu-button-secondary mcu-button-sm" href="<?php echo esc_url($item['url']); ?>">
                                                <span class="dashicons dashicons-download"></span> <?php esc_html_e('Scarica', 'marrison-custom-updater'); ?>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="margin-top: 20px;">
                            <?php wp_nonce_field('marrison_clear_update_logs'); ?>
                            <input type="hidden" name="action" value="marrison_clear_update_logs">
                            <button type="submit" class="mcu-button mcu-button-secondary" onclick="return confirm('<?php echo esc_js(__('Sei sicuro di voler eliminare tutti i log aggiornamenti?', 'marrison-custom-updater')); ?>');">
                                <span class="dashicons dashicons-trash"></span> <?php esc_html_e('Pulisci Log', 'marrison-custom-updater'); ?>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>

            <?php endif; ?>
        </div>
        <?php
    }

    public function download_repo_file() {
        check_admin_referer('marrison_download_repo_file');
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permessi insufficienti', 'marrison-custom-updater'));
        }

        // Use MCU_PLUGIN_DIR if defined, otherwise fallback to dirname logic
        $base_path = defined('MCU_PLUGIN_DIR') ? MCU_PLUGIN_DIR : plugin_dir_path(dirname(dirname(dirname(__FILE__))));
        
        $file_path = $base_path . 'add_this_file_for_plugin_repo/index.php';

        if (!file_exists($file_path)) {
            // Debug info in case of failure
            wp_die('File non trovato: ' . esc_html($file_path) . ' (Base: ' . esc_html($base_path) . ')');
        }

        // Clean buffer to prevent corruption
        if (ob_get_length()) ob_end_clean();

        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="index.php"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($file_path));
        readfile($file_path);
        exit;
    }

    public function download_theme_repo_file() {
        check_admin_referer('marrison_download_theme_repo_file');
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permessi insufficienti', 'marrison-custom-updater'));
        }

        // Use MCU_PLUGIN_DIR if defined, otherwise fallback to dirname logic
        $base_path = defined('MCU_PLUGIN_DIR') ? MCU_PLUGIN_DIR : plugin_dir_path(dirname(dirname(dirname(__FILE__))));
        
        $file_path = $base_path . 'add_this_file_for_themes_repo/index.php';

        if (!file_exists($file_path)) {
            // Debug info in case of failure
            wp_die('File non trovato: ' . esc_html($file_path) . ' (Base: ' . esc_html($base_path) . ')');
        }

        // Clean buffer to prevent corruption
        if (ob_get_length()) ob_end_clean();

        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="index.php"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($file_path));
        readfile($file_path);
        exit;
    }

    public function installer_page() {
        $permissions = $this->check_remote_permissions();
        if (!$permissions['installer']) {
            ?>
            <div class="wrap">
                <?php $this->render_header('Installer - Repository Privato'); ?>
                <div class="notice notice-error"><p><?php esc_html_e('Non sei autorizzato a visualizzare questa pagina.', 'marrison-custom-updater'); ?></p></div>
                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                    <?php wp_nonce_field('marrison_check_permissions'); ?>
                    <input type="hidden" name="action" value="marrison_check_permissions">
                    <input type="hidden" name="redirect_to" value="<?php echo esc_url(admin_url('admin.php?page=marrison-updater-installer')); ?>">
                    <button class="mcu-button mcu-button-secondary"><?php esc_html_e('Verifica permessi', 'marrison-custom-updater'); ?></button>
                </form>
            </div>
            <?php
            return;
        }
        $updates = $this->get_available_updates();
        $plugins = get_plugins();
        $installed_slugs = $_GET['installed'] ?? [];
        if (!is_array($installed_slugs)) $installed_slugs = [$installed_slugs];
        ?>
        <div class="wrap">
            <?php $this->render_header('Installer - Repository Privato'); ?>
            <?php if (!empty($installed_slugs)): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php printf(esc_html__('%d plugin installati con successo.', 'marrison-custom-updater'), count($installed_slugs)); ?></p>
                </div>
            <?php endif; ?>
            <div id="marrison-install-progress" class="notice notice-info" style="display:none; padding: 15px; margin: 20px 0;">
                <div style="display: flex; align-items: center; gap: 15px;">
                    <div class="spinner is-active" style="float:none; width:20px; height:20px; margin:0;"></div>
                    <div style="flex: 1;">
                        <div id="marrison-install-status" style="font-weight: 600; margin-bottom: 8px;">Installazione in corso...</div>
                        <div style="background: #f0f0f1; border-radius: 4px; height: 8px; overflow: hidden;">
                            <div id="marrison-install-bar" style="background: #2271b1; height: 100%; width: 0%; transition: width 0.3s ease;"></div>
                        </div>
                        <div id="marrison-install-info" style="font-size: 12px; color: #646970; margin-top: 4px;">In attesa...</div>
                    </div>
                </div>
            </div>
            <form id="marrison-install-form" method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <?php wp_nonce_field('marrison_bulk_install'); ?>
                <input type="hidden" name="action" value="marrison_bulk_install">
                <div class="tablenav top">
                    <div class="alignleft actions">
                        <label style="font-weight: 600;"><input type="checkbox" id="marrison-select-all"> Seleziona tutti</label>
                    </div>
                    <div class="alignleft actions">
                        <button type="submit" id="marrison-install-btn" class="mcu-button mcu-button-primary">Installa selezionati</button>
                    </div>
                </div>
                <div class="marrison-grid-container" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; margin-top: 20px;">
                    <?php if (empty($updates)): ?>
                        <p>Nessun plugin disponibile nel repository.</p>
                    <?php else: ?>
                        <?php foreach ($updates as $u): 
                            if (!isset($u['slug'])) continue;
                            $slug = $u['slug'];
                            $plugin_file = $this->find_plugin_file($slug, $u['name'] ?? '');
                            $is_installed = !empty($plugin_file);
                            $is_active = $is_installed && is_plugin_active($plugin_file);
                            $disabled = $is_installed;
                            $card_style = $disabled ? 'opacity: 0.6; background: #f6f7f7;' : 'background: #fff;';
                            $card_style .= ' border: 1px solid #c3c4c7; padding: 12px; border-radius: 4px; box-shadow: 0 1px 1px rgba(0,0,0,.04); position: relative;';
                        ?>
                            <div class="marrison-plugin-card" style="<?php echo $card_style; ?>">
                                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px;">
                                    <h3 style="margin: 0; font-size: 1em; line-height: 1.3;"><?php echo esc_html($u['name']); ?></h3>
                                    <?php if (!$disabled): ?>
                                        <input type="checkbox" name="plugins[]" value="<?php echo esc_attr($slug); ?>" class="marrison-plugin-cb" style="transform: scale(1.1);">
                                    <?php else: ?>
                                        <input type="checkbox" disabled checked style="transform: scale(1.1);">
                                    <?php endif; ?>
                                </div>
                                <p style="margin: 4px 0; font-size: 0.9em;"><strong>v</strong> <?php echo esc_html($u['version']); ?></p>
                                <div style="margin-top: 8px; padding-top: 8px; border-top: 1px solid #f0f0f1; font-size: 0.85em;">
                                    <?php if ($is_active): ?>
                                        <span class="dashicons dashicons-yes" style="color: #00a32a; font-size: 16px; width: 16px; height: 16px;"></span> <span style="color: #00a32a; font-weight: 600;">Attivo</span>
                                    <?php elseif ($is_installed): ?>
                                        <span class="dashicons dashicons-warning" style="color: #dba617; font-size: 16px; width: 16px; height: 16px;"></span> <span style="color: #dba617; font-weight: 600;">Installato (Inattivo)</span>
                                    <?php else: ?>
                                        <span class="dashicons dashicons-download" style="color: #2271b1; font-size: 16px; width: 16px; height: 16px;"></span> <span style="color: #2271b1; font-weight: 600;">Disponibile</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </form>
            <script>
            jQuery(document).ready(function($) {
                $('#marrison-select-all').on('change', function() {
                    $('.marrison-plugin-cb').prop('checked', $(this).is(':checked'));
                });
                $('#marrison-install-form').on('submit', function(e) {
                    var selected = $('.marrison-plugin-cb:checked');
                    if (selected.length === 0) {
                        alert('Seleziona almeno un plugin da installare.');
                        return false;
                    }
                    e.preventDefault();
                    var plugins = [];
                    selected.each(function() { plugins.push($(this).val()); });
                    var total = plugins.length;
                    var processed = 0;
                    var success = 0;
                    $('#marrison-install-progress').slideDown();
                    $('#marrison-install-btn').prop('disabled', true).text('Installazione in corso...');
                    $('.marrison-plugin-cb').prop('disabled', true);
                    function processNext() {
                        if (processed >= total) {
                            $('#marrison-install-status').text('Completato!');
                            $('#marrison-install-info').text(success + ' su ' + total + ' plugin installati correttamente. Ricaricamento...');
                            setTimeout(function() { location.reload(); }, 1500);
                            return;
                        }
                        var slug = plugins[processed];
                        var percent = Math.round((processed / total) * 100);
                        $('#marrison-install-bar').css('width', percent + '%');
                        $('#marrison-install-info').text('Installazione di ' + slug + ' (' + (processed + 1) + '/' + total + ')...');
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'marrison_update_plugin_ajax',
                                slug: slug,
                                nonce: '<?php echo wp_create_nonce("marrison_bulk_update"); ?>'
                            },
                            success: function(response) {
                                if (response.success) { success++; } else { console.error('Errore installazione ' + slug + ':', response); }
                            },
                            error: function(xhr, status, error) { console.error('Errore AJAX ' + slug + ':', error); },
                            complete: function() {
                                processed++;
                                $('#marrison-install-bar').css('width', Math.round((processed / total) * 100) + '%');
                                processNext();
                            }
                        });
                    }
                    processNext();
                });
            });
            </script>
        </div>
        <?php
    }

    public function backup_page() {
        if (!\MarrisonCustomUpdater\MaintenanceClient\Settings::repository_config_managed()) {
            wp_safe_redirect(admin_url('admin.php?page=marrison-updater-settings&tab=client'));
            exit;
        }

        $restored = $_GET['restored'] ?? '';
        $this->cleanup_orphan_plugin_backups();
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugins = get_plugins();
        ?>
        <div class="mcu-wrap">
            <?php $this->render_header(__('Backup Disponibili', 'marrison-custom-updater')); ?>
            <div class="mcu-progress-container">
                <div class="mcu-progress-header">
                    <span id="mcu-progress-title"><?php esc_html_e('Ripristino in corso...', 'marrison-custom-updater'); ?></span>
                    <span id="mcu-progress-percentage"></span>
                </div>
                <div class="mcu-progress-track">
                    <div class="mcu-progress-bar"></div>
                </div>
                <div class="mcu-progress-status" id="mcu-progress-status-text"><?php esc_html_e('Inizializzazione...', 'marrison-custom-updater'); ?></div>
            </div>
            <?php if ($restored): ?>
                <div class="mcu-notice mcu-notice-success"><span class="dashicons dashicons-yes"></span> <?php printf(esc_html__('Plugin %s ripristinato con successo.', 'marrison-custom-updater'), esc_html($restored)); ?></div>
            <?php endif; ?>
            <?php 
            $backup_dir = WP_CONTENT_DIR . '/marrison-backups';
            $backups = [];
            $db_backups = [];
            $files_backups = [];
            if (is_dir($backup_dir)) {
                $all_files = array_merge(
                    glob($backup_dir . '/*-backup.zip') ?: [],
                    glob($backup_dir . '/db-backup-*.zip') ?: [],
                    glob($backup_dir . '/files-backup-*.zip') ?: [],
                    glob($backup_dir . '/files-backup-*.tar.gz') ?: []
                );
                $all_files = array_unique($all_files);
                usort($all_files, function($a, $b) { return filemtime($b) - filemtime($a); });
                foreach ($all_files as $file) {
                    $filename = basename($file);
                    if (strpos($filename, 'db-backup-') === 0) {
                        $db_backups[] = [
                            'file'     => $file,
                            'filename' => $filename,
                            'date'     => date('d/m/Y H:i', filemtime($file)),
                            'size'     => size_format(filesize($file)),
                            'integrity' => $this->get_db_backup_integrity_status($filename, $file),
                        ];
                        continue;
                    }
                    if (strpos($filename, 'files-backup-') === 0) {
                        $files_backups[] = [
                            'file'     => $file,
                            'filename' => $filename,
                            'date'     => date('d/m/Y H:i', filemtime($file)),
                            'size'     => size_format(filesize($file)),
                        ];
                        continue;
                    }
                    $slug = '';
                    $backup_version = 'N/A';
                    $type = 'plugin';
                    $type_label = __('Plugin', 'marrison-custom-updater');
                    $parse_name = $filename;
                    if (strpos($filename, 'theme-') === 0) {
                        $type = 'theme';
                        $type_label = __('Tema', 'marrison-custom-updater');
                        $parse_name = substr($filename, 6);
                    } elseif (strpos($filename, 'plugin-') === 0) {
                        $type = 'plugin';
                        $type_label = __('Plugin', 'marrison-custom-updater');
                        $parse_name = substr($filename, 7);
                    }
                    if (preg_match('/^(.*?)-v(.*?)-(\d{8})-(\d{6})-backup\.zip$/', $parse_name, $matches)) {
                        $slug = $matches[1];
                        $backup_version = $matches[2];
                    } elseif (preg_match('/^(.*?)-v(.*)-backup\.zip$/', $parse_name, $matches)) {
                        $slug = $matches[1];
                        $backup_version = $matches[2];
                    } elseif (preg_match('/^(.*?)-backup\.zip$/', $parse_name, $matches)) {
                        $slug = $matches[1];
                    } else {
                        if (preg_match('/^(.*)-v(.*)-(\d{8})-(\d{6})-backup\.zip$/', $filename, $matches)) {
                            $slug = $matches[1];
                            $backup_version = $matches[2];
                        } elseif (preg_match('/^(.*)-v(.*)-backup\.zip$/', $filename, $matches)) {
                            $slug = $matches[1];
                            $backup_version = $matches[2];
                        } else {
                            $slug = str_replace('-backup.zip', '', $filename);
                        }
                    }
                    $backups[] = [
                        'file' => $file,
                        'filename' => $filename,
                        'slug' => $slug,
                        'type' => $type,
                        'type_label' => $type_label,
                        'backup_version' => $backup_version,
                        'date' => date('d/m/Y H:i', filemtime($file)),
                        'size' => size_format(filesize($file))
                    ];
                }
            }
            ?>
            <div class="mcu-card" style="margin-bottom: 30px;">
                <div class="mcu-card-header">
                    <h2 class="mcu-card-title"><span class="dashicons dashicons-database"></span> <?php esc_html_e('Backup Database', 'marrison-custom-updater'); ?></h2>
                    <button type="button" id="marrison-db-backup-btn" class="mcu-button mcu-button-primary"
                        data-nonce="<?php echo wp_create_nonce('marrison_db_backup'); ?>">
                        <span class="dashicons dashicons-download"></span> <?php esc_html_e('Esegui Backup Database', 'marrison-custom-updater'); ?>
                    </button>
                </div>
                <span id="marrison-db-backup-result" style="padding: 0 20px; font-weight: 600;"></span>
                <?php if (!empty($db_backups)): ?>
                    <table class="mcu-table">
                        <thead><tr>
                            <th><?php esc_html_e('File', 'marrison-custom-updater'); ?></th>
                            <th><?php esc_html_e('Data', 'marrison-custom-updater'); ?></th>
                            <th><?php esc_html_e('Dimensione', 'marrison-custom-updater'); ?></th>
                            <th><?php esc_html_e('Verifica', 'marrison-custom-updater'); ?></th>
                            <th style="text-align:right;"><?php esc_html_e('Azione', 'marrison-custom-updater'); ?></th>
                        </tr></thead>
                        <tbody>
                            <?php foreach ($db_backups as $db): ?>
                                <tr>
                                    <td><span class="dashicons dashicons-database" style="color:#874abd;"></span> <?php echo esc_html($db['filename']); ?></td>
                                    <td><?php echo esc_html($db['date']); ?></td>
                                    <td><?php echo esc_html($db['size']); ?></td>
                                    <td>
                                        <span class="mcu-badge <?php echo esc_attr($db['integrity']['class']); ?>"><?php echo esc_html($db['integrity']['label']); ?></span>
                                        <div style="font-size:11px; color:#646970; margin-top:4px;"><?php echo esc_html($db['integrity']['detail']); ?></div>
                                    </td>
                                    <td style="text-align:right;">
                                        <?php
                                        $dl_url = wp_nonce_url(
                                            admin_url('admin-post.php?action=marrison_download_db_backup&file=' . urlencode($db['filename'])),
                                            'marrison_download_db_backup'
                                        );
                                        $delete_nonce = wp_create_nonce('marrison_delete_backup_' . $db['filename']);
                                        ?>
                                        <a href="<?php echo esc_url($dl_url); ?>" class="mcu-button mcu-button-secondary mcu-button-sm">
                                            <span class="dashicons dashicons-download"></span> <?php esc_html_e('Scarica', 'marrison-custom-updater'); ?>
                                        </a>
                                        <button type="button"
                                                class="mcu-button mcu-button-danger mcu-button-sm mcu-action-delete-backup"
                                                data-filename="<?php echo esc_attr($db['filename']); ?>"
                                                data-nonce="<?php echo esc_attr($delete_nonce); ?>">
                                            <span class="dashicons dashicons-trash"></span> <?php esc_html_e('Elimina', 'marrison-custom-updater'); ?>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="mcu-empty-state">
                        <span class="dashicons dashicons-database"></span>
                        <p><?php esc_html_e('Nessun backup database disponibile. Clicca il pulsante per crearne uno.', 'marrison-custom-updater'); ?></p>
                    </div>
                <?php endif; ?>
            </div>

            <div class="mcu-card" style="margin-bottom: 30px;">
                <div class="mcu-card-header">
                    <h2 class="mcu-card-title"><span class="dashicons dashicons-archive"></span> <?php esc_html_e('Backup File', 'marrison-custom-updater'); ?></h2>
                    <button type="button" id="marrison-files-backup-btn" class="mcu-button mcu-button-primary"
                        data-nonce="<?php echo wp_create_nonce('marrison_files_backup'); ?>">
                        <span class="dashicons dashicons-download"></span> <?php esc_html_e('Esegui Backup File', 'marrison-custom-updater'); ?>
                    </button>
                </div>
                <span id="marrison-files-backup-result" style="padding: 0 20px; font-weight: 600;"></span>
                <?php if (!empty($files_backups)): ?>
                    <table class="mcu-table">
                        <thead><tr>
                            <th><?php esc_html_e('File', 'marrison-custom-updater'); ?></th>
                            <th><?php esc_html_e('Data', 'marrison-custom-updater'); ?></th>
                            <th><?php esc_html_e('Dimensione', 'marrison-custom-updater'); ?></th>
                            <th style="text-align:right;"><?php esc_html_e('Azione', 'marrison-custom-updater'); ?></th>
                        </tr></thead>
                        <tbody>
                            <?php foreach ($files_backups as $backup): ?>
                                <tr>
                                    <td><span class="dashicons dashicons-archive" style="color:#0073aa;"></span> <?php echo esc_html($backup['filename']); ?></td>
                                    <td><?php echo esc_html($backup['date']); ?></td>
                                    <td><?php echo esc_html($backup['size']); ?></td>
                                    <td style="text-align:right;">
                                        <?php
                                        $dl_url = wp_nonce_url(
                                            admin_url('admin-post.php?action=marrison_download_files_backup&file=' . urlencode($backup['filename'])),
                                            'marrison_download_files_backup'
                                        );
                                        $delete_nonce = wp_create_nonce('marrison_delete_backup_' . $backup['filename']);
                                        ?>
                                        <a href="<?php echo esc_url($dl_url); ?>" class="mcu-button mcu-button-secondary mcu-button-sm">
                                            <span class="dashicons dashicons-download"></span> <?php esc_html_e('Scarica', 'marrison-custom-updater'); ?>
                                        </a>
                                        <button type="button"
                                                class="mcu-button mcu-button-danger mcu-button-sm mcu-action-delete-backup"
                                                data-filename="<?php echo esc_attr($backup['filename']); ?>"
                                                data-nonce="<?php echo esc_attr($delete_nonce); ?>">
                                            <span class="dashicons dashicons-trash"></span> <?php esc_html_e('Elimina', 'marrison-custom-updater'); ?>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="mcu-empty-state">
                        <span class="dashicons dashicons-archive"></span>
                        <p><?php esc_html_e('Nessun backup file disponibile. Clicca il pulsante per crearne uno.', 'marrison-custom-updater'); ?></p>
                    </div>
                <?php endif; ?>
            </div>

            <div class="mcu-card">
                <div class="mcu-card-header">
                    <h2 class="mcu-card-title"><span class="dashicons dashicons-list-view"></span> <?php esc_html_e('Lista Backup', 'marrison-custom-updater'); ?></h2>
                    <span class="mcu-badge mcu-badge-primary"><?php printf(esc_html__('%d Backup', 'marrison-custom-updater'), count($backups)); ?></span>
                </div>
                <?php if (!empty($backups)): ?>
                    <table class="mcu-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Elemento', 'marrison-custom-updater'); ?></th>
                                <th><?php esc_html_e('Versione Backup', 'marrison-custom-updater'); ?></th>
                                <th><?php esc_html_e('Versione Attuale', 'marrison-custom-updater'); ?></th>
                                <th><?php esc_html_e('Data Backup', 'marrison-custom-updater'); ?></th>
                                <th><?php esc_html_e('Dimensione', 'marrison-custom-updater'); ?></th>
                                <th style="text-align:right;"><?php esc_html_e('Azione', 'marrison-custom-updater'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($backups as $info): 
                                $plugin_name = $info['slug'];
                                $current_version = __('Non installato', 'marrison-custom-updater');
                                $version_class = '';
                                if (($info['type'] ?? 'plugin') === 'theme') {
                                    $theme = wp_get_theme($info['slug']);
                                    if (!$theme->exists()) {
                                        $installed_themes = wp_get_themes();
                                        foreach ($installed_themes as $theme_slug => $installed_theme) {
                                            if (strcasecmp($theme_slug, $info['slug']) === 0 || strcasecmp($installed_theme->get_stylesheet(), $info['slug']) === 0) {
                                                $theme = $installed_theme;
                                                break;
                                            }
                                        }
                                    }
                                    if ($theme->exists()) {
                                        $plugin_name = $theme->get('Name');
                                        $current_version = $theme->get('Version');
                                    }
                                } else {
                                    $found_file = $this->find_plugin_file($info['slug']);
                                    if ($found_file && isset($plugins[$found_file])) {
                                        $plugin_name = $plugins[$found_file]['Name'];
                                        $current_version = $plugins[$found_file]['Version'];
                                    }
                                }
                                if ($info['backup_version'] !== 'N/A' && $info['backup_version'] !== $current_version) {
                                    $version_class = 'color: var(--mcu-danger); font-weight: bold;';
                                }
                            ?>
                                <tr>
                                    <td>
                                        <strong><?php echo esc_html($plugin_name); ?></strong>
                                        <span class="mcu-badge" style="background:#e0e0e0; margin-left:5px;"><?php echo esc_html($info['type_label'] ?? 'Plugin'); ?></span>
                                        <div style="font-size:11px; color:#888;"><?php echo esc_html($info['slug']); ?></div>
                                    </td>
                                    <td>
                                        <span class="mcu-badge mcu-badge-primary">
                                            <?php echo esc_html($info['backup_version']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span style="<?php echo $version_class; ?>">
                                            <?php echo esc_html($current_version); ?>
                                        </span>
                                    </td>
                                    <td><?php echo esc_html($info['date']); ?></td>
                                    <td><?php echo esc_html($info['size']); ?></td>
                                    <td style="text-align:right;">
                                        <?php 
                                        $restore_nonce = wp_create_nonce('marrison_restore_' . $info['filename']); 
                                        $delete_nonce = wp_create_nonce('marrison_delete_backup_' . $info['filename']);
                                        $is_active_version = ($info['backup_version'] !== 'N/A' && $info['backup_version'] === $current_version);
                                        ?>
                                        <?php if ($is_active_version): ?>
                                            <button type="button" 
                                                    class="mcu-button mcu-button-secondary mcu-button-sm mcu-action-restore updated" 
                                                    disabled
                                                    style="opacity: 0.7; cursor: not-allowed;">
                                                <span class="dashicons dashicons-yes"></span> <?php esc_html_e('Ripristinato', 'marrison-custom-updater'); ?>
                                            </button>
                                        <?php else: ?>
                                            <button type="button" 
                                                    class="mcu-button mcu-button-secondary mcu-button-sm mcu-action-restore" 
                                                    data-filename="<?php echo esc_attr($info['filename']); ?>"
                                                    data-nonce="<?php echo esc_attr($restore_nonce); ?>">
                                                <span class="dashicons dashicons-undo"></span> <?php esc_html_e('Ripristina', 'marrison-custom-updater'); ?>
                                            </button>
                                        <?php endif; ?>
                                        <button type="button"
                                                class="mcu-button mcu-button-danger mcu-button-sm mcu-action-delete-backup"
                                                data-filename="<?php echo esc_attr($info['filename']); ?>"
                                                data-nonce="<?php echo esc_attr($delete_nonce); ?>">
                                            <span class="dashicons dashicons-trash"></span> <?php esc_html_e('Elimina', 'marrison-custom-updater'); ?>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="mcu-empty-state">
                        <span class="dashicons dashicons-backup"></span>
                        <p><?php esc_html_e('Nessun backup disponibile.', 'marrison-custom-updater'); ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    public function admin_page() {
        if (!\MarrisonCustomUpdater\MaintenanceClient\Settings::repository_config_managed()) {
            wp_safe_redirect(admin_url('admin.php?page=marrison-updater-settings&tab=client'));
            exit;
        }

        $updates     = $this->get_available_updates();
        $theme_updates = $this->get_available_theme_updates();
        $plugins     = get_plugins();
        $themes      = wp_get_themes();
        $updated     = $_GET['updated'] ?? '';
        $restored    = $_GET['restored'] ?? '';
        $bulkUpdated = $_GET['bulk_updated'] ?? [];
        if (!is_array($bulkUpdated)) $bulkUpdated = [$bulkUpdated];
        $settingsUpdated = $_GET['settings-updated'] ?? '';
        $repo_updates_count = 0;
        foreach($updates as $u) {
            $file = $this->find_plugin_file($u['slug'], $u['name'] ?? '');
            if ($this->mcu_is_plugin_update_excluded($u['slug'], $file, $u['name'] ?? '', $u)) {
                continue;
            }
            if ($file && isset($plugins[$file]) && version_compare(trim($plugins[$file]['Version']), trim($u['version']), '<')) {
                $repo_updates_count++;
            }
        }
        $theme_updates_count = 0;
        $installed_themes = wp_get_themes();
        foreach($theme_updates as $u) {
            $slug = $u['slug'];

            // Check exclusion
            if ($this->is_item_excluded($slug, 'theme')) {
                continue;
            }

            $theme = wp_get_theme($slug);
            if (!$theme->exists()) {
                foreach ($installed_themes as $t_slug => $t_obj) {
                    if (strcasecmp($t_obj->get('Name'), $u['name']) === 0 || $t_obj->get('TextDomain') === $slug) {
                        $theme = $t_obj;
                        break;
                    }
                }
            }
            if ($theme->exists() && version_compare($theme->get('Version'), $u['version'], '<')) {
                $theme_updates_count++;
            }
        }
        $total_updates = $repo_updates_count + $theme_updates_count;
        $repo_url_config = get_option('marrison_repo_url');
        $theme_repo_url_config = get_option('marrison_themes_repo_url');
        $transient_plugins = get_site_transient('update_plugins');
        $public_plugin_updates_count = 0;
        $private_updates_check = $this->get_available_updates();
        $private_slugs_check = [];
        $private_files_check = [];
        foreach ($private_updates_check as $u) {
            $private_slugs_check[] = $u['slug'];
            $found_file = $this->find_plugin_file($u['slug'], $u['name'] ?? '');
            if ($found_file) $private_files_check[] = $found_file;
        }
        $known_slugs_check = get_option('marrison_known_private_slugs', []);
        if (is_array($known_slugs_check)) {
            $private_slugs_check = array_unique(array_merge($private_slugs_check, $known_slugs_check));
        }
        if (!empty($transient_plugins->response)) {
            foreach ($transient_plugins->response as $file => $data) {
                // Check exclusion - use directory slug as primary, WordPress.org slug as fallback
                $slug = dirname($file);
                if ($slug === '.' || $slug === '') $slug = basename($file, '.php');
                
                // Also check WordPress.org slug for exclusion
                $wp_org_slug = isset($data->slug) ? $data->slug : $slug;
                $plugin_name = isset($plugins[$file]['Name']) ? $plugins[$file]['Name'] : $slug;
                
                if ($this->mcu_is_plugin_update_excluded($wp_org_slug, $file, $plugin_name, $data)) continue;

                if (in_array($file, $private_files_check)) continue;
                
                // Check if this is a premium/private plugin (not from WordPress.org)
                $is_premium = false;
                if (isset($plugins[$file])) {
                    $plugin_data = $plugins[$file];
                    // Premium plugins usually don't have WordPress.org plugin URI
                    if (!isset($plugin_data['PluginURI']) || 
                        strpos($plugin_data['PluginURI'], 'wordpress.org/plugins') === false) {
                        $is_premium = true;
                    }
                }
                
                // For premium plugins, count them directly
                if ($is_premium) {
                    $public_plugin_updates_count++;
                    continue;
                }
                
                // Regular WordPress.org plugins
                $check_slugs = [dirname($file), basename($file, '.php')];
                if (isset($data->slug)) $check_slugs[] = $data->slug;
                $found_private = false;
                foreach ($check_slugs as $s) {
                    if ($s !== '.' && $s !== '' && in_array($s, $private_slugs_check)) {
                        $found_private = true;
                        break;
                    }
                }
                if ($found_private) continue;
                $public_plugin_updates_count++;
            }
        }
        $transient_themes = get_site_transient('update_themes');
        $public_theme_updates_count = 0;
        $private_theme_slugs = [];
        foreach ($theme_updates as $u) {
            $private_theme_slugs[] = $u['slug'];
        }
        if (!empty($transient_themes->response)) {
            foreach ($transient_themes->response as $slug => $data) {
                // Check exclusion
                if ($this->is_item_excluded($slug, 'theme')) {
                    continue;
                }
                
                if (in_array($slug, $private_theme_slugs)) continue;
                $public_theme_updates_count++;
            }
        }
        include_once ABSPATH . 'wp-admin/includes/translation-install.php';
        $translation_updates = wp_get_translation_updates();
        $translation_updates_count = count($translation_updates);
        ?>
        <div class="mcu-wrap">
            <?php
            ob_start();
            ?>
            <button type="button" class="mcu-button mcu-button-primary mcu-action-update-all" style="margin-right: 10px;" 
                    data-nonce-auto="<?php echo wp_create_nonce('marrison_auto_update'); ?>"
                    data-nonce-bulk="<?php echo wp_create_nonce('marrison_bulk_update'); ?>"
                    data-nonce-all="<?php echo wp_create_nonce('marrison_update_all'); ?>">
                <span class="dashicons dashicons-update-alt"></span> <?php esc_html_e('Aggiorna tutto', 'marrison-custom-updater'); ?>
            </button>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display:inline;">
                <?php wp_nonce_field('marrison_clear_cache'); ?>
                <input type="hidden" name="action" value="marrison_clear_cache">
                <input type="hidden" name="redirect_to" value="<?php echo esc_url(admin_url('admin.php?page=marrison-updater&cache_cleared=1')); ?>">
                <button class="mcu-button mcu-button-secondary mcu-action-clear-cache">
                    <span class="dashicons dashicons-update"></span> <?php esc_html_e('Pulisci Cache', 'marrison-custom-updater'); ?>
                </button>
            </form>
            <?php
            $header_actions = ob_get_clean();
            $this->render_header('WP Master Updater', $header_actions);
            ?>
            <?php if ($settingsUpdated === 'saved'): ?>
                <div class="mcu-notice mcu-notice-success"><span class="dashicons dashicons-yes"></span> <?php esc_html_e('Impostazioni salvate correttamente.', 'marrison-custom-updater'); ?></div>
            <?php elseif ($settingsUpdated === 'removed'): ?>
                <div class="mcu-notice mcu-notice-success"><span class="dashicons dashicons-yes"></span> <?php esc_html_e('URL del repository ripristinato ai valori predefiniti.', 'marrison-custom-updater'); ?></div>
            <?php endif; ?>
            <?php if ($bulkUpdated): ?>
                <div class="mcu-notice mcu-notice-success"><span class="dashicons dashicons-yes"></span> <?php esc_html_e('Bulk update completato.', 'marrison-custom-updater'); ?></div>
            <?php endif; ?>
            <?php if (isset($_GET['cache_cleared'])): ?>
                <div class="mcu-notice mcu-notice-success"><span class="dashicons dashicons-yes"></span> <?php esc_html_e('Cache pulita con successo.', 'marrison-custom-updater'); ?></div>
            <?php endif; ?>
            <div class="mcu-progress-container">
                <div class="mcu-progress-header">
                    <span id="mcu-progress-title"><?php esc_html_e('Aggiornamento in corso...', 'marrison-custom-updater'); ?></span>
                    <span id="mcu-progress-percentage"></span>
                </div>
                <div class="mcu-progress-track">
                    <div class="mcu-progress-bar"></div>
                </div>
                <div class="mcu-progress-status" id="mcu-progress-status-text"><?php esc_html_e('Inizializzazione...', 'marrison-custom-updater'); ?></div>
            </div>
            <div class="mcu-dashboard-grid" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));">
                <div class="mcu-card mcu-stat-card">
                    <?php if (empty($repo_url_config)): ?>
                        <div class="mcu-stat-number" style="color: var(--mcu-warning);">
                            <span class="dashicons dashicons-warning" style="font-size: 36px; height: 36px; width: 36px;"></span>
                        </div>
                    <?php else: ?>
                        <div class="mcu-stat-number" style="color: <?php echo $repo_updates_count > 0 ? 'var(--mcu-danger)' : 'var(--mcu-success)'; ?>;">
                            <?php echo $repo_updates_count > 0 ? $repo_updates_count : '<span class="dashicons dashicons-yes" style="font-size: 36px; height: 36px; width: 36px;"></span>'; ?>
                        </div>
                    <?php endif; ?>
                    <div class="mcu-stat-label"><?php esc_html_e('Plugin Privati', 'marrison-custom-updater'); ?></div>
                </div>
                <div class="mcu-card mcu-stat-card">
                    <div class="mcu-stat-number" style="color: <?php echo $public_plugin_updates_count > 0 ? 'var(--mcu-danger)' : 'var(--mcu-success)'; ?>;">
                        <?php echo $public_plugin_updates_count > 0 ? $public_plugin_updates_count : '<span class="dashicons dashicons-yes" style="font-size: 36px; height: 36px; width: 36px;"></span>'; ?>
                    </div>
                    <div class="mcu-stat-label"><?php esc_html_e('Plugin Pubblici', 'marrison-custom-updater'); ?></div>
                </div>
                <div class="mcu-card mcu-stat-card">
                    <?php if (empty($theme_repo_url_config)): ?>
                        <div class="mcu-stat-number" style="color: var(--mcu-warning);">
                            <span class="dashicons dashicons-warning" style="font-size: 36px; height: 36px; width: 36px;"></span>
                        </div>
                    <?php else: ?>
                        <div class="mcu-stat-number" style="color: <?php echo $theme_updates_count > 0 ? 'var(--mcu-danger)' : 'var(--mcu-success)'; ?>;">
                            <?php echo $theme_updates_count > 0 ? $theme_updates_count : '<span class="dashicons dashicons-yes" style="font-size: 36px; height: 36px; width: 36px;"></span>'; ?>
                        </div>
                    <?php endif; ?>
                    <div class="mcu-stat-label"><?php esc_html_e('Temi Privati', 'marrison-custom-updater'); ?></div>
                </div>
                <div class="mcu-card mcu-stat-card">
                    <div class="mcu-stat-number" style="color: <?php echo $public_theme_updates_count > 0 ? 'var(--mcu-danger)' : 'var(--mcu-success)'; ?>;">
                        <?php echo $public_theme_updates_count > 0 ? $public_theme_updates_count : '<span class="dashicons dashicons-yes" style="font-size: 36px; height: 36px; width: 36px;"></span>'; ?>
                    </div>
                    <div class="mcu-stat-label"><?php esc_html_e('Temi Pubblici', 'marrison-custom-updater'); ?></div>
                </div>
                <div class="mcu-card mcu-stat-card">
                    <div class="mcu-stat-number" style="color: <?php echo $translation_updates_count > 0 ? 'var(--mcu-danger)' : 'var(--mcu-success)'; ?>;">
                        <?php echo $translation_updates_count > 0 ? $translation_updates_count : '<span class="dashicons dashicons-yes" style="font-size: 36px; height: 36px; width: 36px;"></span>'; ?>
                    </div>
                    <div class="mcu-stat-label"><?php esc_html_e('Traduzioni', 'marrison-custom-updater'); ?></div>
                </div>
            </div>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <?php wp_nonce_field('marrison_bulk_update'); ?>
                <input type="hidden" name="action" value="marrison_bulk_update">
                <div class="mcu-card" style="margin-bottom: 30px;">
                    <div class="mcu-card-header">
                        <h2 class="mcu-card-title"><span class="dashicons dashicons-admin-plugins"></span> <?php esc_html_e('Plugin Repository Privato', 'marrison-custom-updater'); ?></h2>
                        <?php if ($repo_updates_count > 0): ?>
                            <button type="button" class="mcu-button mcu-button-primary mcu-button-sm mcu-action-bulk-update-private" data-type="plugin"><?php esc_html_e('Aggiorna Selezionati', 'marrison-custom-updater'); ?></button>
                        <?php endif; ?>
                    </div>
                    <?php if (empty($updates)): ?>
                        <div class="mcu-empty-state">
                            <?php if (empty($repo_url_config)): ?>
                                <span class="dashicons dashicons-warning" style="color: var(--mcu-warning);"></span>
                                <p><?php esc_html_e('Repository non configurato.', 'marrison-custom-updater'); ?></p>
                                <a href="<?php echo admin_url('admin.php?page=marrison-updater-settings'); ?>" class="mcu-button mcu-button-secondary"><?php esc_html_e('Configura ora', 'marrison-custom-updater'); ?></a>
                            <?php else: ?>
                                <span class="dashicons dashicons-saved"></span>
                                <p><?php esc_html_e('Tutti i plugin privati sono aggiornati.', 'marrison-custom-updater'); ?></p>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <table class="mcu-table">
                            <thead>
                                <tr>
                                    <th style="width: 30px;"><input type="checkbox" id="cb-select-all-1"></th>
                                    <th><?php esc_html_e('Plugin', 'marrison-custom-updater'); ?></th>
                                    <th><?php esc_html_e('Versione', 'marrison-custom-updater'); ?></th>
                                    <th style="text-align:right;"><?php esc_html_e('Azione', 'marrison-custom-updater'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php 
                            $has_repo_updates = false;
                            foreach ($updates as $u):
                                $file = $this->find_plugin_file($u['slug'], $u['name'] ?? '');
                                if ($file && isset($plugins[$file])) {
                                    $data = $plugins[$file];
                                    $slug = $u['slug']; 
                                    $is_excluded = $this->mcu_is_plugin_update_excluded($slug, $file, $u['name'] ?? '', $u);
                                    $has_update = version_compare(trim($data['Version']), trim($u['version']), '<');
                                    if ($has_update && !$is_excluded) $has_repo_updates = true;
                                    $row_style = (!$has_update || $is_excluded) ? 'opacity: 0.6; background: #f9f9f9;' : '';
                            ?>
                                <tr style="<?php echo $row_style; ?>">
                                    <td>
                                        <?php if($has_update && !$is_excluded): ?>
                                            <?php 
                                            $nonce = ($slug === 'marrison-custom-updater') ? wp_create_nonce('marrison_update_marrison-custom-updater') : wp_create_nonce('marrison_update_' . $slug);
                                            ?>
                                            <input type="checkbox" name="plugins[]" value="<?php echo esc_attr($slug); ?>" data-nonce="<?php echo esc_attr($nonce); ?>">
                                        <?php elseif($is_excluded): ?>
                                            <span class="dashicons dashicons-hidden" style="color:var(--mcu-warning);" title="<?php esc_attr_e('Escluso dagli aggiornamenti', 'marrison-custom-updater'); ?>"></span>
                                        <?php else: ?>
                                            <span class="dashicons dashicons-yes" style="color:var(--mcu-success);"></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?php echo esc_html($u['name']); ?></strong>
                                        <div style="font-size:11px; color:#888;"><?php echo esc_html($slug); ?></div>
                                        <?php if($is_excluded): ?>
                                            <div style="margin-top: 4px;">
                                                <span class="mcu-badge mcu-badge-warning" style="font-size:10px;"><?php esc_html_e('Escluso', 'marrison-custom-updater'); ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="mcu-badge mcu-badge-<?php echo $has_update ? 'warning' : 'success'; ?>">
                                            <?php echo esc_html($data['Version']); ?>
                                        </span>
                                        <?php if($has_update): ?>
                                            <span class="dashicons dashicons-arrow-right-alt2" style="font-size:12px;vertical-align:middle;margin:0 5px;"></span>
                                            <span class="mcu-badge mcu-badge-success"><?php echo esc_html($u['version']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:right;">
                                        <?php if ($has_update && !$is_excluded): ?>
                                            <?php 
                                            $is_self_update = ($slug === 'marrison-custom-updater');
                                            $nonce = $is_self_update ? wp_create_nonce('marrison_update_marrison-custom-updater') : wp_create_nonce('marrison_update_' . $slug);
                                            ?>
                                            <button class="mcu-button mcu-button-primary mcu-button-sm mcu-action-update" 
                                                    data-slug="<?php echo esc_attr($slug); ?>"
                                                    data-version="<?php echo esc_attr($u['version']); ?>"
                                                    data-nonce="<?php echo esc_attr($nonce); ?>">
                                                <?php esc_html_e('Aggiorna', 'marrison-custom-updater'); ?>
                                            </button>
                                        <?php elseif($is_excluded): ?>
                                            <span style="color:var(--mcu-warning); font-size:12px; font-weight:500;"><?php esc_html_e('Escluso', 'marrison-custom-updater'); ?></span>
                                        <?php else: ?>
                                            <span style="color:var(--mcu-success); font-size:12px; font-weight:500;"><?php esc_html_e('Aggiornato', 'marrison-custom-updater'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php
                                }
                            endforeach; 
                            ?>
                            <?php if (!$has_repo_updates && !empty($updates)): ?>
                                <tr><td colspan="4" style="text-align:center; padding: 20px;"><?php esc_html_e('Tutti i plugin monitorati sono aggiornati.', 'marrison-custom-updater'); ?></td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
                <div class="mcu-card" style="margin-bottom: 30px;">
                    <div class="mcu-card-header">
                        <h2 class="mcu-card-title"><span class="dashicons dashicons-art"></span> <?php esc_html_e('Temi Repository Privato', 'marrison-custom-updater'); ?></h2>
                        <?php if ($theme_updates_count > 0): ?>
                            <button type="button" class="mcu-button mcu-button-primary mcu-button-sm mcu-action-bulk-update-private" data-type="theme"><?php esc_html_e('Aggiorna Selezionati', 'marrison-custom-updater'); ?></button>
                        <?php endif; ?>
                    </div>
                    <?php if (empty($theme_repo_url_config)): ?>
                        <div class="mcu-empty-state">
                            <span class="dashicons dashicons-warning" style="color: var(--mcu-warning);"></span>
                            <p><?php esc_html_e('Repository Temi non configurato.', 'marrison-custom-updater'); ?></p>
                            <a href="<?php echo admin_url('admin.php?page=marrison-updater-settings'); ?>" class="mcu-button mcu-button-secondary"><?php esc_html_e('Configura ora', 'marrison-custom-updater'); ?></a>
                        </div>
                    <?php else: ?>
                    <table class="mcu-table">
                        <thead>
                            <tr>
                                <th style="width: 30px;"><input type="checkbox" id="cb-select-all-themes"></th>
                                <th><?php esc_html_e('Tema', 'marrison-custom-updater'); ?></th>
                                <th><?php esc_html_e('Versione', 'marrison-custom-updater'); ?></th>
                                <th style="text-align:right;"><?php esc_html_e('Azione', 'marrison-custom-updater'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php 
                        $has_theme_updates = false;
                        foreach ($theme_updates as $u):
                            $slug = $u['slug'];
                            $is_excluded = $this->is_item_excluded($slug, 'theme');
                            $theme = wp_get_theme($slug);
                            if (!$theme->exists()) {
                                foreach ($installed_themes as $t_slug => $t_obj) {
                                    if (strcasecmp($t_obj->get('Name'), $u['name']) === 0 || $t_obj->get('TextDomain') === $slug) {
                                        $theme = $t_obj;
                                        $slug = $t_slug;
                                        break;
                                    }
                                }
                            }
                            if ($theme->exists()) {
                                $has_update = version_compare($theme->get('Version'), $u['version'], '<');
                                if ($has_update && !$is_excluded) $has_theme_updates = true;
                                $row_style = (!$has_update || $is_excluded) ? 'opacity: 0.6; background: #f9f9f9;' : '';
                        ?>
                            <tr style="<?php echo $row_style; ?>">
                                <td>
                                    <?php if($has_update && !$is_excluded): ?>
                                        <?php $nonce = wp_create_nonce('marrison_update_theme_' . $slug); ?>
                                        <input type="checkbox" name="themes[]" value="<?php echo esc_attr($slug); ?>" data-nonce="<?php echo esc_attr($nonce); ?>">
                                    <?php elseif($is_excluded): ?>
                                        <span class="dashicons dashicons-hidden" style="color:var(--mcu-warning);" title="<?php esc_attr_e('Escluso dagli aggiornamenti', 'marrison-custom-updater'); ?>"></span>
                                    <?php else: ?>
                                        <span class="dashicons dashicons-yes" style="color:var(--mcu-success);"></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($u['name']); ?></td>
                                <td>
                                    <span class="mcu-badge mcu-badge-<?php echo $has_update ? 'warning' : 'success'; ?>">
                                        <?php echo esc_html($theme->get('Version')); ?>
                                    </span>
                                    <?php if($has_update): ?>
                                        <span class="dashicons dashicons-arrow-right-alt2" style="font-size:12px;vertical-align:middle;margin:0 5px;"></span>
                                        <span class="mcu-badge mcu-badge-success"><?php echo esc_html($u['version']); ?></span>
                                    <?php endif; ?>
                                    <?php if($is_excluded): ?>
                                        <div style="margin-top: 4px;">
                                            <span class="mcu-badge mcu-badge-warning" style="font-size:10px;"><?php esc_html_e('Escluso', 'marrison-custom-updater'); ?></span>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:right;">
                                    <?php if ($has_update && !$is_excluded): ?>
                                        <?php $nonce = wp_create_nonce('marrison_update_theme_' . $slug); ?>
                                        <button type="button" class="mcu-button mcu-button-primary mcu-button-sm mcu-action-update" 
                                                data-slug="<?php echo esc_attr($slug); ?>"
                                                data-version="<?php echo esc_attr($u['version']); ?>"
                                                data-nonce="<?php echo esc_attr($nonce); ?>"
                                                data-type="theme">
                                            <?php esc_html_e('Aggiorna', 'marrison-custom-updater'); ?>
                                        </button>
                                    <?php elseif($is_excluded): ?>
                                        <span style="color:var(--mcu-warning); font-size:12px; font-weight:500;"><?php esc_html_e('Escluso', 'marrison-custom-updater'); ?></span>
                                    <?php else: ?>
                                        <span style="color:var(--mcu-success); font-size:12px; font-weight:500;"><?php esc_html_e('Aggiornato', 'marrison-custom-updater'); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php
                            }
                        endforeach; 
                        if (!$has_theme_updates): ?>
                            <tr><td colspan="4" style="text-align:center; padding: 20px;"><?php esc_html_e('Nessun aggiornamento temi disponibile.', 'marrison-custom-updater'); ?></td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </form>
            <?php 
                $auto_update_plugins = (array) get_site_option('auto_update_plugins', []);
                $transient = get_site_transient('update_plugins');
                $private_updates = $this->get_available_updates();
                $private_files = [];
                foreach ($private_updates as $u) {
                    $found_file = $this->find_plugin_file($u['slug'], $u['name'] ?? '');
                    if ($found_file) $private_files[] = $found_file;
                }
                $private_slugs = [];
                foreach ($private_files as $pf) {
                    $private_slugs[] = dirname($pf);
                    $private_slugs[] = basename($pf, '.php');
                }
                $private_slugs = array_values(array_filter(array_unique($private_slugs), function($s){
                    return $s !== '.' && $s !== '';
                }));
                $plugins_with_auto_update = [];
                if (!empty($transient->response)) {
                    foreach ($transient->response as $file => $data) {
                        // Use directory slug as primary, WordPress.org slug as fallback
                        $slug = dirname($file);
                        if ($slug === '.' || $slug === '') $slug = basename($file, '.php');
                        
                        // Also check WordPress.org slug for exclusion
                        $wp_org_slug = isset($data->slug) ? $data->slug : $slug;
                        
                        if (in_array($file, $private_files)) continue;
                        
                        // Check if this is a premium/private plugin (not from WordPress.org)
                        $is_premium = false;
                        if (isset($plugins[$file])) {
                            $plugin_data = $plugins[$file];
                            // Premium plugins usually don't have WordPress.org plugin URI
                            if (!isset($plugin_data['PluginURI']) || 
                                strpos($plugin_data['PluginURI'], 'wordpress.org/plugins') === false) {
                                $is_premium = true;
                            }
                        }
                        
                        // For premium plugins, we need different logic
                        if ($is_premium) {
                            // Include premium plugins in the list
                            $plugins_with_auto_update[$slug] = [
                                'name' => isset($plugins[$file]['Name']) ? $plugins[$file]['Name'] : $slug,
                                'current_version' => isset($plugins[$file]['Version']) ? $plugins[$file]['Version'] : '?',
                                'new_version' => $data->new_version ?? '?',
                                'is_premium' => true,
                                'wp_org_slug' => $wp_org_slug,
                                'file' => $file,
                            ];
                            continue;
                        }
                        
                        // Regular WordPress.org plugins
                        $check_slugs = [];
                        $check_slugs[] = dirname($file);
                        $check_slugs[] = basename($file, '.php');
                        if (isset($data->slug)) $check_slugs[] = $data->slug;
                        $found_private = false;
                        foreach ($check_slugs as $s) {
                            if ($s !== '.' && $s !== '' && in_array($s, $private_slugs)) {
                                $found_private = true;
                                break;
                            }
                        }
                        if ($found_private) continue;
                        
                        // Include all public plugins (even excluded ones)
                        $plugins_with_auto_update[$slug] = [
                            'name' => isset($plugins[$file]['Name']) ? $plugins[$file]['Name'] : $slug,
                            'current_version' => isset($plugins[$file]['Version']) ? $plugins[$file]['Version'] : '?',
                            'new_version' => $data->new_version ?? '?',
                            'is_premium' => false,
                            'wp_org_slug' => $wp_org_slug,
                            'file' => $file,
                        ];
                    }
                }
            ?>
            <div class="mcu-card">
                <div class="mcu-card-header">
                    <h2 class="mcu-card-title"><span class="dashicons dashicons-wordpress"></span> <?php esc_html_e('Plugin con Aggiornamenti', 'marrison-custom-updater'); ?></h2>
                    <?php if ($public_plugin_updates_count > 0): ?>
                        <?php $auto_update_nonce = wp_create_nonce('marrison_auto_update'); ?>
                        <button type="button" class="mcu-button mcu-button-primary mcu-button-sm mcu-action-auto-update" data-nonce="<?php echo esc_attr($auto_update_nonce); ?>">
                            <?php esc_html_e('Aggiorna Tutti', 'marrison-custom-updater'); ?>
                        </button>
                    <?php endif; ?>
                </div>
                <?php if (empty($plugins_with_auto_update)): ?>
                    <div class="mcu-empty-state">
                        <span class="dashicons dashicons-yes-alt"></span>
                        <p><?php esc_html_e('Tutti i plugin sono aggiornati.', 'marrison-custom-updater'); ?></p>
                    </div>
                <?php else: ?>
                    <table class="mcu-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Plugin', 'marrison-custom-updater'); ?></th>
                                <th><?php esc_html_e('Versione Attuale', 'marrison-custom-updater'); ?></th>
                                <th><?php esc_html_e('Nuova Versione', 'marrison-custom-updater'); ?></th>
                                <th><?php esc_html_e('Tipo', 'marrison-custom-updater'); ?></th>
                                <th><?php esc_html_e('Stato', 'marrison-custom-updater'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($plugins_with_auto_update as $slug => $info):
                                // Check exclusion using both directory slug and WordPress.org slug
                                $is_excluded = $this->mcu_is_plugin_update_excluded(
                                    $info['wp_org_slug'] ?? $slug,
                                    $info['file'] ?? '',
                                    $info['name'] ?? '',
                                    $info
                                );
                            ?>
                                <tr style="<?php echo $is_excluded ? 'opacity: 0.6; background: #f9f9f9;' : ''; ?>">
                                    <td>
                                        <?php echo esc_html($info['name']); ?>
                                        <?php if($is_excluded): ?>
                                            <div style="margin-top: 4px;">
                                                <span class="mcu-badge mcu-badge-warning" style="font-size:10px;"><?php esc_html_e('Escluso', 'marrison-custom-updater'); ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html($info['current_version']); ?></td>
                                    <td><span class="mcu-badge mcu-badge-primary"><?php echo esc_html($info['new_version']); ?></span></td>
                                    <td>
                                        <?php if(isset($info['is_premium']) && $info['is_premium']): ?>
                                            <span class="mcu-badge mcu-badge-warning" style="font-size:10px;"><?php esc_html_e('Premium', 'marrison-custom-updater'); ?></span>
                                        <?php else: ?>
                                            <span class="mcu-badge mcu-badge-success" style="font-size:10px;">WordPress.org</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if($is_excluded): ?>
                                            <span class="mcu-badge mcu-badge-warning"><?php esc_html_e('Escluso', 'marrison-custom-updater'); ?></span>
                                        <?php else: ?>
                                            <span class="mcu-badge mcu-badge-warning"><?php esc_html_e('In attesa', 'marrison-custom-updater'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            <div class="mcu-card" style="margin-top: 20px; padding: 15px;">
                <h3 style="margin: 0 0 15px 0;"><?php esc_html_e('Strumenti Aggiuntivi', 'marrison-custom-updater'); ?></h3>
                <div style="display:flex; gap: 10px;">
                    <?php $auto_update_nonce = wp_create_nonce('marrison_auto_update'); ?>
                    <button type="button" class="mcu-button mcu-button-secondary marrison-update-themes-btn" 
                            data-nonce="<?php echo esc_attr($auto_update_nonce); ?>">
                        <span class="dashicons dashicons-art"></span> <?php esc_html_e('Aggiorna tutti i temi', 'marrison-custom-updater'); ?>
                    </button>
                    <button type="button" class="mcu-button mcu-button-secondary marrison-update-translations-btn" 
                            data-nonce="<?php echo esc_attr($auto_update_nonce); ?>" style="margin-left: 10px;">
                        <span class="dashicons dashicons-translation"></span> <?php esc_html_e('Aggiorna tutte le traduzioni', 'marrison-custom-updater'); ?>
                    </button>
                </div>
            </div>
        </div>
        <?php
    }

    public function update_all_themes_ajax() {
        @ignore_user_abort(true);
        @set_time_limit(0);

        $nonce = $_POST['nonce'] ?? '';
        if (!wp_verify_nonce($nonce, 'marrison_auto_update') && !wp_verify_nonce($nonce, 'marrison_update_all')) {
            wp_send_json_error('Security check failed');
        }
        
        if (!current_user_can('update_themes')) {
            wp_send_json_error('Insufficient permissions');
        }
        include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        include_once ABSPATH . 'wp-admin/includes/theme.php';
        wp_update_themes();
        $current = get_site_transient('update_themes');
        if (empty($current->response)) {
             wp_send_json_error('Nessun aggiornamento temi disponibile');
        }
        
        $themes = [];
        foreach (array_keys($current->response) as $theme_slug) {
            if (!$this->is_item_excluded($theme_slug, 'theme')) {
                $themes[] = $theme_slug;
            }
        }
        
        if (empty($themes)) {
             wp_send_json_error('Nessun tema da aggiornare (esclusi o aggiornati)');
        }

        $lock = $this->mcu_acquire_update_lock('official_themes_bulk_ajax', ['themes' => $themes]);
        if (is_wp_error($lock)) {
            wp_send_json_error($lock->get_error_message());
        }
        $snapshot = $this->mcu_capture_active_plugin_snapshot([
            'operation' => 'official_themes_bulk_ajax',
            'themes'    => $themes,
        ]);

        foreach ($themes as $theme_slug) {
            $theme = wp_get_theme($theme_slug);
            $current_version = $theme->exists() ? $theme->get('Version') : '';
            $this->create_backup($theme_slug, $current_version, 'theme');
        }

        $skin = new Automatic_Upgrader_Skin();
        $upgrader = new Theme_Upgrader($skin);
        $result = $upgrader->bulk_upgrade($themes);
        if (is_wp_error($result)) {
            $this->mcu_log_event('error', 'official_themes_bulk_failed', ['error' => $result]);
            $this->mcu_flush_update_caches(['operation' => 'official_themes_bulk_ajax']);
            $this->mcu_restore_active_plugin_snapshot($snapshot, ['operation' => 'official_themes_bulk_ajax']);
            $this->mcu_release_update_lock($lock);
            wp_send_json_error($result->get_error_message());
        }

        $success_count = 0;
        if (is_array($result)) {
            foreach ($result as $theme_result) {
                if ($theme_result && !is_wp_error($theme_result)) {
                    $success_count++;
                }
            }
        }
        if ($success_count > 0) {
             $this->mcu_flush_update_caches(['operation' => 'official_themes_bulk_ajax']);
             $this->mcu_restore_active_plugin_snapshot($snapshot, ['operation' => 'official_themes_bulk_ajax']);
             $this->mcu_release_update_lock($lock);
             wp_send_json_success(sprintf('%d temi aggiornati con successo', $success_count));
        } else {
             $this->mcu_flush_update_caches(['operation' => 'official_themes_bulk_ajax']);
             $this->mcu_restore_active_plugin_snapshot($snapshot, ['operation' => 'official_themes_bulk_ajax']);
             $this->mcu_release_update_lock($lock);
             wp_send_json_error('Nessun tema aggiornato');
        }
    }

    public function update_translations_ajax() {
        check_ajax_referer('marrison_auto_update', 'nonce');
        if (!current_user_can('update_core')) {
            wp_send_json_error('Insufficient permissions');
        }

        // Increase execution time to avoid timeouts during multiple remote checks
        @set_time_limit(0);

        include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        include_once ABSPATH . 'wp-admin/includes/file.php';
        include_once ABSPATH . 'wp-admin/includes/misc.php';
        include_once ABSPATH . 'wp-admin/includes/template.php';
        include_once ABSPATH . 'wp-admin/includes/translation-install.php';
        
        // Force refresh of all update transients to ensure we get the latest translation data
        $this->mcu_flush_update_caches(['operation' => 'translations_precheck']);
        
        // Trigger checks
        wp_version_check();
        wp_update_plugins();
        wp_update_themes();
        
        $translations = wp_get_translation_updates();
        if (empty($translations)) {
             wp_send_json_error('Nessun aggiornamento traduzioni disponibile dopo il controllo forzato.');
        }

        $lock = $this->mcu_acquire_update_lock('translations_update_ajax', ['count' => count($translations)]);
        if (is_wp_error($lock)) {
            wp_send_json_error($lock->get_error_message());
        }
        $snapshot = $this->mcu_capture_active_plugin_snapshot([
            'operation' => 'translations_update_ajax',
            'count'     => count($translations),
        ]);

        $skin = new Automatic_Upgrader_Skin();
        $upgrader = new Language_Pack_Upgrader($skin);
        $result = $upgrader->bulk_upgrade($translations);
        if (is_wp_error($result)) {
            $this->mcu_log_event('error', 'translations_update_failed', ['error' => $result]);
            $this->mcu_flush_update_caches(['operation' => 'translations_update_ajax']);
            $this->mcu_restore_active_plugin_snapshot($snapshot, ['operation' => 'translations_update_ajax']);
            $this->mcu_release_update_lock($lock);
            wp_send_json_error($result->get_error_message());
        }
        
        $success_count = 0;
        if (is_array($result)) {
            foreach ($result as $trans_result) {
                if ($trans_result && !is_wp_error($trans_result)) {
                    $success_count++;
                }
            }
        }
        
        $this->mcu_flush_update_caches(['operation' => 'translations_update_ajax']);
        $this->mcu_restore_active_plugin_snapshot($snapshot, ['operation' => 'translations_update_ajax']);
        $this->mcu_release_update_lock($lock);

        if ($success_count > 0) {
             wp_send_json_success(sprintf('%d traduzioni aggiornate con successo', $success_count));
        } else {
             wp_send_json_success('Processo completato.');
        }
    }

    public function get_all_updates_data() {
        $private_updates = $this->get_available_updates();
        $plugins = get_plugins();
        $private_to_update = [];
        foreach ($private_updates as $u) {
            $file = $this->find_plugin_file($u['slug'], $u['name'] ?? '');
            if ($this->mcu_is_plugin_update_excluded($u['slug'], $file, $u['name'] ?? '', $u)) {
                continue;
            }

            if ($file && isset($plugins[$file]) && version_compare($plugins[$file]['Version'], $u['version'], '<')) {
                $private_to_update[] = [
                    'slug' => $u['slug'],
                    'name' => $u['name'],
                    'version' => $u['version']
                ];
            }
        }
        wp_update_plugins();
        $transient = get_site_transient('update_plugins');
        $official_to_update = [];
        $private_slugs = array_map(function($u) { return $u['slug']; }, $private_updates);
        $known_slugs = get_option('marrison_known_private_slugs', []);
        if (is_array($known_slugs)) {
            $private_slugs = array_unique(array_merge($private_slugs, $known_slugs));
        }
        $private_files = [];
        foreach ($private_updates as $u) {
             $found_file = $this->find_plugin_file($u['slug'], $u['name'] ?? '');
             if ($found_file) $private_files[] = $found_file;
        }
        if (!empty($transient->response)) {
            foreach ($transient->response as $file => $data) {
                if (in_array($file, $private_files)) continue;
                $slug = isset($data->slug) ? $data->slug : dirname($file);
                if ($slug === '.') $slug = basename($file, '.php');
                $plugin_name = isset($plugins[$file]['Name']) ? $plugins[$file]['Name'] : $slug;
                
                // Exclude if toggled off
                if ($this->mcu_is_plugin_update_excluded($slug, $file, $plugin_name, $data)) {
                    continue;
                }

                $check_slugs = [dirname($file), basename($file, '.php')];
                if (isset($data->slug)) $check_slugs[] = $data->slug;
                $is_private = false;
                foreach ($check_slugs as $s) {
                    if ($s !== '.' && $s !== '' && in_array($s, $private_slugs)) {
                        $is_private = true;
                        break;
                    }
                }
                if ($is_private) continue;
                $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $file);
                $official_to_update[] = [
                    'file' => $file,
                    'slug' => $slug,
                    'name' => $plugin_data['Name'] ?? $slug,
                    'version' => $data->new_version,
                    'package' => $data->package ?? '',
                    'url' => $data->url ?? ''
                ];
            }
        }
        wp_update_themes();
        $theme_updates_transient = get_site_transient('update_themes');
        $theme_updates_transient = $this->check_for_theme_updates($theme_updates_transient, true);
        set_site_transient('update_themes', $theme_updates_transient);
        
        // Filter themes count and collect data
        $themes_to_update = [];
        $themes_count = 0;
        if (!empty($theme_updates_transient->response)) {
             foreach ($theme_updates_transient->response as $slug => $data) {
                 if (!$this->is_item_excluded($slug, 'theme')) {
                     $themes_to_update[] = [
                         'slug' => $slug,
                         'version' => $data['new_version'] ?? ($data->new_version ?? ''),
                         'package' => $data['package'] ?? ($data->package ?? ''),
                         'url' => $data['url'] ?? ($data->url ?? '')
                     ];
                     $themes_count++;
                 }
             }
        }

        wp_version_check();
        include_once ABSPATH . 'wp-admin/includes/translation-install.php';
        $translation_updates = wp_get_translation_updates();
        $translations_count = count($translation_updates);
        return [
            'plugins_private' => $private_to_update,
            'plugins_official' => $official_to_update,
            'themes' => $themes_to_update,
            'themes_count' => $themes_count,
            'translations_count' => $translations_count
        ];
    }

    public function get_all_updates_ajax() {
        // Increase execution time and memory for this heavy operation
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');
        
        check_ajax_referer('marrison_update_all', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        $cleared_cron_events = $this->mcu_clear_master_update_cron_events();
        if ($cleared_cron_events > 0) {
            $this->mcu_log_event('info', 'dashboard_update_all_cron_cleared', [
                'cleared_cron_events' => $cleared_cron_events,
            ]);
        }
        $data = $this->get_all_updates_data();
        $data['cleared_cron_events'] = $cleared_cron_events;
        wp_send_json_success($data);
    }

    public function add_menu_notification_badge() {
        $update_count = get_option('marrison_available_updates_count', 0);
        if ($update_count > 0) {
            global $menu;
            foreach ($menu as $key => $item) {
                if (isset($item[2]) && $item[2] === 'marrison-updater') {
                    $menu[$key][0] .= ' <span class="marrison-update-badge awaiting-mod count-' . $update_count . '">' . $update_count . '</span>';
                    break;
                }
            }
        }
    }

    public function add_menu_badge_styles() {
        $icon_url = plugin_dir_url(__FILE__) . '../../assets/icon.svg';
        ?>
        <style>
        #toplevel_page_marrison-updater .wp-menu-image:before { display: none; }
        #toplevel_page_marrison-updater .wp-menu-image {
            background-color: currentColor;
            -webkit-mask-image: url('<?php echo esc_url($icon_url); ?>');
            mask-image: url('<?php echo esc_url($icon_url); ?>');
            -webkit-mask-repeat: no-repeat;
            mask-repeat: no-repeat;
            -webkit-mask-position: center;
            mask-position: center;
            -webkit-mask-size: 20px;
            mask-size: 20px;
        }
        .marrison-update-badge {
            display: inline-block;
            background-color: #d63638;
            color: #fff;
            font-size: 9px;
            line-height: 17px;
            font-weight: 600;
            margin: 1px 0 0 2px;
            vertical-align: top;
            -webkit-border-radius: 10px;
            border-radius: 10px;
            z-index: 26;
            min-width: 7px;
            padding: 0 6px;
            text-align: center;
        }
        #adminmenu .marrison-update-badge { position: relative; top: -1px; left: 2px; }
        #adminmenu .wp-submenu a[href="admin.php?page=marrison-updater"] { position: relative; }
        #adminmenu .wp-submenu a[href="admin.php?page=marrison-updater"]:after {
            content: "";
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            width: 8px;
            height: 8px;
            background-color: #d63638;
            border-radius: 50%;
            display: <?php echo get_option('marrison_available_updates_count', 0) > 0 ? 'block' : 'none'; ?>;
        }
        </style>
        <?php
    }
}
