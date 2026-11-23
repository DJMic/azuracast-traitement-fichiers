#!/usr/bin/env bash
# Installs or updates the AzuraCast "Traitement Fichiers" plugin (Docker installations).
#
#   cd /var/azuracast/plugins/azuracast-traitement-fichiers
#   bash bin/setup.sh             # asks before restarting AzuraCast
#   bash bin/setup.sh --yes       # no questions (for automation)
#   bash bin/setup.sh --verifier  # only checks that this AzuraCast version is compatible
#
# What it does:
#   1. adds the plugin to docker-compose.override.yml (in a temporary Node.js container: nothing
#      is installed on the host; a backup of the previous file is kept and the entries of other
#      plugins are left untouched);
#   2. restarts the "web" container if needed, otherwise just reloads PHP;
#   3. checks that this AzuraCast version still works the way the plugin expects.
# Station settings (traitement-fichiers.json in each station's config folder) are never modified.

set -euo pipefail

PLUGIN_NAME="azuracast-traitement-fichiers"
NODE_IMAGE="node:22-alpine"

plugin_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)
azuracast_dir=$(cd "$plugin_dir/../.." && pwd -P)
override="$azuracast_dir/docker-compose.override.yml"
assume_yes=false
check_only=false

for arg in "$@"; do
    case "$arg" in
        -y|--yes) assume_yes=true ;;
        --verifier|--check) check_only=true ;;
        -h|--help) sed -n '2,15p' "${BASH_SOURCE[0]}"; exit 0 ;;
        *) echo "Option inconnue : $arg (utilisez --yes, --verifier ou --help)" >&2; exit 1 ;;
    esac
done

bold=$(tput bold 2>/dev/null || true); normal=$(tput sgr0 2>/dev/null || true)
step() { printf '\n%s==> %s%s\n' "$bold" "$*" "$normal"; }
fail() { printf '\nERREUR : %s\n' "$*" >&2; exit 1; }
ask() {
    $assume_yes && return 0
    [[ -t 0 ]] || fail "Confirmation nécessaire. Relancez avec --yes pour ne pas poser de question."
    local answer
    read -r -p "$1 [o/N] " answer || answer=""
    [[ "$answer" =~ ^([oOyY]|oui|yes)$ ]]
}

# Checks, inside the running container, the parts of AzuraCast the plugin relies on.
# Prints warnings only: the plugin keeps running (an AzuraCast update may just need a plugin update).
check_compatibility() {
    local problems=0
    local src=/var/azuracast/www/backend/src
    local liq=/var/azuracast/liquidsoap/azuracast.liq
    warn() { echo "ATTENTION : $*"; problems=$((problems + 1)); }
    in_web() { docker compose exec -T web "$@" 2>/dev/null; }

    in_web test -d "/var/azuracast/www/plugins/$PLUGIN_NAME" ||
        { warn "le plugin n'est pas visible dans le conteneur « web » (relancez bin/setup.sh)."; return 0; }

    # 1. Custom cue points: the six fields of station_media.extra_metadata.
    for field in AMPLIFY FADE_IN FADE_OUT CUE_IN CUE_OUT CROSS_START_NEXT; do
        in_web grep -q "const string $field = " "$src/Entity/StationMediaMetadata.php" ||
            warn "le champ de point de cue « $field » n'existe plus dans AzuraCast (StationMediaMetadata)."
    done
    # 2. Custom values sent to Liquidsoap before AzuraCast's own AutoCue values.
    in_web grep -q "manualValues" "$src/Radio/AutoDJ/Annotations.php" ||
        warn "AzuraCast ne semble plus donner la priorité aux points de cue personnalisés (Annotations.php)."
    in_web grep -q '"azuracast_autocue"' "$liq" ||
        warn "Liquidsoap ne semble plus lire les points de cue envoyés par AzuraCast (azuracast.liq)."
    # 3. Media processing: date of processing and the "media" queue.
    in_web grep -q 'media->mtime = time()' "$src/Media/MediaProcessor.php" ||
        warn "la date de traitement des fichiers (mtime) n'est plus mise à jour comme prévu (MediaProcessor.php)."
    in_web grep -q "SlowMedia = 'media'" "$src/MessageQueue/QueueNames.php" ||
        warn "la file d'attente « media » d'AzuraCast a changé (QueueNames.php)."
    # 4. The settings page where the tab is added.
    in_web grep -rqs 'hdr_edit_profile' /var/azuracast/www/web/static/vite_dist ||
        warn "la page « Paramètres de la station » a changé : l'onglet risque de ne plus s'afficher (l'analyse continue)."
    # 5. Tools used for the analysis.
    in_web sh -c 'command -v ffmpeg >/dev/null && command -v ffprobe >/dev/null' ||
        warn "ffmpeg ou ffprobe est introuvable dans le conteneur : aucune analyse ne sera possible."

    if [[ $problems -eq 0 ]]; then
        echo "Cette version d'AzuraCast est compatible."
    else
        echo "Cherchez une mise à jour du plugin : sudo bash bin/update.sh"
    fi
}

version=$(tr -d '[:space:]' < "$plugin_dir/VERSION")
step "AzuraCast Traitement Fichiers $version"

# --- Checks -------------------------------------------------------------------
[[ "$plugin_dir" == "$azuracast_dir/plugins/$PLUGIN_NAME" ]] ||
    fail "Le plugin doit se trouver dans <dossier AzuraCast>/plugins/$PLUGIN_NAME (actuellement : $plugin_dir)."
[[ -f "$azuracast_dir/docker-compose.yml" ]] ||
    fail "docker-compose.yml introuvable dans $azuracast_dir. Ce script est prévu pour une installation Docker d'AzuraCast."
command -v docker >/dev/null || fail "Docker est nécessaire."
docker compose version >/dev/null 2>&1 || fail "Docker Compose v2 (« docker compose ») est nécessaire."

cd "$azuracast_dir"

if $check_only; then
    step "Vérification de la compatibilité avec AzuraCast…"
    check_compatibility
    exit 0
fi

[[ -w "$azuracast_dir" ]] || fail "Droits insuffisants sur $azuracast_dir : relancez avec sudo."
[[ ! -L "$override" ]] || fail "docker-compose.override.yml est un lien symbolique : modifiez-le à la main (voir README)."
[[ ! -e "$azuracast_dir/docker-compose.override.yaml" ]] ||
    fail "Un fichier docker-compose.override.yaml existe : fusionnez-le dans docker-compose.override.yml d'abord."
docker compose config --services 2>/dev/null | grep -Fxq web ||
    fail "Service « web » introuvable dans la configuration Docker Compose d'AzuraCast."

work=$(mktemp -d "$azuracast_dir/.traitement-fichiers-setup.XXXXXX")
cleanup() { rm -rf "$work" "$plugin_dir/node_modules"; }
trap cleanup EXIT

# --- 1. Docker Compose override --------------------------------------------------
step "Préparation de docker-compose.override.yml (conteneur $NODE_IMAGE temporaire)…"
docker run --rm --user "$(id -u):$(id -g)" -e npm_config_cache=/tmp/npm-cache -e npm_config_update_notifier=false \
    -v "$plugin_dir:/plugin" -v "$work:/work" -v "$azuracast_dir:/azuracast:ro" -w /plugin "$NODE_IMAGE" \
    sh -c 'npm ci --no-audit --no-fund --loglevel=error && node bin/compose-override.mjs /azuracast /work/docker-compose.override.yml'
[[ -s "$work/docker-compose.override.yml" ]] || fail "La préparation de docker-compose.override.yml a échoué."

docker compose -f docker-compose.yml -f "$work/docker-compose.override.yml" config --quiet ||
    fail "La configuration générée est invalide ; aucun fichier n'a été modifié."

if [[ ! -f "$override" ]] || ! cmp -s "$override" "$work/docker-compose.override.yml"; then
    if [[ -f "$override" ]]; then
        backup="$override.bak-$(date +%Y%m%d-%H%M%S)"
        cp -p "$override" "$backup"
        echo "Sauvegarde de l'ancien fichier : $backup"
    fi
    cp "$work/docker-compose.override.yml" "$override"
    echo "docker-compose.override.yml mis à jour."
else
    echo "Déjà à jour."
fi

# --- 2. Apply -------------------------------------------------------------------
container=$(docker compose ps -q web 2>/dev/null || true)
needs_recreate=true
if [[ -n "$container" ]] && [[ $(docker inspect -f '{{.State.Running}}' "$container") == true ]]; then
    mounts=$(docker inspect -f '{{range .Mounts}}{{println .Destination}}{{end}}' "$container")
    envs=$(docker inspect -f '{{range .Config.Env}}{{println .}}{{end}}' "$container")
    if grep -Fxq "/var/azuracast/www/plugins/$PLUGIN_NAME" <<< "$mounts" &&
        grep -Fxq "AZURACAST_PLUGIN_MODE=true" <<< "$envs" &&
        grep -Fxq "COMPOSER_PLUGIN_MODE=true" <<< "$envs"; then
        needs_recreate=false
    fi
fi

if $needs_recreate; then
    step "Activation du plugin"
    echo "Le conteneur « web » d'AzuraCast doit être recréé pour charger le plugin."
    echo "ATTENTION : la diffusion de toutes les stations sera interrompue pendant environ une minute."
    if ! ask "Redémarrer AzuraCast maintenant ?"; then
        echo
        echo "Rien n'a été redémarré. Quand vous serez prêt, lancez :"
        echo "  cd $azuracast_dir && docker compose up -d"
        exit 0
    fi
    docker compose up -d
    echo "Attente du démarrage d'AzuraCast (jusqu'à 10 minutes)…"
    ready=false
    for _ in $(seq 1 120); do
        if docker compose exec -T web supervisorctl status php-fpm 2>/dev/null | grep -q RUNNING; then
            ready=true
            break
        fi
        sleep 5
    done
    $ready || fail "AzuraCast n'a pas redémarré à temps. Consultez : docker compose logs web"
else
    step "Rechargement de PHP (sans interruption de la diffusion)…"
    docker compose exec -T web supervisorctl signal USR2 php-fpm >/dev/null
    docker compose exec -T web supervisorctl restart php-worker-fast php-worker-slow >/dev/null 2>&1 || true
fi
docker compose exec -T web rm -f /var/azuracast/www_tmp/app_routes.cache.php || true

# --- 3. Compatibility -------------------------------------------------------------
step "Vérification de la compatibilité avec AzuraCast…"
check_compatibility

step "Terminé : Traitement Fichiers $version est installé."
echo "Ouvrez une station dans AzuraCast, puis « Paramètres de la station » : l'onglet"
echo "« Traitement Fichiers » se trouve entre « AutoDJ » et « HLS »."
echo "(Rechargez la page du navigateur si elle était déjà ouverte.)"
