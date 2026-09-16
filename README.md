# Marrison Custom Updater

[![Latest Version](https://img.shields.io/badge/version-9.8.13-blue.svg)](https://github.com/marrisonlab/marrison-custom-updater)
[![WordPress Version](https://img.shields.io/badge/WordPress-6.0%2B-green.svg)](https://wordpress.org)
[![PHP Version](https://img.shields.io/badge/PHP-7.4%2B-green.svg)](https://php.net)
[![License](https://img.shields.io/badge/license-GPL--3.0%2B-blue.svg)](https://www.gnu.org/licenses/gpl-3.0.txt)

**Marrison Custom Updater** è una soluzione avanzata per gestire aggiornamenti di plugin e temi privati in WordPress. Permette di collegare il tuo sito WordPress a un repository personalizzato, consentendo di distribuire aggiornamenti per i tuoi plugin e temi proprietari con la stessa facilità di quelli ufficiali di WordPress.org.

## ✨ Funzionalità Principali

- 🔄 **Repository Privato**: Collega il tuo sito a una fonte esterna per ricevere aggiornamenti per plugin e temi non presenti nella directory ufficiale
- 📦 **Gestione Aggiornamenti Unificata**: Visualizza e installa aggiornamenti per plugin e temi privati direttamente dalla dashboard
- 💾 **Sistema di Backup Integrato**: Esegue automaticamente backup di tutti i plugin e temi (privati e pubblici) prima dell'aggiornamento, permettendo il ripristino rapido (rollback) in caso di problemi
- 🧹 **Pulizia Backup Orfani**: Rimuove automaticamente i backup dei plugin che non sono più installati sul sito
- ⏰ **Aggiornamenti Automatici**: Configura aggiornamenti automatici programmati con giorno del mese dedicato per frequenze mensili e semestrali
- 🌐 **Gestione Traduzioni**: Strumento dedicato per aggiornare le traduzioni dei plugin
- 📊 **Log e Debug**: Sistema di logging integrato per monitorare le operazioni di aggiornamento e cron job
- 🛠️ **Debug remoto da Commander**: Attivazione, lettura, download ed eliminazione del debug.log disponibili solo nel pannello Commander
- 🔁 **Feedback Commander**: `update_all` espone job, stage e callback finale firmato senza agent o daemon residenti sul sito client
- 🚫 **Esclusione Plugin**: Possibilità di escludere specifici plugin dagli aggiornamenti automatici

## � Installation

1. Upload the `marrison-custom-updater` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Configure the private plugin/theme repository URLs in Marrison Commander > Impostazioni

## 📋 Requirements

- WordPress 6.0 or higher
- PHP 7.4 or higher
- PHP zlib extension for full file backups in tar.gz format
- Access to plugin files for backup/restore operations

## 🔄 Version History

### [9.8.13] - 2026-09-16

- Il controller action carica `Authenticator` e `Debug_Logger` anche nei percorsi WP-Cron/callback, evitando job Master falliti con errore "Class MarrisonCustomUpdater\MaintenanceClient\Authenticator not found".

### [9.8.12] - 2026-09-15

- Lo status MCU espone le liste dettagliate `theme_updates` e `translation_updates`, cosi Commander puo mostrare nel Sommario quali temi e traduzioni devono essere aggiornati.

### [9.8.11] - 2026-09-15

- Il bootstrap MCU carica `Authenticator` e `Debug_Logger` gia in `Plugin::init()`, cosi i job WP-Cron avviati da Commander possono firmare la callback finale senza errore "Class MarrisonCustomUpdater\MaintenanceClient\Authenticator not found".

### [9.8.10] - 2026-09-15

- Rimosso il fallback per slug privati gia normalizzati senza punti: gli update usano solo lo slug MCU canonico.
- Le operation remote usano il payload strutturato `data`; `update_plugin` accetta il campo corrente `plugin_file` e gli alias operation legacy sono stati rimossi.
- Le callback Commander inviano l'identificativo job solo dentro `job_status`, senza duplicarlo nel payload principale.

### [9.8.9] - 2026-09-15

- Gli slug degli update privati mantengono i punti nelle risposte a Commander e nelle azioni firmate.
- Il runner riconosce anche slug gia normalizzati senza punti da versioni precedenti, evitando "Aggiornamento non trovato" sui pacchetti privati versionati.

### [9.8.8] - 2026-09-15

- Feedback immediato per gli update avviati da Commander con `job_id`, `stage`, stato cron e callback finale firmato.
- Lo stage del lock update viene esposto nello status MCU e aggiornato anche durante il throttle degli heartbeat.
- Nessun daemon, agente residente o listener sempre attivo viene aggiunto ai siti client.

### [9.8.7] - 2026-09-15

- Matching esclusioni plugin centralizzato tra slug MCU, slug WordPress.org, file, cartella e nome plugin.
- "Aggiorna tutto" da Commander e dalla dashboard elimina i cron di lavoro Master/Commander residui senza toccare la programmazione automatica.
- La disattivazione del debug remoto mantiene solo l'ultimo backup di `wp-config.php` ed elimina i backup debug precedenti.

### [9.8.6] - 2026-09-13

- Gestione del debug WordPress da Marrison Commander tramite endpoint MCU firmati e autenticati.
- Nessuna interfaccia debug o attivita residente aggiunta alle pagine del sito client.
- Invio manuale del debug.log all'AI disponibile dal pannello Commander.

### [9.8.5] - 2026-09-08

- Gli URL dei repository privati per plugin e temi vengono gestiti centralmente da Marrison Commander e distribuiti ai client MCU tramite richieste autenticate.
- La modifica manuale degli URL è stata rimossa dalle impostazioni di MCU; gli option locali restano una cache operativa solo per siti autorizzati da Commander.
- I siti non autorizzati non possono usare i vecchi URL locali; la rimozione da Commander prova a revocare l'accesso al client MCU.

### [9.8.4] - 2026-08-24

- Il backup database non fallisce piu sui siti con tabelle MyISAM o engine non transazionali: in questi casi MCU usa un lock di lettura sulle tabelle per creare un dump coerente.
- Il manifest del backup database registra il metodo di snapshot usato, mantenendo le verifiche su dimensione SQL, hash SHA256, conteggi righe e ZIP.

### [9.8.3] - 2026-08-24

- Il backup file schedulato richiede automaticamente anche il backup database, cosi il set generato prima degli aggiornamenti resta sufficiente per il ripristino del sito WordPress.
- I siti gia configurati con backup file attivo ma backup database disattivo eseguono comunque il backup DB prima degli aggiornamenti automatici.

### [9.8.2] - 2026-08-24

- La rotazione dei backup file completi mantiene solo l'ultimo set disponibile, riducendo l'accumulo in `wp-content/marrison-backups`.
- Aggiunta la costante/filtro `MCU_FILES_BACKUP_MAX_SETS` / `mcu_files_backup_max_sets` per rialzare il limite su siti specifici.

### [9.8.1] - 2026-08-24

- Il backup file automatico e manuale viene limitato alla sola installazione WordPress: `wp-admin`, `wp-includes`, `wp-content` e file root standard.
- Le cartelle extra alla radice dell'hosting, come sottodomini o materiale non WordPress, non vengono piu incluse nei backup file.

### [9.8.0] - 2026-08-04

- Protocollo Maintenance 2 con operazioni read/write dichiarate esplicitamente.
- Nuove operazioni diagnostiche read-only per manifest snapshot, moduli, diagnostica live singolo modulo, pagina e menu.
- Snapshot diagnostici post-manutenzione schedulati dopo il rilascio del lock, con ritardo distribuito e pipeline a eventi singoli.
- Collector tecnici aggregati e sanitizzati, senza polling verso Commander e senza lavoro diagnostico sulle richieste normali.
- Storage diagnostico temporaneo in opzioni non autoload e registry `/action` esplicito con validazione parametri.

### [9.7.16] - 2026-08-01

- Nuova azione remota `cancel_master_update` per annullare da Master/Commander solo la richiesta update accodata dal Master.
- L'annullamento rimuove esclusivamente i cron `mcu_master_update_event` e non tocca la programmazione automatica MCU `marrison_scheduled_update_event`.
- Se il job risulta gia in esecuzione, MCU rimuove solo eventuali cron residui e non interrompe l'aggiornamento in corso.
- Un job Master annullato non viene eseguito anche se WP-Cron lo aveva gia letto prima della rimozione dalla coda.

### [9.7.15] - 2026-08-01

- Il pulsante "Elimina cron bloccato" torna visibile nella scheda Programmazione quando ci sono lock, richieste Master pendenti o log cron stale da pulire.
- La pulizia manuale rimuove anche eventuali job Master `mcu_master_update_event` rimasti in WP-Cron e marca la richiesta Master come fallita.
- Dopo la pulizia il log viene marcato come azzerato manualmente, evitando che il pulsante resti visibile senza necessita.

### [9.7.14] - 2026-08-01

- Nuova azione remota `force_sync` per forzare da Master/Commander il controllo aggiornamenti.
- La sincronizzazione esplicita aggiorna cache WordPress, repo privati plugin/temi e traduzioni senza avviare update.
- Se un update e gia in corso, MCU rifiuta la sincronizzazione per non sovrapporre carico.

### [9.7.13] - 2026-08-01

- Soglia heartbeat stale ridotta a 10 minuti per liberare prima i job Master interrotti.
- Pulsante admin "Interrompi aggiornamento bloccato" per rimuovere esplicitamente un lock rimasto appeso.
- Lo sblocco manuale chiude anche il log `started` e marca la richiesta Master come fallita.
- La pulizia cache non preserva piu lock update gia stale.

### [9.7.12] - 2026-08-01

- Failsafe di shutdown per chiudere come errore i job schedulati interrotti durante backup/update.
- Il lock update viene rilasciato subito nello shutdown quando possibile, evitando blocchi fino alla soglia stale.
- Le richieste Master vengono marcate fallite se il job client si interrompe prima della risposta finale.

### [9.7.11] - 2026-08-01

- Recupero automatico dei log cron rimasti in stato `started` dopo un job interrotto o morto prima della chiusura.
- I lock update scaduti o senza heartbeat vengono liberati alla successiva richiesta utile, incluso status Master, senza daemon o polling.
- Le richieste Master bloccate da un vecchio job vengono marcate come stale/fallite invece di lasciare il sito in attesa indefinita.

### [9.7.10] - 2026-07-31

- La programmazione mensile e semestrale permette di scegliere il giorno del mese.
- Le frequenze mensile e semestrale usano eventi calendariali singoli riprogrammati dopo l'esecuzione, evitando il vecchio riferimento implicito al giorno corrente.
- Il payload Client espone al Master il giorno configurato nella frequenza MCU.

### [9.7.9] - 2026-07-31

- Lo stato repository temi non mostra piu la X rossa quando l'URL e configurato e il repo risponde correttamente ma non ci sono temi aggiornabili.
- Il salvataggio degli URL repository pulisce anche i transient di errore plugin/temi.

### [9.7.8] - 2026-07-31

- Accesso one-click dashboard dal Master con chiave dedicata separata dalla chiave status/update.
- Endpoint Client `/dashboard-access` per generare link wp-admin temporanei e monouso.
- Il file configurazione MCU include endpoint e chiave dashboard, cosi il Master e pronto dopo l'import.
- Nessun daemon, polling o carico ricorrente sui siti client: il link viene creato solo al click dal Master.

### [9.7.7] - 2026-07-31

- Il payload Client per Master non conta piu i plugin esclusi in MCU, anche quando arrivano dal transient WordPress.org.
- Matching esclusioni piu tollerante tra slug cartella, slug WordPress.org e file plugin.

### [9.7.6] - 2026-07-31

- Lock aggiornamenti con heartbeat e recupero dei lock stale rimasti da richieste Master interrotte.
- Lo stato client espone al Master il lock update senza token o segreti.
- Backup schedulati e aggiornamenti lunghi aggiornano il heartbeat senza introdurre daemon o polling.

### [9.7.5] - 2026-07-31

- Fix fatal error durante il download della configurazione Client MCU da `admin-post.php`.

### [9.7.4] - 2026-07-31

- La richiesta update dal Master ora prova ad avviare subito WP-Cron in modo non bloccante.
- Conteggio plugin aggiornabili deduplicato tra transient WordPress e repo privato MCU.

### [9.7.3] - 2026-07-31

- Il pannello Client MCU permette di scaricare un file configurazione JSON importabile dal Master.
- Il file usa il nome sito WordPress del client per compilare il nome sul Master.

### [9.7.2] - 2026-07-31

- Report Master con backup scaricabili quando presenti sul client.
- Richiesta update MCU dal Master con job WordPress cron accodato.
- Stato pending esposto al Master per distinguere richiesta inviata e lavoro ancora in corso.

### [9.7.1] - 2026-07-30

- Client MCU integrato per il dialogo autenticato con il Master Marrison Maintenance.
- Il payload client include l'elenco alfabetico dei plugin aggiornabili, distinguendo update WordPress.org e privati.
- Il Master può mostrare frequenza, ultimo update, prossimo aggiornamento schedulato e dettagli più leggibili.

### [9.7.0] - 2026-07-29

- Log mensili degli aggiornamenti scaricabili da Impostazioni > Log, con protezione nonce/capability e pulizia automatica.
- Lock globale per impedire aggiornamenti concorrenti da AJAX, manuale o cron.
- Snapshot dei plugin attivi prima dell'update e ripristino controllato dei plugin rimasti disattivati dopo il batch.
- Flush cache centralizzato dopo gli aggiornamenti: transient WordPress, cache plugin/temi/update, object cache, OPcache e page cache comuni.
- Errori AJAX piu diagnostici per distinguere timeout, fatal PHP, blocchi HTTP e fallimenti reali dell'updater.

### [9.6.6] - 2026-07-24

- Backup database con scrittura SQL controllata byte per byte, manifest JSON e hash SHA256 dentro lo ZIP
- Validazione dello ZIP dopo la compressione: il backup viene mostrato come riuscito solo se SQL, manifest, dimensione e hash coincidono
- Gli aggiornamenti automatici vengono bloccati se un backup richiesto non viene completato e verificato
- Il backup DB verificato richiede tabelle InnoDB; view, trigger o engine non transazionali vengono bloccati con errore esplicito
- Backup file piu severo sugli errori durante la scrittura tar.gz: se l'archivio potrebbe essere corrotto, il job fallisce invece di dichiarare successo
- Pagina Backup e report email indicano quando un backup database e stato verificato

### [9.6.5] - 2026-07-12

- Backup database con ordinamento per chiave primaria quando disponibile e validazione interna del numero righe esportate per tabella
- Backup file piu tollerante: file mancanti, non leggibili o cambiati durante il job vengono esclusi e riportati invece di interrompere tutto il processo

### [9.6.4] - 2026-07-12

- Nuova opzione per saltare i file piu grandi del limite della singola parte e continuare il backup file
- Report manuale ed email schedulata indicano quanti file grandi sono stati saltati e la dimensione totale esclusa

### [9.6.3] - 2026-07-12

- Backup file manuale eseguito come job AJAX a step, per ridurre timeout e interruzioni di connessione
- Backup file diviso automaticamente in parti `part001`, `part002`, ecc. sotto soglia, utile su hosting con limite di 1GB per file
- Le parti restano temporanee finche non sono chiuse correttamente, evitando di mostrare backup incompleti come validi

### [9.6.2] - 2026-07-12

- Backup completo dei file generato in formato `tar.gz` streaming invece di ZIP, per evitare archivi troncati o corrotti sui siti grandi
- I vecchi backup file `.zip` restano visibili, scaricabili e cancellabili dalla pagina Backup
- Il backup file fallisce con errore se incontra file o directory non leggibili, evitando archivi incompleti dichiarati come riusciti

### [9.6.1] - 2026-07-12

- Dump database reso importabile con maggiore affidabilita: preserva `AUTO_INCREMENT`, chiude con `COMMIT`, evita `LOCK TABLES` e divide gli `INSERT` in blocchi
- Validazione piu robusta del backup DB prima della creazione del file ZIP

### [9.6.0] - 2026-07-12

- Backup completo dei file del sito, manuale e schedulato
- Link diretti nel report email per scaricare backup database e file
- Rotazione automatica limitata agli ultimi 3 backup
- Dump database piu affidabile per phpMyAdmin: preserva `AUTO_INCREMENT`, chiude con `COMMIT`, evita `LOCK TABLES` e divide gli `INSERT` in blocchi
- Pulizia residui debug/commenti e fix parsing date backup

### [9.5.5] - 2026-06-03

#### 🔧 CRITICAL PERFORMANCE FIX
- **Cache invalidation aggressiva rimossa**: `delete_internal_cache` non è più agganciato a `delete_site_transient_update_plugins`
- **GitHub cache cleanup**: `force_clear_github_cache` rimosso da `delete_site_transient_update_plugins`
- **Failure caching**: Aggiunto sistema di "failure cache" di 5 minuti per evitare retry ripetuti su server irraggiungibili
- **Timeout ridotti**: Da 15s/10s a 5s per tutte le chiamate HTTP al repository
- **Guard is_admin()**: Aggiunto in `check_for_updates` e `check_for_theme_updates` come protezione extra

#### 🎯 IMPACT
- **Risolto rallentamento critico**: Siti con repository lento/irraggiungibile non si bloccano più per 15 secondi su ogni pagina admin
- Cache del repository mantenuta correttamente tra i caricamenti pagina
- Fallback intelligente quando il repository non è accessibile

### [9.4.1] - 2026-03-12

#### 🔧 FIXES
- **Pulsante "Pulisci Cache"**: Ora funziona correttamente
- **Handler JavaScript**: Corretto per usare form submit invece di AJAX

#### ⚡ IMPROVEMENTS
- **Pulizia cache completa**: Inclusa cache GitHub e WordPress
- **Ricaricamento forzato**: Aggiornamenti ricaricati dal repository dopo pulizia
- **Dialogo di conferma**: Aggiunto per sicurezza durante pulizia cache
- **Feedback visivo**: Indicatore di stato durante pulizia cache

#### 🎯 IMPACT
- Cache completamente pulita e ricaricata
- Repository aggiornamenti sincronizzato correttamente
- Esperienza utente migliorata con feedback appropriato

### [9.4.0] - 2026-03-04

#### 🔧 CRITICAL FIXES
- **Sistema di esclusioni completamente rinnovato**: Ora funziona per tutti i tipi di plugin
- **Gestione unificata**: Plugin premium/privati e WordPress.org gestiti allo stesso modo
- **Riconoscimento automatico plugin premium**: Tramite analisi del PluginURI
- **Sistema di slug duali**: Compatibilità con tutti i plugin (cartella vs WordPress.org)

#### 🐛 BUG FIXES
- Badge "Escluso" ora funziona per tutti i tipi di plugin
- Contatore principale esclude correttamente i plugin esclusi
- Pulsante "Aggiorna tutto" rispetta le esclusioni
- Plugin esclusi non vengono più aggiornati accidentalmente

#### ⚡ IMPROVEMENTS
- **Ripristinati tutti i pulsanti JavaScript mancanti**
- Handler per pulsante "Invia mail di test" nella programmazione
- Handler per pulsante "Pulisci Cache"
- Handler per pulsante "Aggiorna Tutti" plugin pubblici
- Handler per pulsante "Installa selezionati" e "Seleziona tutti"
- Feedback visivo e toast notifications per tutti i pulsanti
- Gestione errori e stati di caricamento per tutti i pulsanti

#### 🎯 IMPACT
- Sistema di esclusioni ora affidabile al 100%
- Tutti i pulsanti dell'interfaccia funzionano correttamente
- Supporto completo per plugin premium, privati e WordPress.org

### [9.3.0] - 2026-03-04

## 🔧 Configurazione

### Repository Plugin

1. Vai su **Marrison Commander** > **Impostazioni**
2. Inserisci l'URL del repository privato plugin nella sezione "Repository privati MCU"
3. Salva le impostazioni; Commander lo distribuirà ai client MCU autenticati

### Repository Temi

1. Nella stessa sezione di Commander inserisci l'URL del repository privato temi
2. Salva le impostazioni

Gli URL locali non sono sufficienti per autorizzare un sito: MCU li usa solo dopo
aver ricevuto una configurazione firmata da Commander. Un sito rimosso da Commander
viene revocato prima della rimozione; lasciare vuoto un campo disattiva quel
repository sui client alla successiva verifica o sincronizzazione.

### Aggiornamenti Automatici

1. Vai su **Marrison Updater** > **Pianificazione**
2. Configura la frequenza degli aggiornamenti automatici
3. Per frequenze mensili o semestrali scegli il giorno del mese
4. Imposta le notifiche email
5. Salva le impostazioni

## 💾 Sistema di Backup e Restore

### Backup Automatico

Il plugin crea automaticamente un backup prima di ogni aggiornamento per:
- ✅ Plugin privati (repository personalizzato)
- ✅ Plugin pubblici (WordPress.org)
- ✅ Plugin single-file
- ✅ Plugin in cartella
- ✅ Temi privati
- ✅ Temi pubblici

I backup database includono un manifest JSON con conteggi, dimensione SQL e hash SHA256; la pagina Backup mostra se l'archivio e stato verificato. Se un backup richiesto non viene completato e verificato, gli aggiornamenti automatici vengono bloccati.

### Pulizia Backup Orfani

Quando accedi alla pagina **Backup**, il plugin:
- Controlla quali plugin sono ancora installati
- Rimuove automaticamente i backup dei plugin non più presenti
- Mantiene la cartella backup pulita e organizzata

### Restore

1. Vai su **Marrison Updater** > **Backup**
2. Trova il backup desiderato nella lista
3. Clicca "Ripristina" per ripristinare quella versione specifica
4. Il plugin verrà ripristinato e riattivato se necessario

## 🔄 Aggiornamento Massivo

Il pulsante **"Aggiorna tutto"** permette di aggiornare in sequenza:
1. Plugin privati
2. Plugin pubblici/ufficiali
3. Tutti i temi
4. Traduzioni

Ogni aggiornamento include un backup automatico prima dell'installazione.

## 📧 Notifiche Email

Il plugin invia report dettagliati dopo ogni aggiornamento automatico contenente:
- Lista plugin aggiornati con versioni (precedente → nuova)
- Errori eventuali con codici di errore
- Plugin saltati e motivazione
- Stato dei backup database/file, con verifica e link download quando disponibili
- Stato aggiornamento database Elementor (se applicabile)

## 🐛 Troubleshooting

### Plugin non rilevato dal repository
- Verifica che il file JSON del repository sia formattato correttamente
- Controlla che l'URL sia accessibile pubblicamente
- Assicurati che gli slug nel repository corrispondano ai nomi delle cartelle plugin

### Backup non creati
- Verifica i permessi della cartella `wp-content/marrison-backups`
- Controlla che `ZipArchive`/PclZip sia disponibile per i backup database e che `zlib` sia attiva per i backup file `tar.gz`
- Per i backup database verificati, controlla che le tabelle siano InnoDB e che non siano presenti view, trigger o engine non transazionali

### Aggiornamenti automatici non partono
- Verifica che i cron job WordPress siano attivi
- Controlla la configurazione email per le notifiche
- Controlla i log di errore WordPress

## 📝 Changelog

### 9.7.0
- Log aggiornamenti mensili scaricabili dal pannello Impostazioni > Log
- Lock globale anti-concorrenza per update manuali, AJAX e cron
- Snapshot/ripristino plugin attivi per ridurre disattivazioni accidentali di WooCommerce e plugin dipendenti
- Flush cache centralizzato con object cache, OPcache e cache plugin/temi/update
- Errori AJAX piu diagnostici e consultabili nei log

### 9.6.6
- Backup database verificato con manifest JSON, dimensione SQL e hash SHA256 inclusi nello ZIP
- Validazione post-compressione dello ZIP prima di dichiarare il backup riuscito
- Aggiornamenti automatici bloccati quando un backup richiesto non viene completato e verificato
- Backup file piu severo sugli errori durante la scrittura `tar.gz`
- Stato di verifica visibile nella pagina Backup e nei report email programmati

Vedi il file [CHANGELOG.md](CHANGELOG.md) per un elenco completo delle modifiche versione per versione.

## 🤝 Contributi

I contributi sono benvenuti! Per favore:
1. Fai un fork del repository
2. Crea un branch per la tua funzionalità (`git checkout -b feature/amazing-feature`)
3. Fai il commit delle tue modifiche (`git commit -m 'Add some amazing feature'`)
4. Fai il push al branch (`git push origin feature/amazing-feature`)
5. Apri una Pull Request

## 📄 Licenza

Questo plugin è rilasciato sotto licenza GPL-3.0+. Vedi il file [LICENSE](LICENSE) per maggiori dettagli.

## 🆘 Supporto

Per supporto e domande:
- **Issues GitHub**: [marrisonlab/marrison-custom-updater](https://github.com/marrisonlab/marrison-custom-updater/issues)
- **Sito web**: [Marrisonlab](https://marrisonlab.com)
- **Email**: supporto@marrisonlab.com

## 🙏 Ringraziamenti

- A tutta la community WordPress per l'ispirazione e il supporto
- Ai contributori che hanno migliorato questo progetto nel tempo
- Agli utenti che forniscono feedback preziosi per il miglioramento continuo

---

**Sviluppato con ❤️ da [Marrisonlab](https://marrisonlab.com)**
