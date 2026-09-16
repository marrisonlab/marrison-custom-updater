=== Marrison Custom Updater ===
Author: Angelo Marra
Author URI:  https://marrisonlab.com
Tags: updater, plugin-updates, custom repository, auto update
Requires at least: 6.0
Tested up to: 6.9.1
Requires PHP: 7.4
Stable tag: 9.8.13
License: GPL-3.0+
License URI: https://www.gnu.org/licenses/gpl-3.0.txt

Note: il backup completo dei file usa il formato tar.gz e richiede l'estensione PHP zlib.


== Description ==

**Marrison Custom Updater** è una soluzione avanzata per gestire aggiornamenti di plugin e temi privati in WordPress. Permette di collegare il tuo sito WordPress a un repository personalizzato, consentendo di distribuire aggiornamenti per i tuoi plugin e temi proprietari con la stessa facilità di quelli ufficiali di WordPress.org.

**Funzionalità Principali:**

*   **Repository Privato:** Collega il tuo sito a una fonte esterna per ricevere aggiornamenti per plugin e temi non presenti nella directory ufficiale.
*   **Gestione Aggiornamenti Unificata:** Visualizza e installa aggiornamenti per plugin e temi privati direttamente dalla dashboard.
*   **Sistema di Backup Integrato:** Esegue automaticamente backup di tutti i plugin e temi (privati e pubblici) prima dell'aggiornamento, permettendo il ripristino rapido (rollback) in caso di problemi.
*   **Pulizia Backup Orfani:** Rimuove automaticamente i backup dei plugin che non sono più installati sul sito.
*   **Aggiornamenti Automatici:** Configura aggiornamenti automatici programmati con giorno del mese dedicato per frequenze mensili e semestrali.
*   **Gestione Traduzioni:** Strumento dedicato per aggiornare le traduzioni dei plugin.
*   **Log e Debug:** Sistema di logging integrato per monitorare le operazioni di aggiornamento e cron job.
*   **Feedback Commander:** Gli update avviati da Commander espongono job, stage e callback finale firmato senza aggiungere agent o daemon residenti.
*   **Esclusione Plugin:** Possibilità di escludere specifici plugin dagli aggiornamenti automatici.

== Installation ==

1.  Carica la cartella `marrison-custom-updater` nella directory `/wp-content/plugins/` del tuo sito.
2.  Attiva il plugin dal menu 'Plugin' di WordPress.
3.  Configura gli URL dei repository privati per plugin e temi nelle impostazioni di Marrison Commander.

== Changelog ==

= 9.8.13 =
* **Fix**: Il controller action carica `Authenticator` e `Debug_Logger` anche nei percorsi WP-Cron/callback, evitando job Master falliti con errore "Class MarrisonCustomUpdater\MaintenanceClient\Authenticator not found".

= 9.8.12 =
* **Nuovo**: Lo status MCU espone le liste dettagliate `theme_updates` e `translation_updates`, cosi Commander puo mostrare quali temi e traduzioni devono essere aggiornati.

= 9.8.11 =
* **Fix**: Il bootstrap MCU carica `Authenticator` e `Debug_Logger` gia in `Plugin::init()`, cosi i job WP-Cron avviati da Commander possono firmare la callback finale senza errore "Class MarrisonCustomUpdater\MaintenanceClient\Authenticator not found".

= 9.8.10 =
* **Cambiamento**: Rimosso il fallback per slug privati gia normalizzati senza punti: gli update usano solo lo slug MCU canonico.
* **Cambiamento**: Le operation remote usano il payload strutturato `data`; `update_plugin` accetta il campo corrente `plugin_file` e gli alias operation legacy sono stati rimossi.
* **Cambiamento**: Le callback Commander inviano l'identificativo job solo dentro `job_status`, senza duplicarlo nel payload principale.

= 9.8.9 =
* **Fix**: Gli slug degli update privati mantengono i punti nelle risposte a Commander e nelle azioni firmate.
* **Fix**: Il runner riconosce anche slug gia normalizzati senza punti da versioni precedenti, evitando "Aggiornamento non trovato" sui pacchetti privati versionati.

= 9.8.8 =
* **Nuovo**: Feedback immediato per gli update avviati da Commander con `job_id`, `stage`, stato cron e callback finale firmato.
* **Miglioramento**: Lo stato del lock update espone lo stage corrente e lo aggiorna anche durante il throttle degli heartbeat.
* **Sicurezza**: Nessun daemon, agente residente o listener sempre attivo viene aggiunto ai siti client.

= 9.8.7 =
* **Fix**: Le esclusioni plugin vengono rispettate in modo coerente da UI, AJAX, Commander, update ufficiali, update privati e cron schedulati anche quando WordPress usa identificativi diversi per lo stesso plugin.
* **Fix**: "Aggiorna tutto" da Commander e dalla dashboard pulisce eventuali cron `mcu_master_update_event` residui prima di accodare un nuovo job, senza rimuovere la programmazione automatica `marrison_scheduled_update_event`.
* **Fix**: La disattivazione del debug remoto elimina i vecchi backup `wp-config.php.*-debug-backup-*` e conserva solo la copia piu recente.

= 9.8.6 =
* **Nuovo**: Gestione del debug WordPress da Marrison Commander con attivazione, disattivazione, lettura, download ed eliminazione del debug.log.
* **Sicurezza**: Operazioni debug disponibili solo tramite endpoint MCU firmati e autenticati, senza interfaccia o controlli esposti agli utenti del sito client.
* **Sicurezza**: Nessun daemon, agente residente, cron o polling viene aggiunto al sito client per questa funzione.

= 9.8.5 =
* **Cambiamento**: Gli URL dei repository privati per plugin e temi vengono gestiti centralmente da Marrison Commander e distribuiti ai client MCU tramite richieste autenticate.
* **Cambiamento**: Rimossa la modifica manuale degli URL dalla pagina impostazioni di MCU; gli option locali restano una cache operativa solo per siti autorizzati da Commander.
* **Sicurezza**: I siti non autorizzati non possono usare vecchi URL locali; la rimozione da Commander prova a revocare l'accesso al client MCU.

= 9.8.4 =
* **Fix**: Il backup database non fallisce piu sui siti con tabelle MyISAM o engine non transazionali: in questi casi MCU usa un lock di lettura sulle tabelle per creare un dump coerente.
* **Miglioramento**: Il manifest del backup database registra il metodo di snapshot usato, mantenendo le verifiche su dimensione SQL, hash SHA256, conteggi righe e ZIP.

= 9.8.3 =
* **Miglioramento**: Il backup file schedulato richiede automaticamente anche il backup database, cosi il set generato prima degli aggiornamenti resta sufficiente per il ripristino del sito WordPress.
* **Miglioramento**: I siti gia configurati con backup file attivo ma backup database disattivo eseguono comunque il backup DB prima degli aggiornamenti automatici.

= 9.8.2 =
* **Miglioramento**: La rotazione dei backup file completi mantiene solo l'ultimo set disponibile, riducendo l'accumulo in `wp-content/marrison-backups`.
* **Miglioramento**: Aggiunta la costante/filtro `MCU_FILES_BACKUP_MAX_SETS` / `mcu_files_backup_max_sets` per rialzare il limite su siti specifici.

= 9.8.1 =
* **Miglioramento**: Il backup file automatico e manuale ora viene limitato alla sola installazione WordPress: `wp-admin`, `wp-includes`, `wp-content` e file root standard.
* **Miglioramento**: Le cartelle extra alla radice dell'hosting, come sottodomini o materiale non WordPress, non vengono piu incluse nei backup file.

= 9.8.0 =
* **Nuovo**: Protocollo Maintenance 2 con operazioni read/write dichiarate nello status leggero.
* **Nuovo**: Operazioni diagnostiche read-only per manifest snapshot, moduli, diagnostica live singolo modulo, pagina e menu.
* **Nuovo**: Snapshot diagnostici post-manutenzione con fingerprint pre-update, ritardo distribuito e pipeline modulare a eventi singoli.
* **Sicurezza**: Registry esplicito `/action`, payload massimo 1 MB e sanitizzazione centralizzata di dati diagnostici.
* **Performance**: Nessun polling verso Commander, nessun collector sulle richieste normali e opzioni diagnostiche non autoload.

= 9.7.16 =
* **Nuovo**: Azione remota `cancel_master_update` per annullare da Master/Commander solo la richiesta update accodata dal Master
* **Sicurezza**: L'annullamento rimuove esclusivamente i cron `mcu_master_update_event` e non tocca la programmazione automatica MCU `marrison_scheduled_update_event`
* **Sicurezza**: Se il job risulta gia in esecuzione, MCU rimuove solo eventuali cron residui e non interrompe l'aggiornamento in corso
* **Sicurezza**: Un job Master annullato non viene eseguito anche se WP-Cron lo aveva gia letto prima della rimozione dalla coda

= 9.7.15 =
* **Fix**: Il pulsante "Elimina cron bloccato" torna visibile nella scheda Programmazione quando ci sono lock, richieste Master pendenti o log cron stale da pulire
* **Fix**: La pulizia manuale rimuove anche eventuali job Master `mcu_master_update_event` rimasti in WP-Cron e marca la richiesta Master come fallita
* **Miglioramento**: Dopo la pulizia il log viene marcato come azzerato manualmente, evitando che il pulsante resti visibile senza necessita

= 9.7.14 =
* **Nuovo**: Azione remota `force_sync` per forzare da Master/Commander il controllo aggiornamenti senza eseguire update
* **Miglioramento**: La sincronizzazione esplicita aggiorna cache WordPress, repository privati plugin/temi e traduzioni
* **Sicurezza**: Se un update e gia in corso, MCU rifiuta `force_sync` per evitare lavoro sovrapposto sul client

= 9.7.13 =
* **Nuovo**: Pulsante admin "Interrompi aggiornamento bloccato" nella scheda Programmazione, protetto da nonce e capability
* **Miglioramento**: Soglia heartbeat stale ridotta a 10 minuti per liberare prima i job Master interrotti
* **Fix**: Lo sblocco manuale chiude il log `started`, rimuove il lock update e marca la richiesta Master come fallita
* **Fix**: La pulizia cache non preserva piu lock update gia stale

= 9.7.12 =
* **Fix**: Failsafe di shutdown per chiudere come errore i job schedulati interrotti durante backup/update
* **Fix**: Rilascio immediato del lock update nello shutdown quando PHP riesce a completare la fase di arresto
* **Miglioramento**: Le richieste Master vengono marcate fallite se il job client si interrompe prima della risposta finale

= 9.7.11 =
* **Fix**: Recupero automatico dei log cron rimasti in stato `started` dopo un job interrotto o morto prima della chiusura
* **Fix**: I lock update scaduti o senza heartbeat vengono liberati alla successiva richiesta utile, incluso status Master e tentativi manuali
* **Miglioramento**: Le richieste Master bloccate da un vecchio job vengono marcate come stale/fallite invece di lasciare il sito in attesa indefinita
* **Sicurezza**: Nessun daemon, polling o traffico extra verso il Master; il recupero lavora solo durante admin/status/update gia richiesti

= 9.7.10 =
* **Nuovo**: Campo "Giorno del mese" nella programmazione automatica MCU per frequenze mensili e semestrali
* **Miglioramento**: Mensile e semestrale usano eventi calendariali singoli riprogrammati dopo l'esecuzione, senza daemon o polling ricorrente
* **Miglioramento**: Il payload Client espone al Master il giorno configurato nella frequenza MCU

= 9.7.9 =
* **Fix**: Lo stato repository temi non mostra piu la X rossa quando l URL e configurato e il repo risponde correttamente ma non ci sono temi aggiornabili
* **Fix**: Il salvataggio URL repository pulisce anche i transient di errore plugin/temi

= 9.7.8 =
* **Nuovo**: Accesso one-click dashboard dal Master con chiave dedicata separata dalla connessione status/update
* **Nuovo**: Endpoint Client `/dashboard-access` che genera link wp-admin temporanei e monouso
* **Miglioramento**: Il file configurazione MCU include i dati dashboard per import automatico sul Master
* **Sicurezza**: Nessun daemon, polling o carico ricorrente sui siti client; il link viene creato solo al click dal Master

= 9.7.7 =
* **Fix**: Il payload Client usato dal Master non conta i plugin esclusi in MCU, inclusi quelli provenienti dal transient WordPress.org
* **Miglioramento**: Matching esclusioni plugin piu robusto tra slug MCU, slug WordPress.org, cartella e file principale

= 9.7.6 =
* **Fix**: Lock aggiornamenti con heartbeat e recupero dei lock stale rimasti da richieste Master interrotte
* **Miglioramento**: Stato lock esposto al Master senza token o segreti

= 9.7.0 =
* **Nuovo**: Log mensili degli aggiornamenti scaricabili da Impostazioni > Log, con protezione nonce/capability e pulizia automatica
* **Sicurezza**: Lock globale per impedire aggiornamenti concorrenti da AJAX, manuale o cron
* **Sicurezza**: Snapshot dei plugin attivi prima dell'update e ripristino controllato dei plugin rimasti disattivati dopo il batch
* **Miglioramento**: Flush cache centralizzato dopo gli aggiornamenti: transient WordPress, cache plugin/temi/update, object cache, OPcache e page cache comuni
* **Fix**: Ridotto il rischio che WooCommerce e plugin dipendenti restino disattivati dopo una finestra temporanea di assenza durante l'aggiornamento
* **Debug**: Errori AJAX piu diagnostici e consultabili nei log

= 9.6.6 =
* **Sicurezza**: Backup database con scrittura SQL controllata byte per byte, manifest JSON e hash SHA256 dentro lo ZIP
* **Sicurezza**: Validazione dello ZIP dopo la compressione: il backup viene dichiarato riuscito solo se SQL, manifest, dimensione e hash coincidono
* **Sicurezza**: Gli aggiornamenti automatici vengono bloccati se un backup richiesto non viene completato e verificato
* **Compatibilita**: Il backup DB verificato richiede tabelle InnoDB; view, trigger o engine non transazionali vengono bloccati con errore esplicito
* **Fix critico**: Backup file piu severo sugli errori durante la scrittura tar.gz, evitando archivi potenzialmente corrotti dichiarati validi
* **Miglioramento**: Pagina Backup e report email indicano quando un backup database e stato verificato

= 9.6.5 =
* **Fix**: Backup database con ordinamento per chiave primaria quando disponibile e validazione interna del numero righe esportate per tabella
* **Miglioramento**: Backup file piu tollerante: file mancanti, non leggibili o cambiati durante il job vengono esclusi e riportati invece di interrompere tutto il processo

= 9.6.4 =
* **Nuovo**: Opzione per saltare i file piu grandi del limite della singola parte e continuare il backup file
* **Miglioramento**: Report manuale ed email schedulata indicano quanti file grandi sono stati saltati e la dimensione totale esclusa

= 9.6.3 =
* **Fix critico**: Backup file manuale eseguito come job AJAX a step, per ridurre timeout e interruzioni di connessione
* **Fix critico**: Backup file diviso automaticamente in parti `part001`, `part002`, ecc. sotto soglia, utile su hosting con limite di 1GB per file
* **Sicurezza**: Le parti restano temporanee finche non sono chiuse correttamente, evitando backup incompleti dichiarati validi

= 9.6.2 =
* **Fix critico**: Backup completo dei file generato in formato `tar.gz` streaming invece di ZIP, per evitare archivi troncati o corrotti sui siti grandi
* **Compatibilita**: I vecchi backup file `.zip` restano visibili, scaricabili e cancellabili dalla pagina Backup
* **Sicurezza**: Il backup file fallisce con errore se incontra file o directory non leggibili, evitando archivi incompleti dichiarati come riusciti

= 9.6.1 =
* **Fix**: Dump database piu affidabile per phpMyAdmin: preserva `AUTO_INCREMENT`, chiude con `COMMIT`, evita `LOCK TABLES` e divide gli `INSERT` in blocchi
* **Fix**: Validazione piu robusta del backup DB prima della creazione del file ZIP

= 9.6.0 =
* **Nuovo**: Backup completo dei file del sito
* **Nuovo**: Pulsante "Esegui Backup File" nella pagina Backup per backup on-demand
* **Nuovo**: Opzione in Impostazioni > Programmazione per backup automatico dei file prima degli aggiornamenti
* **Nuovo**: Link diretti in email per scaricare backup database e backup file
* **Nuovo**: Cancellazione dei singoli backup dalla pagina Backup
* **Miglioramento**: Rotazione automatica backup database/file limitata agli ultimi 3 archivi
* **Fix**: Dump database piu affidabile per phpMyAdmin: preserva `AUTO_INCREMENT`, chiude con `COMMIT`, evita `LOCK TABLES` e divide gli `INSERT` in blocchi
* **Fix**: Il backup file non usa più PclZip come fallback, evitando fatal error da memoria esaurita su siti grandi
* **Fix**: Download dei backup ZIP in streaming a blocchi per evitare errori di memoria su file grandi
* **Pulizia**: Rimossi residui JS/commenti di debug e corretta lettura date nella lista backup

= 9.5.9 =
* Rimosse tutte le dipendenze da key/Commander e semplificata la configurazione del plugin
* Ripulita la documentazione e l'interfaccia dalle sezioni di verifica licenza
* Aggiornata la localizzazione e i testi della dashboard

= 9.5.4 =
* **Fix**: frontend rallentato - aggiunto controllo is_admin() ai filtri aggiornamento
* **Miglioramento**: filtri update eseguiti solo in admin, non su frontend

= 9.5.3 =
* **Fix**: dashboard bloccata dopo migrazione - rimosso hook admin_init che causava timeout
* **Miglioramento**: controllo aggiornamenti già eseguito via filtri site_transient

= 9.5.2 =
* **Fix**: backup database compatibile con importazione phpMyAdmin
* **Fix**: gestione di AUTO_INCREMENT e formattazione SQL del dump
* **Fix**: fixato errore $wpdb->dbhost() e problemi di formattazione SQL

= 9.5.1 =
* **Miglioramento**: i file index.php del repository privato ora scansionano ricorsivamente le sottocartelle
* **Miglioramento**: è possibile organizzare plugin/temi in sottocartelle (es. per cliente o categoria)
* **Fix**: il download_url preserva il percorso relativo delle sottocartelle

= 9.5.0 =
* **Nuovo**: Backup completo del database (dump SQL compresso in .zip)
* **Nuovo**: Pulsante "Esegui Backup Database" nella pagina Backup per backup on-demand
* **Nuovo**: Opzione in Impostazioni > Programmazione per backup automatico del DB prima degli aggiornamenti
* **Nuovo**: Download diretto dei backup database dalla pagina Backup
* **Nuovo**: Rotazione automatica, mantiene gli ultimi 3 backup del database
* **Sicurezza**: backup salvati in wp-content/marrison-backups/ con protezione .htaccess

= 9.4.3 =
* **Fix critico**: Le email arrivavano con l'HTML grezzo visibile invece del contenuto renderizzato
* **Causa**: Plugin SMTP (WP Mail SMTP, FluentSMTP, ecc.) potevano resettare il content-type tramite `phpmailer_init`, forzando l'invio come `text/plain`
* **Fix**: Aggiunto filtro `wp_mail_content_type` e forzato `isHTML(true)` tramite `phpmailer_init` a priorità 999 prima di ogni invio, rimossi subito dopo
* **Impatto**: Le email HTML ora vengono renderizzate correttamente indipendentemente dal plugin SMTP attivo

= 9.4.2 =
* **Fix critico**: Risolto fallimento invio email sul 90% dei siti
* **Fix**: Sostituito indirizzo From fabbricato `no-reply@dominio.com` con reale `admin_email`
* **Causa**: La maggior parte degli hosting rifiuta email da indirizzi mittente non configurati (fallimenti SPF/DMARC)
* **Impatto**: Le notifiche email ora funzionano affidabilmente su tutti gli ambienti hosting

= 9.4.1 =
* **Correzione**: Pulsante "Pulisci Cache" ora funziona correttamente
* **Miglioramento**: Pulizia cache completa inclusa cache GitHub e WordPress
* **Miglioramento**: Forza ricaricamento aggiornamenti dal repository dopo pulizia
* **Correzione**: Handler JavaScript corretto per usare form submit invece di AJAX
* **Miglioramento**: Aggiunto dialogo di conferma per pulizia cache
* **Miglioramento**: Feedback visivo durante pulizia cache

= 9.4 =
* **Correzione critica**: Sistema di esclusioni completamente rinnovato
* **Nuovo sistema**: Gestione unificata per plugin premium/privati e WordPress.org
* **Miglioramento**: Riconoscimento automatico plugin premium tramite PluginURI
* **Miglioramento**: Sistema di slug duali per compatibilità con tutti i plugin
* **Correzione**: Badge "Escluso" ora funziona per tutti i tipi di plugin
* **Correzione**: Contatore principale esclude correttamente i plugin esclusi
* **Correzione**: Pulsante "Aggiorna tutto" rispetta le esclusioni
* **Correzione**: Ripristinati tutti i pulsanti JavaScript mancanti
* **Nuovo**: Handler per pulsante "Invia mail di test" nella programmazione
* **Nuovo**: Handler per pulsante "Pulisci Cache"
* **Nuovo**: Handler per pulsante "Aggiorna Tutti" plugin pubblici
* **Nuovo**: Handler per pulsante "Installa selezionati" e "Seleziona tutti"
* **Miglioramento**: Feedback visivo e toast notifications per tutti i pulsanti
* **Miglioramento**: Gestione errori e stati di caricamento per tutti i pulsanti

= 9.3 =
* **Feature:** Esteso il sistema di backup/restore a tutti i plugin e temi, inclusi quelli da repository pubbliche e private.
* **Feature:** Aggiunta pulizia automatica dei backup orfani (backup di plugin non più installati).
* **Improvement:** Supporto completo per plugin single-file e plugin in cartella nel sistema di backup/restore.
* **Fix:** Risolto il problema del pulsante "Aggiorna tutto" che non rispondeva dopo l'introduzione dei backup estesi.
* **Improvement:** Esteso il tempo di esecuzione per gli endpoint di aggiornamento massivo per gestire backup durante update multipli.

= 9.2 =
* UI: Centrati i pulsanti nella testata della pagina Aggiornamenti tra titolo e logo.
* UI: Standardizzati i colori dei pulsanti in tutto il plugin (normale: viola scuro, hover: rosa acceso).
* UI: Rimossi i contatori dei plugin nel repository (informazione privata) dalla dashboard e dalle impostazioni.
* UI: Spostato il blocco "Strumenti Avanzati" nella tab "Guida & Download".
* UI: Pulizia delle colonne nelle tabelle dei plugin e temi monitorati (rimosse colonne File, Slug e Stato).
* Fix: Risolto il problema del download del file index.php per il repository dei temi (percorsi assoluti e logica separata).
* Core: Introdotta costante globale `MCU_PLUGIN_DIR` per una gestione più robusta dei percorsi dei file.

= 9.1 =
* Feature: Aggiornamento alla versione 9.1 con miglioramenti generali di stabilità e prestazioni.
* Update: Versione stabile aggiornata per riflettere l'ultimo rilascio.

= 8.6 =
* Fix: Rimosso controllo conflitto con Marrison Custom Installer e reso plugin indipendente.
* Fix: Rinominato classe principale e trait in MCU_* per evitare collisioni di nomi.
* Fix: Eliminato notice di attivazione non voluto.

= 8.5 =
* Feature: Implementato aggiornamento forzato delle traduzioni (Core, Temi, Plugin) con pulizia profonda della cache e timeout esteso.
* Feature: Aggiunta automazione per l'aggiornamento del database di Elementor dopo l'aggiornamento del plugin.
* Feature: Integrato stato aggiornamento DB Elementor nel report email automatico.
* Fix: Aggiunto delay di sicurezza (3 secondi) prima del trigger DB Elementor per stabilità filesystem.

= 8.4 =
* UI: Aggiornati i nomi delle frequenze di aggiornamento (Giornaliera, Settimanale, Mensile, Semestrale) per una migliore coerenza.
* Fix: Migliorato il calcolo della prossima esecuzione programmata per rispettare correttamente la frequenza impostata (Giornaliera, Settimanale, Mensile, Semestrale).

= 8.3 =
* Fix: Risolto problema critico per cui l'aggiornamento automatico del plugin stesso (self-update) poteva causare la disattivazione del plugin. Implementata strategia di aggiornamento atomico con backup preventivo.

= 8.2.1 =
*   Email: Aggiunti banner di stato visivi (Verde/Rosso) per una rapida identificazione dell'esito.
*   Email: Migliorato il report con sezioni dettagliate per errori e aggiornamenti saltati.
*   Email: Ottimizzazione grafica (logo, footer pulito).
*   Core: Migliorata la gestione degli errori durante gli aggiornamenti (cattura codici errore WP_Error).
*   Core: Aggiunto controllo requisiti PHP prima dell'aggiornamento.
*   UI: Rimossa tab "About" dal pannello impostazioni.

= 8.2.0 =
*   Refactoring: Ristrutturazione completa del codice utilizzando Traits per migliorare modularità e stabilità.
*   Email: Grafica delle email di notifica rinnovata con design moderno e responsive.
*   Email: Aggiunto dettaglio versioni (Precedente -> Nuova) nel report degli aggiornamenti.
*   Fix: Risolti conflitti di ridefinizione funzioni con il core di WordPress.

= 8.1.5 =
*   Migliorata l'internazionalizzazione: rese traducibili le stringhe delle opzioni di pianificazione (Settimanale, Mensile, ecc.) e dei messaggi di test email.
*   Aggiornato il file .pot con le ultime stringhe.

= 8.1.4 =
*   Aggiunta opzione di pianificazione settimanale per gli aggiornamenti automatici.

= 8.1.3 =
*   FIX: Risolto problema di rilevamento aggiornamenti per WPCode Lite (insert-headers-and-footers) quando il plugin Marrison Custom Updater è attivo.
*   Migliorata la logica di esclusione dei plugin privati per evitare falsi positivi.

= 8.1.2 =
*   Migliorata la logica di rilevamento degli aggiornamenti per i plugin privati.
*   Aggiunta la possibilità di escludere i plugin privati installati dai controlli standard di WordPress per evitare conflitti.

= 8.1.1 =
*   Migliorata la gestione del cron job: aggiunti log dettagliati e gestione errori (try-catch) per evitare blocchi.
*   Corretto bug che impediva l'invio del report email se non c'erano aggiornamenti (ora invia sempre se programmato).
*   Risolto avviso PHP "Undefined variable" nel cron job.

= 8.1.0 =
*   Aggiunto pulsante per inviare email di test nelle impostazioni di pianificazione.
*   Migliorata l'interfaccia utente con feedback visivo (spinner, messaggi di successo/errore) per l'invio email.
*   Impostato header "From" corretto (no-reply@dominio) per le email inviate.
*   Ottimizzato il caricamento degli script JS nell'admin.

= 8.0.0 =
*   Rifattorizzazione completa del codice.
*   Nuova interfaccia utente a tab.
*   Migliorato il sistema di backup e rollback.
*   Supporto per aggiornamenti temi.
