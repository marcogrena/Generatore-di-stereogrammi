# Versione PHP Standalone

Questa cartella contiene una versione standalone dell'app stereogrammi, indipendente da quella Python.

## Avvio locale

```bash
cd PHP_VERSION
php -S 0.0.0.0:8000
```

Apri poi: `http://localhost:8000`

## Cartelle dati locali

- `obj/` file OBJ
- `background/` immagini sfondo
- `stereogrammi/` output JPG generati

Puoi usare upload dal browser oppure selezionare file gia presenti nelle cartelle server locali.

## Requisito PHP

Serve l'estensione `gd` abilitata per la generazione immagini.
