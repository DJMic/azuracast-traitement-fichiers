# Changements

## 1.0.1

- Correction : l'onglet ne se chargeait pas (« Table 'station_playlist' doesn't exist »). La table des playlists d'AzuraCast s'appelle `station_playlists`. La lecture des playlists est maintenant couverte par les tests.

## 1.0.0

Première version.

- Analyse en arrière-plan des nouveaux fichiers dont les six points de cue personnalisés sont vides : `cue_in` précis, `cue_out`, `fade_in`, `fade_out`, `cross_start_next` et `amplify` (cible −14 LUFS, arrondi à 0,1 dB).
- Onglet « Traitement Fichiers » dans les paramètres de la station, entre « AutoDJ » et « HLS » : activation, trois seuils, playlists exclues, analyse des fichiers existants.
- Attente de la fin du traitement d'AzuraCast (file « media » vide, 60 s après le traitement) et, avec S3, de la fin du transfert du fichier.
- Fichiers de plus de 8 minutes ignorés.
- Commandes `traitement-fichiers:analyser` et `traitement-fichiers:executer`.
- Installation, mise à jour, vérification de compatibilité (`bin/setup.sh --verifier`) et désinstallation.
