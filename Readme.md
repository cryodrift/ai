# NAME
ai - AI Inference API Client CLI

# SYNOPSIS
`php index.php -echo /ai` [COMMAND] [OPTIONS]

# DESCRIPTION
Dieses Modul bietet eine CLI-Schnittstelle zur Interaktion mit verschiedenen AI-Providern (OpenAI, Claude, Lokale Instanzen).

# COMMANDS
- **chat**: Interaktive oder dateibasierte Chat-Anfragen.
- **batch**: Erstellen und Verwalten von Batch-Jobs (für asynchrone Verarbeitung).
- **batches**: Statusabfrage oder Auflistung von Batch-Jobs.
- **models**: Verfügbare Modelle eines Providers auflisten.
- **files**: Hochgeladene Dateien verwalten (auflisten, lesen, löschen).
- **extract**: Informationen aus Nachrichten in Dateien extrahieren.

# OPTIONS
- `-help`: Zeigt immer die jeweilige Parameterhilfe an.
- `-model=NAME`: Name des zu verwendenden KI-Modells.
- `-provider=TYPE`: API-Provider (`oairesp` für OpenAI, `locresp` für Local, `cldresp` für Claude).
- `-content=FILE`: Pfad zu einer Eingabedatei (kann mehrfach angegeben werden).
- `-outfile=FILE`: Pfad, in den die Antwort geschrieben werden soll.
- `-send`: Schickt die Anfrage tatsächlich ab.
- `-id=ID`: Spezifische ID für Batches oder Dateien.
- `-status`: Zeigt den Status an (meist in Verbindung mit `batches`).
- `-stop`: Beendet den Vorgang nach dem Senden (bei Batches).
- `-read`: Liest den Inhalt einer Datei (bei `files`).
- `-msg=FILE`: Pfad zur Nachrichtendatei (bei `extract`).


# EXAMPLES
### Chat Modus
Einfacher Chat mit mehreren Quelldateien und Ausgabe in eine HTML-Datei:
```bash
php index.php -echo /ai chat -model="gpt-4o" -provider=oairesp -content="src/app/input.txt" -content="src/app/docs.md" -outfile="src/app/result.html" -send
```

Chat über Pipe (Standard-Input):
```bash
echo "Übersetze dies ins Englische" | php index.php -echo /ai chat -model="gpt-4o" -provider=oairesp -content -outfile="result.txt" -send
```

### Batch Modus
Einen neuen Batch-Job starten:
```bash
php index.php -echo /ai batch -model="gpt-4o" -provider=oairesp -content="task.txt" -outfile="batch_result.html" -send
```

Status eines Batches abfragen:
```bash
php index.php -echo /ai batches -status -id=BATCH_ID_HERE
```

Liste aller Batches anzeigen:
```bash
php index.php -echo /ai batches
```

### Modell-Verwaltung
Verfügbare Modelle eines Providers auflisten:
```bash
php index.php -echo /ai models -provider=locresp -send
```

### Datei-Operationen
Hochgeladene Dateien auflisten:
```bash
php index.php -echo /ai files -provider="oairesp"
```

Inhalt einer remote Datei lesen und lokal speichern:
```bash
php index.php -echo /ai files -provider="oairesp" -read -id=file-ID_HERE -outfile="downloaded.html"
```

### Extraktion
Extrahiert Informationen aus einer Chat-Message in eine HTML-Datei:
```bash
php index.php -echo /ai extract -msg=".cryodrift/data/ai/message_id.chat" -outfile="src/data/input.html"
```
