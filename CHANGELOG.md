# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [9.8.15] - 2026-09-16

### Added
- Aggiunta l'operazione remota firmata `update_theme`, cosi Marrison Commander puo aggiornare un singolo tema tramite MCU come gia avviene per i plugin.

### Fixed
- Gli aggiornamenti tema avviati da Commander supportano sia temi privati MCU sia temi ufficiali WordPress, rispettando le esclusioni configurate.
- Il refresh forzato degli update tema ricostruisce il transient anche nei percorsi non-admin, evitando che WP-Cron/Commander non vedano un tema ancora disponibile dopo il job.

## [9.8.14] - 2026-09-16

### Fixed
- Lo status MCU non dipende piu dal transient `marrison_available_updates_v2`/`marrison_available_theme_updates`: se la cache e assente viene ricostruita dal repository privato (rispettando il breaker da 5 minuti), cosi un cache-wipe non puo piu far apparire il sito "tutto aggiornato" mentre gli aggiornamenti sono ancora pendenti.
- Il percorso di update non cancella piu le liste del repository privato (`clear_update_caches` con `$include_repository_lists = false` in `queue_update`/`run_queued_update`): il job accodato parte con i metadati necessari invece di applicare zero aggiornamenti.
- Il job accodato azzera i breaker `marrison_updates_fetch_failed`/`marrison_theme_updates_fetch_failed` e riscarica le liste subito prima dell'esecuzione (`prepare_repository_metadata_for_update`), evitando aggiornamenti a vuoto per un flag di fetch fallito ancora attivo.

### Changed
- L'esito del job Master non e piu dedotto da un fallback incondizionato a `completed`: `resolve_queued_update_outcome` marca `failed` (stage `repository_unavailable`) quando non e stato applicato nulla e i metadati del repository non erano disponibili.
- `marrison_last_cron_log` registra i conteggi di plugin/temi/traduzioni aggiornati e di aggiornamenti falliti/saltati, usati per determinare l'esito reale del job.
- `Actions_Controller::fetch_private_repo_updates()` e `public` per consentire allo status di ricostruire le liste MCU.

## [9.8.13] - 2026-09-16

### Fixed
- Il controller action carica `Authenticator` e `Debug_Logger` anche nei percorsi WP-Cron/callback, evitando job Master falliti con errore "Class MarrisonCustomUpdater\MaintenanceClient\Authenticator not found".

## [9.8.12] - 2026-09-15

### Added
- Lo status MCU espone le liste dettagliate `theme_updates` e `translation_updates`, cosi Commander puo mostrare nel Sommario quali temi e traduzioni devono essere aggiornati.

## [9.8.11] - 2026-09-15

### Fixed
- Il bootstrap MCU carica `Authenticator` e `Debug_Logger` gia in `Plugin::init()`, cosi i job WP-Cron avviati da Commander possono firmare la callback finale senza errore "Class MarrisonCustomUpdater\MaintenanceClient\Authenticator not found".

## [9.8.10] - 2026-09-15

### Changed
- Rimosso il fallback per slug privati gia normalizzati senza punti e mantenuto solo il match esatto sullo slug MCU canonico.
- Allineato `/action` al payload strutturato `data`, rimuovendo i campi duplicati top-level usati per compatibilita.
- Le callback verso Commander inviano l'identificativo job solo dentro `job_status`, senza duplicarlo nel payload principale.
- Rimossi l'alias remoto `file` per `update_plugin` e gli alias legacy delle operation remote, mantenendo solo i nomi canonici usati da Commander corrente.

## [9.8.9] - 2026-09-15

### Fixed
- Gli slug degli update privati mantengono i punti nelle risposte a Commander e nelle azioni firmate, evitando errori "Aggiornamento non trovato" su pacchetti versionati come `jupiter-core-v1.1.0`.
- Il runner degli update privati riconosce anche slug gia normalizzati senza punti da richieste generate con versioni precedenti.

## [9.8.8] - 2026-09-15

### Added
- Feedback immediato per gli update avviati da Commander: `update_all` conserva `job_id`, `stage`, stato cron e callback firmato opzionale verso Commander alla chiusura del job.
- Lo stato del lock update espone lo stage corrente, cosi Commander puo distinguere coda, avvio cron, esecuzione e conclusione.

### Changed
- Gli heartbeat del lock aggiornano subito lo stage anche dentro la finestra di throttle, senza introdurre daemon, agent residenti o polling lato sito client.

## [9.8.7] - 2026-09-15

### Fixed
- Le esclusioni plugin ora vengono rispettate in modo coerente da UI, AJAX, Commander, update ufficiali, update privati e cron schedulati anche quando WordPress usa identificativi diversi per lo stesso plugin.
- "Aggiorna tutto" da Commander e dalla dashboard pulisce eventuali cron `mcu_master_update_event` residui prima di accodare un nuovo job, senza rimuovere la programmazione automatica `marrison_scheduled_update_event`.
- La disattivazione del debug remoto elimina i vecchi backup `wp-config.php.*-debug-backup-*` e conserva solo la copia piu recente.

## [9.8.6] - 2026-09-13

### Added
- Gestione del debug WordPress da Marrison Commander con attivazione, disattivazione, lettura, download ed eliminazione del debug.log.
- Endpoint MCU firmati e autenticati per le operazioni debug, senza interfaccia o controlli esposti agli utenti del sito client.
- Invio manuale del debug.log all'AI da Commander, con analisi salvata senza conservare il contenuto del log.

### Security
- La lettura del log e l'invio all'AI avvengono solo su richiesta esplicita di Commander e usano un limite di dimensione.
- Nessun daemon, agente residente, cron o polling viene aggiunto al sito client per questa funzione.

## [9.8.5] - 2026-09-08

### Changed
- Gli URL dei repository privati per plugin e temi vengono ricevuti da Marrison Commander tramite il canale MCU autenticato.
- Rimossa la modifica manuale degli URL dalla pagina impostazioni di MCU; gli option WordPress restano una cache operativa solo per siti autorizzati da Commander.
- I siti non autorizzati non possono usare i vecchi URL locali; la rimozione di un sito da Commander prova a revocare anche l'accesso al client MCU.

### Fixed
- Corretto il riepilogo status inviato a Commander: ora include gli aggiornamenti dei temi privati già presenti nella cache MCU e usa lo stesso conteggio delle traduzioni mostrato nel pannello WordPress.
- Corretto il riferimento al metodo di controllo dell'autorizzazione repository, che in una distribuzione incoerente poteva causare un errore HTTP 500 sull'endpoint status.
- Se un aggiornamento automatico viene interrotto o termina in errore lasciando attiva la programmazione ma senza prossimo evento cron, MCU rigenera automaticamente la prossima esecuzione; la scheda Programmazione ripara anche i siti gia rimasti in quello stato.

## [9.8.4] - 2026-08-24

### Fixed
- Il backup database non fallisce piu sui siti con tabelle MyISAM o engine non transazionali: in questi casi MCU usa un lock di lettura sulle tabelle per creare un dump coerente.
- Il manifest del backup database registra il metodo di snapshot usato (`transaction`, `read_locks` o `read_locks_fallback`) mantenendo le verifiche su dimensione SQL, hash SHA256, conteggi righe e ZIP.

## [9.8.3] - 2026-08-24

### Changed
- Il backup file schedulato richiede automaticamente anche il backup database, cosi il set generato prima degli aggiornamenti resta sufficiente per il ripristino del sito WordPress.
- I siti gia configurati con backup file attivo ma backup database disattivo eseguono comunque il backup DB prima degli aggiornamenti automatici.

## [9.8.2] - 2026-08-24

### Changed
- La rotazione dei backup file completi mantiene solo l'ultimo set disponibile, riducendo l'accumulo di archivi in `wp-content/marrison-backups`.
- Aggiunta la costante/filtro `MCU_FILES_BACKUP_MAX_SETS` / `mcu_files_backup_max_sets` per rialzare il limite nei casi in cui serve una retention locale maggiore.

## [9.8.1] - 2026-08-24

### Changed
- Il backup file automatico e manuale ora limita lo scope alla sola installazione WordPress: directory `wp-admin`, `wp-includes`, `wp-content` e file root standard di WordPress.
- Le directory extra presenti alla radice dell'hosting, ad esempio cartelle di sottodomini o materiale non WordPress, non vengono piu incluse nel backup file.

## [9.8.0] - 2026-08-04

### Added
- Protocollo Maintenance 2 con `supported_read_operations`, `supported_write_operations`, manifest snapshot e stato pipeline nello status leggero.
- Operazioni diagnostiche read-only `diagnostics_snapshot_manifest`, `diagnostics_snapshot_module`, `diagnostics_live_module`, `diagnostics_page_inspect` e `diagnostics_menu_inspect`.
- Snapshot diagnostici post-manutenzione con fingerprint leggero pre-update, delay distribuito 10-45 minuti e pipeline modulare a eventi singoli.
- Collector tecnici aggregati per ambiente, estensioni, capability, cache, database, cron, errori, contenuti, menu, builder, WooCommerce e manutenzione.

### Changed
- `/action` usa un registry esplicito read/write, mantiene le operazioni esistenti e rifiuta payload oltre 1 MB.
- La diagnostica usa opzioni non autoload e non esegue lavoro nelle richieste WordPress normali.

### Security
- Sanitizzazione centralizzata di snapshot e risposte, con redazione di secret, token, cookie, nonce, email, IP e path assoluti noti.
- Nessuna operazione diagnostica accetta callback, nomi classe/metodo, SQL, URL arbitrari, table name arbitrari o letture filesystem arbitrarie.

## [9.7.16] - 2026-08-01

### Added
- Azione remota `cancel_master_update` per annullare da Master/Commander solo le richieste update accodate dal Master.

### Security
- L'annullamento rimuove esclusivamente i cron `mcu_master_update_event` e non tocca la programmazione automatica MCU `marrison_scheduled_update_event`.
- Se il job risulta gia in esecuzione, MCU rimuove solo eventuali cron residui e non interrompe l'aggiornamento in corso.
- Un job Master annullato non viene eseguito anche se WP-Cron lo aveva gia letto prima della rimozione dalla coda.

## [9.7.15] - 2026-08-01

### Fixed
- Il pulsante "Elimina cron bloccato" torna visibile nella scheda Programmazione quando ci sono lock, richieste Master pendenti o log cron stale da pulire.
- La pulizia manuale rimuove anche eventuali job Master `mcu_master_update_event` rimasti in WP-Cron e marca la richiesta Master come fallita.

### Changed
- Dopo la pulizia manuale il log cron viene marcato come azzerato, evitando che il pulsante resti visibile senza necessita.

## [9.7.14] - 2026-08-01

### Added
- Azione remota `force_sync` per forzare da Master/Commander il controllo aggiornamenti senza eseguire update.

### Changed
- La sincronizzazione esplicita aggiorna cache WordPress, repository privati plugin/temi e traduzioni, poi lascia al Master la rilettura dello stato.

### Security
- Se un update e gia in corso, MCU rifiuta `force_sync` per evitare lavoro sovrapposto sul client.

## [9.7.13] - 2026-08-01

### Added
- Pulsante admin "Interrompi aggiornamento bloccato" nella scheda Programmazione, protetto da nonce e capability.

### Changed
- Soglia heartbeat stale ridotta a 10 minuti per liberare prima i job Master interrotti senza aspettare il timeout completo.

### Fixed
- Lo sblocco manuale chiude il log `started`, rimuove il lock update e marca la richiesta Master come fallita.
- La pulizia cache non preserva piu lock update gia stale.

## [9.7.12] - 2026-08-01

### Fixed
- Failsafe di shutdown per chiudere come errore i job schedulati interrotti durante backup/update.
- Rilascio immediato del lock update nello shutdown quando PHP riesce a completare la fase di arresto.
- Le richieste Master vengono marcate fallite se il job client si interrompe prima della risposta finale.

## [9.7.11] - 2026-08-01

### Fixed
- Recupero automatico dei log cron rimasti in stato `started` dopo un job interrotto o morto prima della chiusura.
- I lock update scaduti o senza heartbeat vengono liberati alla successiva richiesta utile, incluso status Master e tentativi manuali.
- Le richieste Master bloccate da un vecchio job vengono marcate come stale/fallite invece di lasciare il sito in attesa indefinita.

### Security
- Il recupero resta lazy: nessun daemon, polling o traffico extra verso il Master; lavora solo durante admin/status/update gia richiesti.

## [9.7.10] - 2026-07-31

### Added
- Campo "Giorno del mese" nella programmazione automatica MCU per frequenze mensili e semestrali.
- Il payload Client espone al Master il giorno configurato nella frequenza automatica.

### Changed
- Le frequenze mensile e semestrale vengono programmate come eventi calendariali singoli e riprogrammate dopo l'esecuzione, senza daemon o polling ricorrente.
- Nei mesi piu corti del giorno scelto viene usato l'ultimo giorno disponibile.

## [9.7.9] - 2026-07-31

### Fixed
- Stato repository temi nella dashboard MCU: un repository configurato e raggiungibile non mostra piu la X rossa solo perche non ci sono temi aggiornabili.
- Salvataggio URL repository ora pulisce anche i transient di errore plugin/temi, evitando stati rossi temporanei dopo una correzione URL.

## [9.7.8] - 2026-07-31

### Added
- Accesso one-click dashboard per il Master con chiave dedicata separata dalla connessione status/update.
- Endpoint Client `/dashboard-access` che genera link wp-admin temporanei, monouso e senza password WordPress salvate sul Master.
- File configurazione Client arricchito con endpoint e chiave dashboard per import automatico sul Master.

### Security
- I link dashboard scadono rapidamente, vengono consumati una sola volta e non introducono daemon, polling o carichi ricorrenti sul sito Client.

## [9.7.7] - 2026-07-31

### Fixed
- Il payload Client usato dal Master esclude dal conteggio i plugin marcati come esclusi in MCU.
- Matching esclusioni plugin piu robusto tra slug MCU, slug WordPress.org, cartella e file principale.

## [9.7.6] - 2026-07-31

### Added
- Heartbeat sul lock globale degli aggiornamenti, incluso nei backup e nel flusso schedulato usato dal Master.
- Stato lock nel payload Client per permettere al Master di distinguere update attivi e lock stale.

### Fixed
- Recupero automatico dei lock update stale lasciati da job Master o cron interrotti prima del rilascio.
- Le nuove richieste Master possono riaccodare un job rimasto senza risposta invece di restare in `running` indefinito.

## [9.7.5] - 2026-07-31

### Fixed
- Caricamento del controller REST anche nel contesto admin, evitando fatal error nel download configurazione Client.

## [9.7.4] - 2026-07-31

### Fixed
- La richiesta update dal Master ora sveglia WP-Cron in modo non bloccante dopo aver accodato il job.
- Conteggio plugin aggiornabili deduplicato tra transient WordPress e repo privato MCU.
- Matching plugin privati piu tollerante per evitare doppi conteggi quando slug e cartella differiscono solo per separatori.

## [9.7.3] - 2026-07-31

### Added
- Download configurazione Client MCU in formato JSON per import diretto sul Master.
- Nome sito WordPress incluso nella configurazione per compilare automaticamente il nome su Master.

## [9.7.2] - 2026-07-31

### Added
- Report Master con backup scaricabili quando presenti sul client.
- Richiesta update MCU dal Master con job WordPress cron accodato.
- Stato del job Master esposto nel payload client per mostrare il pending sul Master.

### Changed
- Versione client riallineata alla nuova integrazione col Master.

## [9.7.1] - 2026-07-30

### Added
- Client MCU integrato per il dialogo autenticato con il Master Marrison Maintenance.
- Payload client arricchito con l'elenco alfabetico dei plugin aggiornabili, compresi update privati e WordPress.org.

### Changed
- Il Master mostra i dati MCU in forma leggibile, inclusi frequenza, ultimo update, prossimo update schedulato e plugin da aggiornare.

### Fixed
- Evitate scritture ripetute della stessa metadata di richiesta Master in un intervallo molto breve.

## [9.7.0] - 2026-07-29

### Added
- Log mensili degli aggiornamenti in `wp-content/marrison-updater-logs`, scaricabili da Impostazioni > Log e protetti da nonce/capability.
- Lock globale per impedire aggiornamenti concorrenti manuali, AJAX e programmati.
- Snapshot dei plugin attivi prima degli aggiornamenti e ripristino controllato dei plugin rimasti disattivati dopo il batch.
- Flush cache centralizzato post-update con transient WordPress, cache plugin/temi/update, object cache, OPcache e integrazioni comuni di page cache.

### Changed
- Gli errori AJAX degli update pubblici e privati riportano messaggi piu diagnostici, invece del generico errore di connessione.
- I flussi di update ufficiali, privati, temi, traduzioni, restore e cron usano logging strutturato e rilascio lock controllato.

### Fixed
- Ridotto il rischio che plugin dipendenti da WooCommerce restino disattivati se WooCommerce risulta temporaneamente assente durante un aggiornamento.
- Migliorata la pulizia cache dopo aggiornamenti per evitare caricamenti di codice o oggetti persistenti non allineati alla nuova versione.

## [9.6.6] - 2026-07-24

### Added
- Manifest JSON nel backup database con conteggi, dimensione SQL e hash SHA256.
- Stato di verifica nella pagina Backup e nel report email programmato.

### Changed
- Il backup database ora valida scrittura SQL, dimensione file, hash e contenuto dello ZIP prima di dichiarare successo.
- Il backup file ora fallisce sugli errori avvenuti dopo l'inizio della scrittura tar.gz, evitando archivi potenzialmente corrotti marcati come validi.
- Gli aggiornamenti automatici vengono bloccati se un backup richiesto non viene completato e verificato.
- Il backup database verificato richiede tabelle InnoDB e blocca view, trigger o engine non transazionali con errore esplicito.

## [9.6.5] - 2026-07-12

### Changed
- Backup database con ordinamento per chiave primaria quando disponibile durante l'esportazione a batch.
- Backup file piu tollerante: file mancanti, non leggibili o cambiati durante il job vengono esclusi e riportati invece di interrompere tutto il processo.

### Fixed
- Validazione interna del numero righe esportate per tabella nel dump database; il backup fallisce se il dump scritto non corrisponde allo snapshot letto.

## [9.6.4] - 2026-07-12

### Added
- Nuova opzione per saltare i file piu grandi del limite della singola parte e continuare il backup file.

### Changed
- Report manuale ed email schedulata indicano quanti file grandi sono stati saltati e la dimensione totale esclusa.

## [9.6.3] - 2026-07-12

### Changed
- Backup file manuale eseguito come job AJAX a step, per ridurre timeout e interruzioni di connessione.
- Backup file diviso automaticamente in parti `part001`, `part002`, ecc. sotto soglia, utile su hosting con limite di 1GB per file.
- Avanzamento del backup file calcolato sui byte processati rispetto ai byte totali scansionati.

### Fixed
- Le parti del backup restano temporanee finche non sono chiuse correttamente, evitando backup incompleti dichiarati validi.

## [9.6.2] - 2026-07-12

### Changed
- Backup completo dei file generato in formato `tar.gz` streaming invece di ZIP, per evitare archivi troncati o corrotti sui siti grandi.
- I vecchi backup file `.zip` restano visibili, scaricabili e cancellabili dalla pagina Backup.

### Fixed
- Il backup file fallisce con errore se incontra file o directory non leggibili, evitando archivi incompleti dichiarati come riusciti.

## [9.6.1] - 2026-07-12

### Changed
- Bump versione per includere le correzioni al dump database e alla validazione del backup.

### Fixed
- Il dump database preserva lo `SHOW CREATE TABLE` originale, inclusi `AUTO_INCREMENT`, indici e opzioni tabella.
- Il dump database inizializza le variabili di sessione che ripristina, chiude sempre con `COMMIT` e non usa piu `LOCK TABLES`.
- Gli `INSERT` del dump database includono la lista colonne e vengono divisi in blocchi per migliorare la compatibilita con phpMyAdmin.

## [9.6.0] - 2026-07-12

### Added
- Backup completo dei file del sito in formato `.zip`, eseguibile manualmente dalla pagina Backup.
- Opzione di backup file schedulato prima degli aggiornamenti automatici.
- Link diretti nel report email per scaricare backup database e backup file.
- Cancellazione dei singoli backup dalla pagina Backup.

### Changed
- Rotazione dei backup database e file limitata agli ultimi 3 archivi.
- Backup database generato da snapshot coerente quando MySQL lo consente e compresso con `ZipArchive` quando disponibile.

### Fixed
- Il dump database preserva lo `SHOW CREATE TABLE` originale, inclusi `AUTO_INCREMENT`, indici e opzioni tabella.
- Il dump database inizializza le variabili di sessione che ripristina, chiude sempre con `COMMIT` e non usa piu `LOCK TABLES`.
- Gli `INSERT` del dump database includono la lista colonne e vengono divisi in blocchi per migliorare la compatibilita con phpMyAdmin.
- Download dei backup ZIP in streaming a blocchi per evitare fatal error da memoria esaurita su file grandi.
- Il backup file non usa più PclZip come fallback, evitando fatal error da memoria esaurita su siti grandi.
- Corretta la lettura delle date nei nomi dei backup versionati.
- Rimossi residui JS/commenti di debug.

## [9.5.8] - 2026-07-01

### 🐛 BUG FIX — Badge "aggiornamenti disponibili" bloccato su un valore stale

#### Causa
- `marrison_available_updates_count` (l'opzione che controlla il pallino rosso nel menu) veniva ricalcolata da `check_for_available_updates()` **solo** dopo il completamento riuscito di un aggiornamento manuale (AJAX).
- Non essendo mai ricalcolata al caricamento delle pagine admin, se il valore veniva impostato >0 in un momento precedente (es. un aggiornamento tentato, o durante i test con i filtri disabilitati in 9.5.5-9.5.6) e non si verificava più nessun altro aggiornamento completato, il badge restava bloccato su quel numero anche quando in realtà non c'erano più aggiornamenti disponibili.

#### Fix
- Aggiunto hook `admin_init` → `check_for_available_updates()` per ricalcolare il conteggio ad ogni richiesta admin. `get_available_updates()`/`get_available_theme_updates()` sono già cachate con transient da 6h, quindi non introduce chiamate HTTP ripetute né impatti sulle performance.

---

## [9.5.7] - 2026-07-01

### 🐛 CRITICAL BUG FIX — Nessun aggiornamento rilevato (plugin/temi/self-update)

#### Causa
- I filtri `site_transient_update_plugins`, `site_transient_update_themes` e `plugins_api` erano stati **disabilitati per debug** nella versione 9.5.5 (commento "TEMPORANEAMENTE DISABILITATO PER DEBUG") e **mai riattivati**.
- Di conseguenza `check_for_updates` e `check_for_theme_updates` non venivano mai eseguiti: nessun aggiornamento (privato, pubblico o del plugin stesso) veniva iniettato nel transient `update_plugins`/`update_themes`.
- Questo causava due sintomi distinti riportati dagli utenti:
  1. Gli aggiornamenti pubblicati sul repository (incluse nuove release su GitHub) non apparivano nella lista plugin di WordPress, anche dopo il refresh.
  2. Il self-update del plugin (`update_plugin_ajax`) falliva sempre con errore, perché la logica si basa su `get_site_transient('update_plugins')->response[...]`, mai popolato.

#### Fix
- Riattivati i filtri in `admin_init` come previsto, con i guard `is_admin()` interni (introdotti in 9.5.5) mantenuti come protezione extra.

### 🎯 IMPACT
- Gli aggiornamenti di plugin, temi e del plugin stesso vengono correttamente rilevati e mostrati in WordPress
- Il self-update funziona nuovamente

---

## [9.5.6] - 2026-06-04

### 🐛 CRITICAL BUG FIX — WooCommerce (e plugin correlati) venivano disattivati dopo un aggiornamento

#### Causa 1 — `perform_update`: rimpiazzo non atomico della directory (BUG PRINCIPALE)
- **Problema**: `perform_update` cancellava la directory del plugin (`$wp_filesystem->delete($dest, true)`) PRIMA di copiare i nuovi file. Se `copy_dir` falliva per qualsiasi motivo (permessi, errore filesystem), il plugin rimaneva senza file su disco. Alla richiesta successiva, WordPress tentava di caricare il plugin da `active_plugins` ma non trovava il file → **Fatal Error Protection di WP 5.2+** auto-deattivava il plugin. WP 6.5+ Plugin Dependencies poi rimuoveva automaticamente tutti i plugin con `Requires Plugins: woocommerce` (Stripe, PayPal, Subscriptions, ecc.).
- **Fix**: Rimpiazzo atomico (copy-then-swap):
  1. Copia i nuovi file su una directory temporanea (`plugin-marrison-new-{ts}`) — nessuna azione distruttiva ancora
  2. Rinomina la vecchia directory in backup (`plugin-marrison-old-{ts}`)
  3. Rinomina la temp nella destinazione finale
  4. Se step 2/3 fallisce, ripristina il backup automaticamente
  5. Se tutto ok, elimina il backup

#### Causa 2 — `activate_plugin` con `$silent = false` in contesti di update (BUG SECONDARIO)
- **Problema**: In `update_plugin_ajax`, `bulk_update_ajax`, e `update_official_plugin_ajax`, `activate_plugin` veniva chiamato con `$silent = false` (4° parametro). Se il plugin finiva per qualche ragione fuori da `active_plugins`, venivano eseguiti gli activation hook in un contesto anomalo. Alcuni plugin WooCommerce nei propri activation hook chiamano `deactivate_plugins()` in caso di incompatibilità, con effetti collaterali imprevedibili.
- **Fix**: Tutte le chiamate `activate_plugin` nei flussi di aggiornamento usano ora `$silent = true` e vengono eseguite solo se `!is_plugin_active()` (guard aggiunto).

### 🎯 IMPACT
- I plugin privati (incluso WooCommerce se presente nel repo) non vengono mai lasciati in uno stato "vuoto" su disco durante un aggiornamento
- Gli activation hook non vengono più eseguiti in contesti di re-attivazione post-update

---

## [9.5.5] - 2026-06-03

### 🔧 CRITICAL PERFORMANCE FIX
- **Cache invalidation aggressiva rimossa**: `delete_internal_cache` non è più agganciato a `delete_site_transient_update_plugins` — WP cancella questo transient su quasi ogni pagina admin, il che svuotava il cache del repo ad ogni richiesta
- **GitHub cache cleanup**: `force_clear_github_cache` rimosso da `delete_site_transient_update_plugins` per evitare fetch ripetuti
- **Failure caching**: Aggiunto sistema di "failure cache" di 5 minuti (`marrison_updates_fetch_failed`, `marrison_theme_updates_fetch_failed`, `marrison_github_fetch_failed`) per evitare retry ripetuti su server irraggiungibili
- **Timeout ridotti**: Da 15s/10s a 5s per tutte le chiamate HTTP al repository (`get_available_updates`, `get_available_theme_updates`, `get_github_version`)
- **Guard is_admin()**: Aggiunto in `check_for_updates` e `check_for_theme_updates` come protezione extra per quando i filtri verranno riabilitati
- **Pulizia failure transients**: `delete_internal_cache` ora pulisce anche i transient di fallimento per permettere retry dopo pulizia cache manuale

### 🎯 IMPACT
- **Risolto rallentamento critico**: Siti con repository lento/irraggiungibile non si bloccano più per 15 secondi su ogni pagina admin
- Cache del repository mantenuto correttamente tra i caricamenti pagina
- Fallback intelligente quando il repository non è accessibile (5 min di attesa prima di retry)

---

## [9.5.4] - 2026-05-29

### 🐛 FIX
- **Frontend rallentato**: aggiunto controllo `is_admin()` ai filtri di aggiornamento per evitare chiamate HTTP sul frontend
- **Performance**: i filtri `site_transient_update_plugins` e `site_transient_update_themes` vengono eseguiti solo nell'area admin

### 🎯 IMPACT
- Il frontend non viene più rallentato dalle chiamate al repository privato
- Migliore performance complessiva del sito

---

## [9.5.3] - 2026-05-29

### 🐛 FIX
- **Dashboard bloccata dopo migrazione**: rimosso hook `admin_init` per `check_for_available_updates()` che causava timeout HTTP al repository privato
- **Miglioramento**: il controllo aggiornamenti viene già eseguito tramite filtri `site_transient_update_plugins` e `site_transient_update_themes`

### 🎯 IMPACT
- Dashboard non si blocca più dopo migrazioni o se il repository privato non è accessibile
- Caricamento admin più veloce

---

## [9.5.2] - 2026-05-18

### � FIX
- **Backup DB formato phpMyAdmin**: il dump SQL generato è ora compatibile con l'importazione phpMyAdmin
- **Backup DB compatibilità**: gestione di `AUTO_INCREMENT`, SET SQL_MODE/START TRANSACTION globali
- **Backup DB**: fixato `$wpdb->dbhost()` (proprietà, non metodo)
- **Backup DB**: fixato segno `=` residuo nelle opzioni tabella

### 🎯 IMPACT
- Backup database completamente compatibile con phpMyAdmin per restore affidabile

---

## [9.5.1] - 2026-05-18

### 🔧 ENHANCEMENT
- **Repository index.php**: i file `index.php` per plugin e temi ora scansionano ricorsivamente le sottocartelle per i file `.zip`
- **Organizzazione flessibile**: è ora possibile organizzare i plugin/temi in sottocartelle (es. `repo/client-a/plugin.zip`, `repo/ecommerce/theme.zip`)
- **Download URL corretto**: il percorso relativo delle sottocartelle viene preservato nel `download_url`

### 🎯 IMPACT
- Migliore organizzazione del repository privato
- Possibilità di separare i file per cliente o categoria

---

## [9.5.0] - 2026-05-13

### ✨ NEW FEATURE - Backup Database
- **Backup manuale**: pulsante "Esegui Backup Database" nella pagina Backup per creare un dump SQL on-demand
- **Backup schedulato**: nuova opzione in Impostazioni > Programmazione per eseguire automaticamente un backup del DB prima di ogni aggiornamento automatico
- **Download**: ogni backup database è scaricabile direttamente dalla pagina Backup in formato `.zip`
- **Rotazione automatica**: vengono mantenuti solo gli ultimi 3 backup del database, i precedenti vengono eliminati automaticamente
- **Formato**: dump SQL completo con `DROP TABLE IF EXISTS` + `CREATE TABLE` + `INSERT INTO`, compresso in `.zip`
- **Sicurezza**: i file sono salvati in `wp-content/marrison-backups/` con `.htaccess` `deny from all`

### 🎯 IMPACT
- Protezione database completa prima di ogni aggiornamento
- Possibilità di ripristinare il DB in caso di problemi dopo un aggiornamento

---

## [9.4.3] - 2026-05-12

### 🔧 CRITICAL FIX
- **Email HTML rendering**: Fixed emails arriving as raw HTML code instead of rendered content
- **Root cause**: SMTP plugins (WP Mail SMTP, FluentSMTP, etc.) hooking into `phpmailer_init` could reset the content type after WordPress set it, causing the email to be delivered as `text/plain`
- **Fix**: Added `wp_mail_content_type` filter and forced `isHTML(true)` via `phpmailer_init` at priority 999 (runs last) before every send, then removed both hooks immediately after

### 🎯 IMPACT
- HTML emails now render correctly regardless of which SMTP plugin is active
- Applied to both test emails and scheduled update report emails

---

## [9.4.2] - 2026-05-08

### 🔧 CRITICAL FIX
- **Email sending**: Fixed email delivery failure on 90% of sites
- **From header**: Replaced fabricated `no-reply@domain.com` with real `admin_email`
- **Root cause**: Most hosting providers reject emails from non-configured sender addresses (SPF/DMARC failures)

### 🎯 IMPACT
- Email notifications now work reliably across all hosting environments
- Both test emails and scheduled update reports are delivered correctly
- No more silent email failures due to server restrictions

---

## [9.4.1] - 2026-03-12

### 🔧 FIXES
- **Pulsante "Pulisci Cache"**: Ora funziona correttamente
- **Handler JavaScript**: Corretto per usare form submit invece di AJAX

### ⚡ IMPROVEMENTS
- **Pulizia cache completa**: Inclusa cache GitHub e WordPress
- **Ricaricamento forzato**: Aggiornamenti ricaricati dal repository dopo pulizia
- **Dialogo di conferma**: Aggiunto per sicurezza durante pulizia cache
- **Feedback visivo**: Indicatore di stato durante pulizia cache

### 🎯 IMPACT
- Cache completamente pulita e ricaricata
- Repository aggiornamenti sincronizzato correttamente
- Esperienza utente migliorata con feedback appropriato

---

## [9.4.0] - 2026-03-04

### CRITICAL FIXES
- **Sistema di esclusioni completamente rinnovato**: Ora funziona per tutti i tipi di plugin
- **Gestione unificata**: Plugin premium/privati e WordPress.org gestiti allo stesso modo
- **Riconoscimento automatico plugin premium**: Tramite analisi del PluginURI
- **Sistema di slug duali**: Compatibilità con tutti i plugin (cartella vs WordPress.org)

### BUG FIXES
- Badge "Escluso" ora funziona per tutti i tipi di plugin
- Contatore principale esclude correttamente i plugin esclusi
- Pulsante "Aggiorna tutto" rispetta le esclusioni
- Plugin esclusi non vengono più aggiornati accidentalmente

### IMPROVEMENTS
- **Ripristinati tutti i pulsanti JavaScript mancanti**
- Handler per pulsante "Invia mail di test" nella programmazione
- Handler per pulsante "Pulisci Cache"
- Handler per pulsante "Aggiorna Tutti" plugin pubblici
- Handler per pulsante "Installa selezionati" e "Seleziona tutti"
- Feedback visivo e toast notifications per tutti i pulsanti
- Gestione errori e stati di caricamento per tutti i pulsanti

### IMPACT
- Sistema di esclusioni ora affidabile al 100%
- Tutti i pulsanti dell'interfaccia funzionano correttamente
- Supporto completo per plugin premium, privati e WordPress.org

## [9.3.0] - 2025-03-04

### Added
- **Backup/Restore Extended**: Complete backup/restore functionality now covers all plugins and themes, including both public and private repositories
- **Orphan Backup Cleanup**: Automatic removal of backups for plugins that are no longer installed on the site
- **Single-File Plugin Support**: Full backup/restore support for single-file plugins (not just directory-based plugins)

### Fixed
- **Update All Button**: Fixed "Aggiorna tutto" button that became unresponsive after backup extension
- **Timeout Issues**: Extended execution time limits for bulk update endpoints to handle backup operations during mass updates

### Improved
- **Backup Reliability**: Enhanced backup filename handling and slug detection for both single-file and directory plugins
- **Restore Process**: Improved restore logic to handle both plugin types seamlessly
- **Performance**: Optimized backup cleanup routine to run efficiently when accessing backup page

## [9.2.0] - Previous Release

### UI Improvements
- Centered action buttons in the Updates page header between title and logo
- Standardized button colors throughout the plugin (normal: dark purple, hover: bright pink)
- Removed repository plugin counters (private information) from dashboard and settings
- Moved "Advanced Tools" block to "Guide & Download" tab
- Cleaned up plugin and themes monitoring tables (removed File, Slug, and Status columns)

### Bug Fixes
- Fixed theme repository index.php download issue (absolute paths and separate logic)
- Introduced global `MCU_PLUGIN_DIR` constant for more robust file path management

## [9.1.0] - Previous Release

### General
- Feature update to version 9.1 with general stability and performance improvements
- Updated stable version to reflect latest release

## [8.6.0] - Previous Release

### Bug Fixes
- Removed conflict check with Marrison Custom Installer and made plugin independent
- Renamed main class and traits to MCU_* to avoid name collisions
- Eliminated unwanted activation notice

## [8.5.0] - Previous Release

### Added
- Implemented forced translation updates (Core, Themes, Plugins) with deep cache cleanup and extended timeout
- Added automation for Elementor database update after plugin update
- Integrated Elementor DB update status in automatic email report

### Bug Fixes
- Added safety delay (3 seconds) before Elementor DB trigger for filesystem stability

## [8.4.0] - Previous Release

### UI Improvements
- Updated update frequency names (Daily, Weekly, Monthly, Semi-annual) for better consistency

### Bug Fixes
- Improved next scheduled execution calculation to correctly respect set frequency (Daily, Weekly, Monthly, Semi-annual)

## [8.3.0] - Previous Release

### Bug Fixes
- Fixed critical issue where plugin self-update could cause plugin deactivation
- Implemented atomic update strategy with preventive backup

## [8.2.1] - Previous Release

### Email Improvements
- Added visual status banners (Green/Red) for quick outcome identification
- Enhanced report with detailed sections for errors and skipped updates
- Optimized email graphics (logo, clean footer)

### Core Improvements
- Improved error handling during updates (captures WP_Error error codes)
- Added PHP requirements check before update
- Removed "About" tab from settings panel

## [8.2.0] - Previous Release

### Refactoring
- Complete code restructuring using Traits to improve modularity and stability

### Email Improvements
- Renovated notification email graphics with modern responsive design
- Added version details (Previous -> New) in update report

### Bug Fixes
- Resolved function redefinition conflicts with WordPress core

## [8.1.5] - Previous Release

### Internationalization
- Made scheduling option strings (Weekly, Monthly, etc.) and test email messages translatable
- Updated .pot file with latest strings

## [8.1.4] - Previous Release

### Added
- Weekly scheduling option for automatic updates

## [8.1.3] - Previous Release

### Bug Fixes
- Fixed update detection issue for WPCode Lite (insert-headers-and-footers) when Marrison Custom Updater is active
- Improved private plugin exclusion logic to avoid false positives

## [8.1.2] - Previous Release

### Improvements
- Enhanced private plugin update detection logic
- Added ability to exclude installed private plugins from standard WordPress checks to avoid conflicts

## [8.1.1] - Previous Release

### Improvements
- Improved cron job management: added detailed logs and error handling (try-catch) to prevent blocks
- Fixed bug that prevented email report sending when there were no updates (now always sends if scheduled)
- Resolved PHP "Undefined variable" notice in cron job

## [8.1.0] - Previous Release

### Added
- Initial release with core functionality
