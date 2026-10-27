jQuery(document).ready(function($) {

    function t(text) {
        return (window.mcuAdminTranslations && window.mcuAdminTranslations[text]) ? window.mcuAdminTranslations[text] : text;
    }

    function ajaxErrorMessage(xhr, status, error) {
        var parts = [];
        if (xhr && xhr.status) {
            parts.push('HTTP ' + xhr.status);
        }
        if (status) {
            parts.push(status);
        }
        if (error) {
            parts.push(error);
        }
        if (xhr && xhr.responseText) {
            var text = String(xhr.responseText).replace(/\s+/g, ' ').trim();
            if (text.length > 220) {
                text = text.substring(0, 220) + '...';
            }
            if (text) {
                parts.push(text);
            }
        }
        return parts.length ? parts.join(' - ') : t('Errore di connessione al server.');
    }

    function toggleMonthDayField() {
        var frequency = $('#marrison_auto_update_frequency').val();
        $('#marrison_auto_update_month_day_row').toggle(frequency === 'monthly' || frequency === 'biannual');
    }

    $('#marrison_auto_update_frequency').on('change', toggleMonthDayField);
    toggleMonthDayField();
    
    // UI Helpers
    const MCU = {
        showProgress: function(title) {
            $('.mcu-progress-container').slideDown();
            // Scroll to progress bar
            $('html, body').animate({
                scrollTop: $('.mcu-progress-container').offset().top - 50
            }, 500);
            
            $('#mcu-progress-title').text(title || t('Elaborazione in corso...'));
            this.updateProgress(0, t('Avvio...'));
        },
        
        hideProgress: function() {
            setTimeout(function() {
                $('.mcu-progress-container').slideUp();
            }, 2000);
        },
        
        updateProgress: function(percent, status) {
            $('.mcu-progress-bar').css('width', percent + '%');
            $('#mcu-progress-status-text').text(status);
        },

        toast: function(message, type) {
            // Simple toast implementation or fallback to alert/notice
            // For now, let's use the progress bar completion state as a "toast"
            // or append a notice to the header
            const noticeClass = type === 'success' ? 'mcu-notice-success' : 'mcu-notice-error';
            const icon = type === 'success' ? 'yes' : 'warning';
            
            const html = `
                <div class="mcu-notice ${noticeClass}" style="display:none">
                    <span class="dashicons dashicons-${icon}"></span>
                    ${message}
                    <button type="button" class="notice-dismiss" style="margin-left:auto;background:none;border:none;cursor:pointer;"><span class="dashicons dashicons-dismiss"></span></button>
                </div>
            `;
            
            const $notice = $(html);
            $('.mcu-header').after($notice);
            $notice.slideDown();
            
            setTimeout(() => {
                $notice.slideUp(() => $notice.remove());
            }, 5000);
        }
    };

    $(document).on('click', '.notice-dismiss', function() {
        $(this).closest('.mcu-notice').slideUp(() => $(this).closest('.mcu-notice').remove());
    });
        // --- Restore Backup Handler ---
    $(document).on('click', '.mcu-action-restore', function(e) {
        e.preventDefault();
        
        var $btn = $(this);
        var filename = $btn.data('filename');
        var nonce = $btn.data('nonce');
        
        if (!confirm(t('Sei sicuro di voler ripristinare questo backup? L\'attuale versione verrà sovrascritta.'))) {
            return;
        }

        $btn.prop('disabled', true).addClass('updating').html('<span class="dashicons dashicons-update-alt dashicons-spin"></span> ' + t('Ripristino...'));
        MCU.showProgress(t('Ripristino backup in corso...'));
        MCU.updateProgress(10, t('Preparazione ripristino...'));

        $.ajax({
            url: marrisonUpdater.ajaxurl,
            type: 'POST',
            data: {
                action: 'marrison_restore_plugin_ajax',
                filename: filename,
                nonce: nonce
            },
            success: function(response) {
                if (response.success) {
                    $btn.removeClass('updating').addClass('updated').prop('disabled', true).html('<span class="dashicons dashicons-yes"></span> ' + t('Ripristinato'));
                    MCU.updateProgress(100, t('Ripristino completato!'));
                    MCU.toast(t('Backup ripristinato con successo!'), 'success');
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    $btn.prop('disabled', false).removeClass('updating').html('<span class="dashicons dashicons-undo"></span> ' + t('Ripristina'));
                    MCU.updateProgress(100, t('Errore!'));
                    MCU.toast(t('Errore:') + ' ' + (response.data || t('Sconosciuto')), 'error');
                }
            },
            error: function() {
                $btn.prop('disabled', false).removeClass('updating').html('<span class="dashicons dashicons-undo"></span> ' + t('Ripristina'));
                MCU.updateProgress(100, t('Errore di connessione'));
                MCU.toast(t('Errore di connessione al server.'), 'error');
            }
        });
    });

    // --- Delete Backup Handler ---
    $(document).on('click', '.mcu-action-delete-backup', function(e) {
        e.preventDefault();

        var $btn = $(this);
        var filename = $btn.data('filename');
        var nonce = $btn.data('nonce');

        if (!confirm(t('Sei sicuro di voler eliminare questo backup? L\'operazione non può essere annullata.'))) {
            return;
        }

        $btn.prop('disabled', true).html('<span class="dashicons dashicons-update-alt dashicons-spin"></span> ' + t('Eliminazione...'));

        $.ajax({
            url: marrisonUpdater.ajaxurl,
            type: 'POST',
            data: {
                action: 'marrison_delete_backup',
                filename: filename,
                nonce: nonce
            },
            success: function(response) {
                if (response.success) {
                    MCU.toast(response.data.message || t('Backup eliminato correttamente.'), 'success');
                    $btn.closest('tr').fadeOut(250, function() {
                        $(this).remove();
                        if ($('.mcu-action-delete-backup').length === 0) {
                            location.reload();
                        }
                    });
                } else {
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-trash"></span> ' + t('Elimina'));
                    MCU.toast(t('Errore:') + ' ' + (response.data || t('Sconosciuto')), 'error');
                }
            },
            error: function() {
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-trash"></span> ' + t('Elimina'));
                MCU.toast(t('Errore di connessione al server.'), 'error');
            }
        });
    });

    // --- DB Backup Handler ---
    $(document).on('click', '#marrison-db-backup-btn', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var nonce = $btn.data('nonce');
        var $result = $('#marrison-db-backup-result');

        $btn.prop('disabled', true).html('<span class="dashicons dashicons-update-alt dashicons-spin"></span> ' + t('Backup in corso...'));
        $result.css('color', '#555').text('');

        $.ajax({
            url: marrisonUpdater.ajaxurl,
            type: 'POST',
            data: { action: 'marrison_db_backup', nonce: nonce },
            success: function(response) {
                if (response.success) {
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-download"></span> ' + t('Esegui Backup Database'));
                    $result.css('color', 'var(--mcu-success, #46b450)').text('✓ ' + response.data.message);
                    setTimeout(function() { location.reload(); }, 1500);
                } else {
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-download"></span> ' + t('Esegui Backup Database'));
                    $result.css('color', 'var(--mcu-danger, #d63638)').text('✗ ' + (response.data || t('Errore sconosciuto')));
                }
            },
            error: function() {
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-download"></span> ' + t('Esegui Backup Database'));
                $result.css('color', 'var(--mcu-danger, #d63638)').text('✗ ' + t('Errore di connessione al server.'));
            }
        });
    });

    // --- Chunked Files Backup Handler ---
    $(document).on('click', '#marrison-files-backup-btn', function(e) {
        e.preventDefault();
        e.stopImmediatePropagation();

        var $btn = $(this);
        var nonce = $btn.data('nonce');
        var $result = $('#marrison-files-backup-result');
        var jobId = '';

        $btn.prop('disabled', true).html('<span class="dashicons dashicons-update-alt dashicons-spin"></span> ' + t('Backup in corso...'));
        $result.css('color', '#555').text('');
        MCU.showProgress(t('Backup file in corso...'));
        MCU.updateProgress(0, t('Scansione file...'));

        function runBackupStep() {
            $.ajax({
                url: marrisonUpdater.ajaxurl,
                type: 'POST',
                data: { action: 'marrison_files_backup', nonce: nonce, job_id: jobId },
                success: function(response) {
                    if (!response.success) {
                        $btn.prop('disabled', false).html('<span class="dashicons dashicons-download"></span> ' + t('Esegui Backup File'));
                        $result.css('color', 'var(--mcu-danger, #d63638)').text('X ' + (response.data || t('Errore sconosciuto')));
                        MCU.updateProgress(100, t('Errore!'));
                        return;
                    }

                    var data = response.data || {};
                    jobId = data.job_id || jobId;
                    MCU.updateProgress(data.percent || 0, data.message || t('Backup file in corso...'));
                    $result.css('color', '#555').text(data.message || '');

                    if (data.done) {
                        $btn.prop('disabled', false).html('<span class="dashicons dashicons-download"></span> ' + t('Esegui Backup File'));
                        $result.css('color', 'var(--mcu-success, #46b450)').text('OK ' + data.message);
                        MCU.updateProgress(100, data.message || t('Backup completato!'));
                        setTimeout(function() { location.reload(); }, 1500);
                    } else {
                        setTimeout(runBackupStep, 500);
                    }
                },
                error: function() {
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-download"></span> ' + t('Esegui Backup File'));
                    $result.css('color', 'var(--mcu-danger, #d63638)').text('X ' + t('Errore di connessione al server.'));
                    MCU.updateProgress(100, t('Errore di connessione'));
                }
            });
        }

        runBackupStep();
    });

    // --- Files Backup Handler ---
    $(document).on('click', '#marrison-files-backup-btn', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var nonce = $btn.data('nonce');
        var $result = $('#marrison-files-backup-result');

        $btn.prop('disabled', true).html('<span class="dashicons dashicons-update-alt dashicons-spin"></span> ' + t('Backup in corso...'));
        $result.css('color', '#555').text('');

        $.ajax({
            url: marrisonUpdater.ajaxurl,
            type: 'POST',
            data: { action: 'marrison_files_backup', nonce: nonce },
            success: function(response) {
                if (response.success) {
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-download"></span> ' + t('Esegui Backup File'));
                    $result.css('color', 'var(--mcu-success, #46b450)').text('✓ ' + response.data.message);
                    setTimeout(function() { location.reload(); }, 1500);
                } else {
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-download"></span> ' + t('Esegui Backup File'));
                    $result.css('color', 'var(--mcu-danger, #d63638)').text('✗ ' + (response.data || t('Errore sconosciuto')));
                }
            },
            error: function() {
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-download"></span> ' + t('Esegui Backup File'));
                $result.css('color', 'var(--mcu-danger, #d63638)').text('✗ ' + t('Errore di connessione al server.'));
            }
        });
    });

    // --- Single Update Handler ---
    $('.mcu-action-update').on('click', function(e) {
        e.preventDefault();
        
        var $btn = $(this);
        var slug = $btn.data('slug');
        var nonce = $btn.data('nonce');
        var type = $btn.data('type') || 'plugin';
        var version = $btn.data('version');
        
        var action = (type === 'theme') ? 'marrison_update_private_theme_ajax' : 'marrison_update_plugin_ajax';
        var itemLabel = (type === 'theme') ? t('Tema') : t('Plugin');
        
        if($btn.prop('disabled')) return;

        // UI Updates
        $btn.prop('disabled', true).addClass('updating').html('<span class="dashicons dashicons-update-alt dashicons-spin"></span>');
        MCU.showProgress(t('Aggiornamento') + ' ' + itemLabel);
        
        // Fake progress simulation
        var progress = 0;
        var progressInterval = setInterval(function() {
            progress += Math.random() * 5; // Slower increment
            if (progress > 90) progress = 90;
            
            let statusText = t('Scaricamento pacchetto...');
            if (progress > 30) statusText = t('Estrazione file...');
            if (progress > 60) statusText = t('Installazione...');
            
            MCU.updateProgress(progress, statusText);
        }, 800); // Slower interval
        
        $.ajax({
            url: marrisonUpdater.ajaxurl,
            type: 'POST',
            data: {
                action: action,
                slug: slug,
                nonce: nonce
            },
            success: function(response) {
                clearInterval(progressInterval);
                MCU.updateProgress(100, t('Completato!'));
                
                if (response.success) {
                    $btn.removeClass('updating').addClass('updated').html('<span class="dashicons dashicons-yes"></span> ' + t('Aggiornato'));
                    $btn.closest('tr').find('.mcu-badge-warning').removeClass('mcu-badge-warning').addClass('mcu-badge-success').text(version);
                    // Remove arrow and new version badge
                    $btn.closest('tr').find('.dashicons-arrow-right-alt2, .mcu-badge-success').remove();
                    
                    MCU.toast(itemLabel + ' ' + t('aggiornato con successo!'), 'success');
                    
                    // Reload to reflect changes globally
                    setTimeout(function() {
                        location.reload();
                    }, 1000);
                } else {
                    $btn.prop('disabled', false).removeClass('updating').html(t('Riprova'));
                    MCU.updateProgress(100, t('Errore!'));
                    MCU.toast(t('Errore:') + ' ' + (response.data || t('Sconosciuto')), 'error');
                }
            },
            error: function(xhr, status, error) {
                clearInterval(progressInterval);
                $btn.prop('disabled', false).removeClass('updating').html(t('Riprova'));
                MCU.updateProgress(100, t('Errore di connessione'));
                MCU.toast(ajaxErrorMessage(xhr, status, error), 'error');
            }
        });
    });

    // --- Bulk Update Handler ---
    $('.mcu-action-bulk-update-private').on('click', function(e) {
        e.preventDefault();
        var type = $(this).data('type') || 'plugin'; // plugin or theme
        var selector = (type === 'theme') ? 'input[name="themes[]"]:checked' : 'input[name="plugins[]"]:checked';
        var selected = $(selector);
        
        if (selected.length === 0) {
            alert(t('Seleziona almeno un elemento da aggiornare.'));
            return;
        }

        if (!confirm(t('Sei sicuro di voler aggiornare') + ' ' + selected.length + ' ' + t('elementi?'))) {
            return;
        }

        MCU.showProgress(t('Aggiornamento massivo in corso...'));
        var total = selected.length;
        var processed = 0;
        var successCount = 0;
        var failCount = 0;

        // Disable buttons
        $('.mcu-action-bulk-update-private').prop('disabled', true);
        selected.prop('disabled', true);

        function processNext(index) {
            if (index >= total) {
                if (failCount > 0) {
                    MCU.updateProgress(100, successCount + ' OK, ' + failCount + ' errori. Controlla i log aggiornamenti.');
                    MCU.toast(successCount + ' OK, ' + failCount + ' errori. Controlla i log aggiornamenti.', 'error');
                } else {
                    MCU.updateProgress(100, t('Tutti gli aggiornamenti completati.'));
                }
                setTimeout(function() {
                    location.reload();
                }, 1000);
                return;
            }

            var $checkbox = $(selected[index]);
            var slug = $checkbox.val();
            var nonce = $checkbox.data('nonce');
            var action = (type === 'theme') ? 'marrison_update_private_theme_ajax' : 'marrison_update_plugin_ajax';

            MCU.updateProgress(Math.round((index / total) * 100), t('Aggiornamento:') + ' ' + slug + '...');

            $.ajax({
                url: marrisonUpdater.ajaxurl,
                type: 'POST',
                data: {
                    action: action,
                    slug: slug,
                    nonce: nonce
                },
                success: function(response) {
                    if (response.success) {
                        successCount++;
                    } else {
                        failCount++;
                        console.error('Update failed for ' + slug, response);
                    }
                },
                error: function(xhr, status, error) {
                    failCount++;
                    console.error('Connection error for ' + slug + ': ' + ajaxErrorMessage(xhr, status, error));
                },
                complete: function() {
                    processed++;
                    processNext(index + 1);
                }
            });
        }

        processNext(0);
    });

    // --- Update All (Repo + Public) ---
    $('.mcu-action-update-all').on('click', function(e) {
        e.preventDefault();
        
        var $btn = $(this);
        var nonceAll = $btn.attr('data-nonce-all');
        var nonceBulk = $btn.attr('data-nonce-bulk');
        var nonceAuto = $btn.attr('data-nonce-auto');
        
        if (!confirm(t('Sei sicuro di voler aggiornare tutti i plugin, temi e traduzioni?'))) {
            return;
        }

        $btn.prop('disabled', true).addClass('updating');
        MCU.showProgress(t('Controllo aggiornamenti in corso...'));
        MCU.updateProgress(5, t('Recupero lista aggiornamenti...'));

        $.ajax({
            url: marrisonUpdater.ajaxurl,
            type: 'POST',
            data: {
                action: 'marrison_get_all_updates_ajax',
                nonce: nonceAll
            },
            success: function(response) {
                if (!response.success) {
                    MCU.updateProgress(100, t('Errore:') + ' ' + (response.data || t('Sconosciuto')));
                    MCU.toast(t('Errore durante il recupero degli aggiornamenti'), 'error');
                    $btn.prop('disabled', false).removeClass('updating');
                    return;
                }

                var data = response.data;
                var queue = [];

                // 1. Private Plugins
                if (data.plugins_private && data.plugins_private.length > 0) {
                    data.plugins_private.forEach(function(plugin) {
                        queue.push({
                            type: 'plugin_private',
                            label: t('Plugin Privato:') + ' ' + (plugin.name || plugin.slug),
                            slug: plugin.slug,
                            nonce: nonceBulk
                        });
                    });
                }

                // 2. Official Plugins
                if (data.plugins_official && data.plugins_official.length > 0) {
                    data.plugins_official.forEach(function(plugin) {
                        queue.push({
                            type: 'plugin_official',
                            label: t('Plugin Ufficiale:') + ' ' + (plugin.name || plugin.slug),
                            file: plugin.file,
                            package: plugin.package,
                            new_version: plugin.version,
                            nonce: nonceAuto
                        });
                    });
                }

                // 3. Themes
                if (data.themes_count > 0) {
                    queue.push({
                        type: 'themes',
                        label: t('Temi') + ' (' + data.themes_count + ')',
                        nonce: nonceAll // or nonceAuto depending on backend
                    });
                }

                // 4. Translations
                if (data.translations_count > 0) {
                    queue.push({
                        type: 'translations',
                        label: t('Traduzioni') + ' (' + data.translations_count + ')',
                        nonce: nonceAuto
                    });
                }

                if (queue.length === 0) {
                    MCU.updateProgress(100, t('Nessun aggiornamento necessario.'));
                    MCU.toast(t('Tutto aggiornato!'), 'success');
                    $btn.prop('disabled', false).removeClass('updating');
                    setTimeout(function() {
                        location.reload();
                    }, 1000);
                    return;
                }

                // Process Queue
                var total = queue.length;
                var processed = 0;
                var successCount = 0;
                var failCount = 0;

                function processQueue(index) {
                    if (index >= total) {
                        if (failCount > 0) {
                            MCU.updateProgress(100, successCount + ' OK, ' + failCount + ' errori. Controlla i log aggiornamenti.');
                            MCU.toast(successCount + ' OK, ' + failCount + ' errori. Controlla i log aggiornamenti.', 'error');
                        } else {
                            MCU.updateProgress(100, t('Tutti gli aggiornamenti completati!'));
                            MCU.toast(t('Aggiornamento massivo completato.'), 'success');
                        }
                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                        return;
                    }

                    var item = queue[index];
                    var progress = Math.round(((index + 1) / total) * 100);
                    MCU.updateProgress(progress, t('Aggiornamento in corso:') + ' ' + item.label + '...');

                    var ajaxData = {};
                    
                    if (item.type === 'plugin_private') {
                        ajaxData = {
                            action: 'marrison_update_plugin_ajax',
                            slug: item.slug,
                            nonce: item.nonce
                        };
                    } else if (item.type === 'plugin_official') {
                        ajaxData = {
                            action: 'marrison_update_official_plugin_ajax',
                            file: item.file,
                            package: item.package,
                            new_version: item.new_version,
                            nonce: item.nonce
                        };
                    } else if (item.type === 'themes') {
                        ajaxData = {
                            action: 'marrison_update_all_themes_ajax',
                            nonce: item.nonce
                        };
                    } else if (item.type === 'translations') {
                        ajaxData = {
                            action: 'marrison_update_translations_ajax',
                            nonce: item.nonce
                        };
                    }

                    $.ajax({
                        url: marrisonUpdater.ajaxurl,
                        type: 'POST',
                        data: ajaxData,
                        success: function(res) {
                            if (res.success) {
                                successCount++;
                            } else {
                                failCount++;
                                console.error('Update failed for ' + item.label, res);
                            }
                        },
                        error: function(xhr, status, error) {
                            failCount++;
                            console.error('Connection error for ' + item.label + ': ' + ajaxErrorMessage(xhr, status, error));
                        },
                        complete: function() {
                            processed++;
                            processQueue(index + 1);
                        }
                    });
                }

                processQueue(0);
            },
            error: function(xhr, status, error) {
                MCU.updateProgress(100, t('Errore di connessione'));
                MCU.toast(ajaxErrorMessage(xhr, status, error), 'error');
                $btn.prop('disabled', false).removeClass('updating');
            }
        });
    });

    // --- Clear Cache ---
    // Handled by form submit in PHP

    // --- Checkbox Select All ---
    $('#cb-select-all-1').on('change', function() {
        $('input[name="plugins[]"]').prop('checked', $(this).is(':checked'));
    });
    
    $('#cb-select-all-themes').on('change', function() {
        $('input[name="themes[]"]').prop('checked', $(this).is(':checked'));
    });

    // --- Exclusion Toggle Handler ---
    $(document).on('change', '.marrison_exclude_toggle', function() {
        const $toggle = $(this);
        const slug = $toggle.data('slug');
        const type = $toggle.data('type');
        const isChecked = $toggle.is(':checked');
        
        $toggle.prop('disabled', true);
        
        $.ajax({
            url: marrisonUpdater.ajaxurl,
            type: 'POST',
            data: {
                action: 'marrison_toggle_exclusion',
                nonce: marrisonUpdater.toggle_exclusion_nonce,
                slug: slug,
                type: type,
                state: isChecked ? 1 : 0
            },
            success: function(response) {
                $toggle.prop('disabled', false);
                if (response.success) {
                    MCU.toast(t('Impostazione salvata:') + ' ' + (isChecked ? t('Escluso') : t('Incluso')), 'success');
                    // Optionally reload or update counters if needed immediately
                    // For now, a toast is enough feedback
                } else {
                    $toggle.prop('checked', !isChecked); // Revert
                    MCU.toast(t('Errore nel salvataggio'), 'error');
                }
            },
            error: function() {
                $toggle.prop('disabled', false);
                $toggle.prop('checked', !isChecked); // Revert
                MCU.toast(t('Errore di connessione'), 'error');
            }
        });
    });

    // Auto update (public plugins) handler
    $('.mcu-action-auto-update').on('click', function(e) {
        e.preventDefault();
        
        var $btn = $(this);
        var nonce = $btn.data('nonce');
        
        if (!confirm(t('Sei sicuro di voler aggiornare tutti i plugin pubblici?'))) {
            return;
        }
        
        $btn.prop('disabled', true).text(t('Aggiornamento in corso...'));
        MCU.showProgress(t('Aggiornamento plugin pubblici...'));
        MCU.updateProgress(10, t('Inizio aggiornamento...'));
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'marrison_auto_update',
                nonce: nonce
            },
            success: function(response) {
                if (response.success) {
                    MCU.updateProgress(100, t('Aggiornamento completato!'));
                    MCU.toast(t('Plugin pubblici aggiornati con successo'), 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    MCU.updateProgress(100, t('Errore:') + ' ' + (response.data || t('Sconosciuto')));
                    MCU.toast(t('Errore durante l\'aggiornamento'), 'error');
                }
                $btn.prop('disabled', false).text(t('Aggiorna Tutti'));
            },
            error: function(xhr, status, error) {
                MCU.updateProgress(100, t('Errore di connessione'));
                MCU.toast(ajaxErrorMessage(xhr, status, error), 'error');
                $btn.prop('disabled', false).text(t('Aggiorna Tutti'));
            }
        });
    });

    // Clear cache handler
    $('.mcu-action-clear-cache').on('click', function(e) {
        e.preventDefault();
        
        var $form = $(this).closest('form');
        var $btn = $(this);
        
        // Show confirmation dialog
        if (!confirm(t('Sei sicuro di voler pulire la cache? Questo ricaricherà la lista degli aggiornamenti dal repository.'))) {
            return;
        }
        
        $btn.prop('disabled', true).text(t('Pulizia in corso...'));
        
        // Submit the form normally (not AJAX)
        $form.submit();
    });

    // Install selected handler
    $('#marrison-install-btn').on('click', function(e) {
        e.preventDefault();
        
        var selected = $('input[name="plugins[]"]:checked');
        if (selected.length === 0) {
            MCU.toast(t('Seleziona almeno un plugin da installare'), 'warning');
            return;
        }
        
        if (!confirm(t('Sei sicuro di voler installare i plugin selezionati?'))) {
            return;
        }
        
        var $btn = $(this);
        var plugins = [];
        selected.each(function() {
            plugins.push($(this).val());
        });
        
        $btn.prop('disabled', true).text(t('Installazione in corso...'));
        MCU.showProgress(t('Installazione plugin...'));
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'marrison_install_plugins',
                plugins: plugins,
                nonce: $('#_wpnonce').val()
            },
            success: function(response) {
                if (response.success) {
                    MCU.updateProgress(100, t('Installazione completata!'));
                    MCU.toast(t('Plugin installati con successo'), 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    MCU.updateProgress(100, t('Errore:') + ' ' + (response.data || t('Sconosciuto')));
                    MCU.toast(t('Errore durante l\'installazione'), 'error');
                }
                $btn.prop('disabled', false).text(t('Installa selezionati'));
            },
            error: function() {
                MCU.updateProgress(100, t('Errore di connessione'));
                MCU.toast(t('Errore di connessione'), 'error');
                $btn.prop('disabled', false).text(t('Installa selezionati'));
            }
        });
    });

    // Select all checkbox handler
    $('#marrison-select-all').on('change', function() {
        var checked = $(this).prop('checked');
        $('input[name="plugins[]"]').prop('checked', checked);
    });

    // Test email functionality
    $('#marrison_test_email_btn').on('click', function(e) {
        e.preventDefault();
        
        var $btn = $(this);
        var $result = $('#marrison_test_email_result');
        var email = $('#marrison_auto_update_email').val();
        
        if (!email) {
            $result.css('color', 'red').text(t('Inserisci un indirizzo email'));
            return;
        }
        
        $btn.prop('disabled', true).text(t('Invio in corso...'));
        $result.css('color', '#666').text('');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'marrison_test_email',
                nonce: $btn.data('nonce'),
                email: email
            },
            success: function(response) {
                $btn.prop('disabled', false).text(t('Invia mail di test'));
                
                if (response.success) {
                    $result.css('color', 'green').text('✓ ' + response.data);
                    MCU.toast(t('Email di test inviata con successo'), 'success');
                } else {
                    $result.css('color', 'red').text('✗ ' + response.data);
                    MCU.toast(t('Errore nell\'invio dell\'email'), 'error');
                }
            },
            error: function() {
                $btn.prop('disabled', false).text(t('Invia mail di test'));
                $result.css('color', 'red').text('✗ ' + t('Errore di connessione'));
                MCU.toast(t('Errore di connessione'), 'error');
            }
        });
    });

});
