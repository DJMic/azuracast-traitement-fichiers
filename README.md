# AzuraCast Traitement Fichiers

Plugin AzuraCast qui calcule **une seule fois** les points de cue et le gain des nouveaux fichiers, et les enregistre dans leurs **points de cue personnalisés** :

- `cue_in`
- `cue_out`
- `fade_in`
- `fade_out`
- `cross_start_next`
- `amplify` : le gain en dB, avec un chiffre après la virgule, pour une sonie de **−14 LUFS**.

Le calcul reprend celui de l'AutoCue interne de Liquidsoap (celui qu'utilise AzuraCast). La différence : l'AutoCue d'AzuraCast ne garde ses résultats que 7 jours et refait le calcul pendant la diffusion, au moment où Liquidsoap prépare le titre suivant. Avec ce plugin, chaque fichier est analysé une fois, en arrière-plan, juste après son ajout. On peut ensuite désactiver l'AutoCue d'AzuraCast.

Le plugin ajoute l'onglet **« Traitement Fichiers »** dans les **Paramètres de la station**, entre « AutoDJ » et « HLS ».

## Ce que fait le plugin, et ce qu'il ne fait pas

- Il analyse les **nouveaux fichiers** dont les six valeurs personnalisées sont **toutes vides**.
- Il ne touche **jamais** à un fichier qui a déjà une de ces valeurs, qu'elle vienne de ses tags (par exemple un fichier préparé avec `ajouter_cues.py`) ou qu'elle ait été saisie à la main dans AzuraCast.
- Il ne fait rien quand l'audio d'un fichier est remplacé : ses anciennes valeurs restent. À vous de les corriger si nécessaire.
- Il ignore les fichiers de **plus de 8 minutes** et ceux des **playlists exclues**.
- Il ne modifie pas les fichiers audio : les valeurs sont écrites seulement dans la base de données d'AzuraCast.
- Il n'analyse les fichiers déjà présents avant son activation que si vous le demandez (bouton « Analyser ces fichiers »).

## Prérequis

- AzuraCast installé avec **Docker**, dans `/var/azuracast` par défaut, en version récente (novembre 2026 ou plus).
- Stockage des médias **local ou distant** (S3, etc.).
- `git` sur le serveur, et un accès `root` ou `sudo`.

## Installation

```bash
sudo mkdir -p /var/azuracast/plugins
sudo git clone https://github.com/DJMic/azuracast-traitement-fichiers.git \
    /var/azuracast/plugins/azuracast-traitement-fichiers
cd /var/azuracast/plugins/azuracast-traitement-fichiers
sudo bash install.sh
```

Le script s'occupe de tout :

1. il ajoute le plugin à `docker-compose.override.yml`, en gardant une copie de sauvegarde de l'ancien fichier. Les entrées des **autres plugins** (Show Downloader, Animateurs virtuels…) ne sont pas modifiées. Node.js n'est pas nécessaire sur le serveur : ce travail se fait dans un conteneur Docker temporaire ;
2. il active le « mode plugin » d'AzuraCast ;
3. il vérifie que votre version d'AzuraCast fonctionne comme le plugin l'attend.

Si le plugin est installé pour la première fois, le conteneur `web` est recréé, ce qui **coupe la diffusion environ une minute**. Le script demande donc confirmation avant. Avec `--yes`, il ne pose aucune question. Si un autre plugin est déjà installé, le mode plugin est déjà actif, mais le conteneur doit quand même être recréé pour monter ce nouveau dossier.

Installation en une seule commande :

```bash
curl -fsSL https://raw.githubusercontent.com/DJMic/azuracast-traitement-fichiers/main/install.sh | sudo bash
```

## Mise en route

1. Ouvrez une station, puis **Paramètres de la station** › onglet **Traitement Fichiers**. Il faut l'autorisation « Gérer la diffusion » de la station, la même que pour l'onglet AutoDJ.
2. Vérifiez les seuils (−28 / −28 / −5 dB par défaut) et cochez les playlists à exclure, par exemple les jingles.
3. Cochez **Activer le traitement des fichiers**, puis cliquez sur **Enregistrer**. Cet onglet a son propre bouton : le bouton « Enregistrer les modifications » d'AzuraCast, qui ne sauvegarde que les champs d'AzuraCast, est masqué tant que l'onglet est affiché.
4. Envoyez un fichier. Environ une à deux minutes après la fin du traitement d'AzuraCast, ses valeurs apparaissent dans **Modifier le morceau** › onglet avancé (points de cue personnalisés).
5. Si vous le souhaitez, lancez **Analyser ces fichiers** dans « Fichiers existants » pour compléter les fichiers déjà présents qui n'ont aucune valeur. Cette analyse avance chaque minute, en arrière-plan, et peut être arrêtée.
6. Quand tout est en place, désactivez **Activer AutoCue** dans l'onglet AutoDJ (voir plus bas).

## Les réglages

| Réglage | Défaut | Rôle |
|---|---|---|
| Seuil de début (cue_in) | −28 dB | Le début est placé juste avant la première mesure au-dessus de ce seuil. |
| Seuil de fin (cue_out) | −28 dB | La fin est placée après la dernière mesure au-dessus de ce seuil. |
| Seuil de fondu enchaîné (cross) | −5 dB | Le titre suivant démarre après la dernière mesure au-dessus de ce seuil (`cross_start_next`). |
| Playlists exclues | aucune | Leurs fichiers ne sont jamais analysés. |

Comme dans Liquidsoap, les seuils sont **relatifs à la sonie de chaque morceau**. Pour un titre mesuré à −9 LUFS, un seuil de −28 dB correspond à −37 dB.

Valeurs fixes :

- **Cible : −14 LUFS.** `amplify` = −14 − sonie intégrée du titre, arrondi à 0,1 dB. Par exemple, un titre à −9,3 LUFS reçoit `−4.7`.
- **Fondu de sortie maximal :** la durée du fondu enchaîné de l'onglet AutoDJ (« Crossfade Duration »). Tant qu'AutoCue est activé, AzuraCast masque ce champ, mais garde sa valeur, et le plugin l'utilise quand même. Si le fondu enchaîné est désactivé, la limite est de 6 s, la valeur par défaut de Liquidsoap.
- **Durée maximale analysée : 8 minutes.**

## Le calcul

Le plugin reprend `autocue.internal.implementation` de Liquidsoap (`src/libs/autocue.liq`) :

1. FFmpeg mesure la sonie EBU R128 toutes les 100 ms (filtre `ebur128`, le même que celui de Liquidsoap), ainsi que la sonie intégrée du titre.
2. **cue_in** : sur la dernière mesure silencieuse avant la première mesure au-dessus du seuil de début.
3. **cue_out** : après la dernière mesure au-dessus du seuil de fin, en partant de la fin.
4. **cross_start_next** : après la dernière mesure au-dessus du seuil de fondu, avant le cue_out. `fade_out` = `cue_out − cross_start_next`. S'il dépasse le fondu maximal, le cue_out est avancé, comme le fait Liquidsoap.

Différences voulues avec Liquidsoap :

- **cue_in précis.** Liquidsoap recule de 0,1 s, ajoute un fondu d'entrée de 0,2 s et ignore les cue_in de 0,2 s ou moins. Ici, le cue_in tombe entre 0,1 et 0,2 s avant le début du son, et `fade_in` = 0. Les trois premières mesures de FFmpeg sont ignorées pour ce calcul : elles ne sont pas encore valables, car leur fenêtre de 400 ms n'est pas pleine. Un titre qui commence tout de suite garde donc un cue_in de 0.
- **Pas de détection des fins tenues** (« sustained endings »).
- **Si aucun point de fondu n'est trouvé, il n'y a pas de fondu** (`fade_out` = 0). Liquidsoap ferait un fondu sur tout le titre et le couperait.

## Fonctionnement détaillé

- **Sans gêner AzuraCast.** Une tâche du plugin tourne chaque minute, dans la synchronisation d'AzuraCast, dans son propre processus. AzuraCast ne la relance jamais tant qu'elle tourne encore. Elle ne fait rien tant que la file « media » d'AzuraCast contient du travail. Elle ne prend un fichier que **60 secondes** après qu'AzuraCast l'a traité : à ce moment-là, ses tags (et donc ses éventuelles valeurs de cue) sont déjà en base.
- **Avec un stockage distant (S3…).** AzuraCast enregistre un fichier envoyé dans sa base **avant** d'avoir fini de le transférer vers S3. Le plugin attend donc que le fichier existe sur le stockage avec la bonne taille. Il le copie ensuite dans un **fichier temporaire à son nom**, l'analyse, puis le supprime. Il n'utilise jamais la copie temporaire partagée d'AzuraCast, que Liquidsoap peut être en train d'utiliser.
- **Ressources.** Un seul fichier à la fois, avec FFmpeg en priorité basse (processeur et disque). Comptez 1 à 3 secondes de calcul par titre, et environ 10 Mo de mémoire. Au plus 50 secondes de travail par minute. Quand il n'y a rien à faire, une requête dans la base suffit.
- **Écriture.** Les valeurs ne sont écrites que si le fichier n'a pas été retraité par AzuraCast pendant l'analyse et si ses valeurs sont toujours vides. La date de traitement d'AzuraCast (`mtime`) n'est pas modifiée, donc AzuraCast ne relit pas le fichier.
- **Échecs.** Un fichier illisible ou silencieux est retenté 24 h plus tard, trois fois au plus. Il est retenté tout de suite si AzuraCast le retraite. Les erreurs sont écrites dans le journal d'AzuraCast (Administration › Journaux du système).
- **Stations qui partagent leurs médias.** Les réglages de la station de plus petit identifiant (parmi celles où le plugin est activé) s'appliquent. L'onglet des autres stations le signale.
- **Fichiers du plugin**, dans le dossier de configuration de chaque station (`/var/azuracast/stations/<station>/config/`) : `traitement-fichiers.json` (les réglages) et `traitement-fichiers-etat.json` (les échecs et l'analyse des fichiers existants). Ils ne sont touchés ni par les mises à jour d'AzuraCast ni par celles du plugin.

## Désactiver l'AutoCue d'AzuraCast

Une fois vos fichiers traités, désactivez **Activer AutoCue** (onglet AutoDJ), puis enregistrez et redémarrez la diffusion.

- Les valeurs du plugin continuent d'être appliquées. AzuraCast les envoie à Liquidsoap que l'AutoCue soit activé ou non.
- Les fichiers que le plugin n'analyse pas (plus de 8 minutes, playlists exclues) et les fichiers qui viennent d'arriver passent sans points de cue et **sans correction de volume**, sauf si vous leur mettez des valeurs à la main.
- Tant que l'AutoCue est activé, il ne calcule que pour les fichiers qui n'ont pas de valeurs.

## Commandes

```bash
cd /var/azuracast

# Analyser un fichier et afficher ses valeurs, sans rien enregistrer (ID ou chemin du fichier)
docker compose exec web azuracast_cli traitement-fichiers:analyser 3 "MP3/Artiste - Titre.mp3"
# … et les enregistrer si les valeurs du fichier sont toutes vides
docker compose exec web azuracast_cli traitement-fichiers:analyser 3 6913 --enregistrer

# Lancer tout de suite un passage du traitement en arrière-plan, avec le détail
docker compose exec web azuracast_cli traitement-fichiers:executer
```

## Mise à jour

```bash
cd /var/azuracast/plugins/azuracast-traitement-fichiers
sudo bash bin/update.sh            # télécharge et installe la dernière version
sudo bash bin/update.sh --check    # indique seulement si une mise à jour existe
```

Une mise à jour ne coupe pas la diffusion : seul PHP est rechargé. Les réglages sont conservés.

**Après une mise à jour d'AzuraCast** (`./docker.sh update`), le plugin reste en place. Vérifiez qu'il est toujours compatible :

```bash
sudo bash /var/azuracast/plugins/azuracast-traitement-fichiers/bin/setup.sh --verifier
```

## Désinstallation

```bash
cd /var/azuracast/plugins/azuracast-traitement-fichiers
sudo bash bin/uninstall.sh
```

Le script retire seulement la ligne de ce plugin de `docker-compose.override.yml`, puis recrée le conteneur `web`. Vous pouvez ensuite supprimer le dossier du plugin.

- Les points de cue déjà écrits restent dans AzuraCast.
- Les fichiers `traitement-fichiers*.json` restent dans le dossier de configuration de chaque station.

## Dépannage

- **L'onglet n'apparaît pas.** Rechargez la page. Vérifiez que vous avez l'autorisation « Gérer la diffusion », puis lancez `bin/setup.sh --verifier`. Une nouvelle version d'AzuraCast a peut-être changé la page : l'analyse continue quand même, et vous pouvez suivre son travail avec `traitement-fichiers:executer`.
- **Un fichier n'est pas analysé.** Vérifiez qu'aucune de ses six valeurs n'est renseignée, qu'il dure 8 minutes ou moins, qu'il n'est pas dans une playlist exclue, et qu'il a été ajouté après l'activation. Sinon, utilisez « Analyser ces fichiers ». La commande `traitement-fichiers:analyser` affiche ce que le plugin calculerait.
- **Tests du plugin** (pour les développeurs) : `php tests/run.php` et `npm test`. Les tests de la base de données ont besoin d'une base MariaDB de test, décrite en tête de `tests/run.php`.

## Licence

MIT
